<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.2.0 テスト設計書（段階1：管理者 → SES SES）の F1〜F15・S1-01〜S1-17 のうち、ほかのテストに無かった境界を足す。
// 7日ちょうど／8日目、同じ人への通知の重複、立場ちがいの操作、「見」の項目。アドレスはすべて example.com
class Step1AcceptanceTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const EMAIL = 'accept@example.com';

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function create(string $email = self::EMAIL): array
    {
        $this->as('admin@park.example')->post('/admin/ses', ['name' => '受入商事株式会社', 'rep_sei' => '受入', 'rep_mei' => '花子', 'email' => $email])->assertRedirect();
        $u = DB::table('users')->where('email', $email)->first();
        $this->guest();
        return [(int) $u->ses_id, (int) $u->id, $u->token];
    }

    private function payload(array $o = []): array
    {
        return $o + ['ses_name' => '受入商事株式会社', 'name_sei' => '受入', 'name_mei' => '花子', 'phone' => '03-1234-5678', 'postal_code' => '100-0001', 'address' => '東京都千代田区1-1',
            'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
    }

    private function row(int $id): object
    {
        return DB::table('users')->find($id);
    }

    // ---------- F11・F10：7日ちょうど／8日目 ----------
    public function test_f11_boundary_link_is_usable_until_the_expiry_second_and_unusable_after(): void
    {
        [$sid, $uid, $token] = $this->create();
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime($this->row($uid)->token_expires), 120);
        // 期限の1分前：まだ使える。一覧は「仮登録」
        DB::table('users')->where('id', $uid)->update(['token_expires' => date('Y-m-d H:i:s', time() + 60)]);
        $this->get('/invite/'.$token)->assertOk()->assertSee('受入商事株式会社');
        $this->assertSame('仮登録', user_state($this->row($uid)));
        // 期限を1分過ぎた（8日目）：使えず、誰のものかも出さない
        DB::table('users')->where('id', $uid)->update(['token_expires' => date('Y-m-d H:i:s', time() - 60)]);
        $this->get('/invite/'.$token)->assertOk()->assertSee(T::LINK_UNUSABLE)->assertDontSee('受入商事株式会社')->assertDontSee(self::EMAIL);
        $this->assertSame('仮登録（期限切れ）', user_state($this->row($uid)));
        $this->post('/invite/'.$token, $this->payload())->assertOk()->assertSee(T::LINK_UNUSABLE);
        $this->assertNull($this->row($uid)->password);
        $this->assertNull($this->row($uid)->registered_at);
        // 期限の秒ちょうどは「期限切れ」（一覧の判定とリンクの判定がそろう）
        DB::table('users')->where('id', $uid)->update(['token_expires' => date('Y-m-d H:i:s')]);
        $this->assertSame('仮登録（期限切れ）', user_state($this->row($uid)));
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
    }

    // ---------- F8：確認コード ----------
    public function test_f8_wrong_code_does_not_complete_and_right_code_does(): void
    {
        [$sid, $uid, $token] = $this->create();
        $this->post('/invite/'.$token, $this->payload())->assertRedirect(url('/two-factor/challenge'));
        $right = $this->lastTwoFactorCode();
        $wrong = $right === '000000' ? '111111' : '000000';
        $this->post('/two-factor/challenge', ['code' => $wrong])->assertRedirect(url('/two-factor/challenge'));
        $this->assertNull($this->row($uid)->registered_at);
        $this->assertNull($this->row($uid)->password);
        $this->assertSame('provisional', DB::table('ses_companies')->find($sid)->status);
        $this->post('/two-factor/challenge', ['code' => ''])->assertRedirect(url('/two-factor/challenge'));
        $this->assertNull($this->row($uid)->registered_at);
        $this->post('/two-factor/challenge', ['code' => $right])->assertRedirect(url('/ses'));
        $this->assertNotNull($this->row($uid)->registered_at);
        $this->assertNull($this->row($uid)->token);
        // 同じコードをもう一度送っても、二重に完了しない（通知は1通のまま）
        $adminId = User::where('email', 'admin@park.example')->value('id');
        $this->post('/two-factor/challenge', ['code' => $right]);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '本登録が完了しました%')->count());
    }

    // ---------- F4：「見」の項目（メール）は直せない ----------
    public function test_f4_email_is_view_only_even_if_posted(): void
    {
        [$sid, $uid, $token] = $this->create();
        $this->post('/invite/'.$token, $this->payload(['email' => 'hijack@example.com', 'rep_email' => 'hijack@example.com']))->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
        $this->assertSame(self::EMAIL, $this->row($uid)->email);
        $this->assertSame(self::EMAIL, DB::table('ses_companies')->find($sid)->email);
        $this->assertSame(0, DB::table('users')->where('email', 'hijack@example.com')->count());
    }

    // ---------- F5：欠けた保存では行が変わらない ----------
    public function test_f5_refused_save_changes_nothing(): void
    {
        [$sid, $uid, $token] = $this->create();
        $before = [(array) $this->row($uid), (array) DB::table('ses_companies')->find($sid)];
        foreach (['phone', 'agree', 'password'] as $k) $this->post('/invite/'.$token, $this->payload([$k => '']))->assertSessionHasErrors($k);
        $this->assertSame($before, [(array) $this->row($uid), (array) DB::table('ses_companies')->find($sid)]);
    }

    // ---------- F12：送り直しの権限 ----------
    public function test_f12_only_the_authorised_can_resend(): void
    {
        [$sid, $uid, $token] = $this->create();
        $this->post("/people/$uid/resend")->assertRedirect();
        $this->assertSame($token, $this->row($uid)->token);
        foreach (['ses1@park.example', 'eg1@park.example'] as $who) {
            $res = $this->as($who)->post("/people/$uid/resend");
            $this->assertContains($res->getStatusCode(), [403, 404], $who);
            $this->assertSame($token, $this->row($uid)->token, $who);
            $this->guest();
        }
        $mails = DB::table('mail_outbox')->where('to_email', self::EMAIL)->count();
        $this->as('admin@park.example')->post("/people/$uid/resend")->assertRedirect();
        $this->assertNotSame($token, $this->row($uid)->token);
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime($this->row($uid)->token_expires), 120);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->where('to_email', self::EMAIL)->count());
        // 本登録済みの人には送り直せない
        $rep = DB::table('users')->where('email', 'ses1@park.example')->first();
        $this->post("/people/{$rep->id}/resend")->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
    }

    // ---------- F13：心当たりがない ----------
    public function test_f13_decline_does_not_fix_the_person_and_does_not_notify_twice(): void
    {
        [$sid, $uid, $token] = $this->create();
        $adminId = User::where('email', 'admin@park.example')->value('id');
        $this->post('/invite/'.$token.'/decline')->assertOk();
        $u = $this->row($uid);
        $this->assertNull($u->registered_at);
        $this->assertNull($u->password);
        $this->assertSame('provisional', DB::table('ses_companies')->find($sid)->status);
        $this->assertNull(DB::table('ses_companies')->find($sid)->phone);
        $this->post('/invite/'.$token.'/decline');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '%心当たりがない%')->count());
        $this->post('/invite/'.str_repeat('a', 40).'/decline');
        $this->assertSame(1, DB::table('app_notifications')->where('title', 'like', '%心当たりがない%')->count());
        $this->post('/invite/'.$token, $this->payload())->assertSee(T::LINK_UNUSABLE);
        $this->assertNull($this->row($uid)->registered_at);
    }

    // ---------- F15：仮登録の人はどこにも入れない ----------
    public function test_f15_provisional_person_cannot_reach_account_welcome_or_other_pages(): void
    {
        [, $uid, $token] = $this->create();
        foreach (['/ses', '/account', '/welcome', '/ses/company', '/ses/engineers'] as $p) $this->get($p)->assertRedirect();
        // 仮登録の行そのものを認証済みにしても、どの画面も開かない
        $this->actingAs(User::find($uid));
        foreach (['/ses', '/account', '/welcome', '/ses/company', '/ses/engineers'] as $p) {
            $this->assertTrue($this->get($p)->isRedirect(), "$p が開いた");
        }
        $this->guest();
        $this->post('/login', ['email' => self::EMAIL, 'password' => 'password12']);
        $this->assertGuest();
    }

    // ---------- S1-06：管理者全員に1通ずつ。使えない管理者がいても残りに届く ----------
    public function test_s1_06_unusable_registrar_still_lets_the_others_know_once_each(): void
    {
        $a2 = W::createUser('adm-two@example.com', '二番 管理', 'admin', 'park1234'); $this->completeProfile($a2);
        $a3 = W::createUser('adm-three@example.com', '三番 管理', 'admin', 'park1234'); $this->completeProfile($a3);
        [$sid, $uid, $token] = $this->create();
        DB::table('users')->where('email', 'admin@park.example')->update(['active' => 0]);
        $this->post('/invite/'.$token, $this->payload())->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/ses'));
        $this->assertNotNull($this->row($uid)->registered_at);
        foreach (['adm-two@example.com', 'adm-three@example.com'] as $email) {
            $id = User::where('email', $email)->value('id');
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $id)->where('title', 'like', '本登録が完了しました%')->count(), $email);
            $this->assertSame(1, DB::table('mail_outbox')->where('to_email', $email)->where('subject', 'like', '%本登録が完了しました%')->count(), $email);
        }
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'admin@park.example')->where('subject', 'like', '%本登録が完了しました%')->count());
    }

    public function test_s1_06_single_admin_gets_exactly_one_notice(): void
    {
        [, , $token] = $this->create();
        $this->post('/invite/'.$token, $this->payload())->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()]);
        $adminId = User::where('email', 'admin@park.example')->value('id');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '本登録が完了しました%')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'admin@park.example')->where('subject', 'like', '%本登録が完了しました%')->count());
    }

    // ---------- S1-03：会社の SES はこの人を指し、会社の欄に同じ姓名が出る ----------
    public function test_s1_03_company_rep_points_to_the_person_and_shows_the_same_name(): void
    {
        [$sid, $uid, $token] = $this->create();
        $this->post('/invite/'.$token, $this->payload(['name_sei' => '直し', 'name_mei' => '次郎']))->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/ses'));
        $s = DB::table('ses_companies')->find($sid);
        $this->assertSame($uid, (int) $s->rep_user_id);
        $this->assertSame('直し 次郎', $this->row($uid)->name);
        $this->get('/ses/company')->assertOk()->assertSee('直し')->assertSee('次郎');
        $this->as('admin@park.example')->get("/admin/ses/$sid")->assertOk()->assertSee('直し');
    }

    // ---------- S1-07：同じアドレス（大文字・空白ちがい）も断る ----------
    public function test_s1_07_address_variants_are_refused(): void
    {
        $this->as('admin@park.example');
        $users = DB::table('users')->count(); $companies = DB::table('ses_companies')->count();
        foreach (['SES1@PARK.EXAMPLE', ' ses1@park.example '] as $email) {
            $this->post('/admin/ses', ['name' => '重複商事', 'rep_sei' => '重複', 'rep_mei' => '太郎', 'email' => $email])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        }
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($companies, DB::table('ses_companies')->count());
        $this->post('/admin/ses', ['name' => 'A商事', 'rep_sei' => 'あ', 'rep_mei' => 'い', 'email' => 'Dup@Example.com'])->assertRedirect();
        $this->post('/admin/ses', ['name' => 'B商事', 'rep_sei' => 'あ', 'rep_mei' => 'い', 'email' => 'dup@example.com'])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertSame(1, DB::table('users')->whereRaw('lower(email) = ?', ['dup@example.com'])->count());
    }

    // ---------- 権限のない人は登録できない ----------
    public function test_s1_07_non_admins_cannot_register_ses(): void
    {
        foreach (['ses1@park.example', 'eg1@park.example'] as $who) {
            $n = DB::table('ses_companies')->count();
            $this->as($who)->post('/admin/ses', ['name' => '勝手商事', 'rep_sei' => '勝手', 'rep_mei' => '太郎', 'email' => 'unauth@example.com'])->assertForbidden();
            $this->assertSame($n, DB::table('ses_companies')->count());
            $this->guest();
        }
        $this->post('/admin/ses', ['name' => '勝手商事', 'rep_sei' => '勝手', 'rep_mei' => '太郎', 'email' => 'unauth@example.com'])->assertRedirect();
        $this->assertSame(0, DB::table('users')->where('email', 'unauth@example.com')->count());
    }

    // ---------- S1-12：再設定のあと、新しいパスワードで入れる ----------
    public function test_s1_12_registered_person_can_log_in_with_the_new_password(): void
    {
        $this->post('/forgot', ['email' => 'ses2@park.example']);
        $token = DB::table('users')->where('email', 'ses2@park.example')->value('token');
        $this->post('/reset/'.$token, ['password' => 'brandnew123', 'password_confirmation' => 'brandnew123'])->assertRedirect();
        $this->guest();
        $this->post('/login', ['email' => 'ses2@park.example', 'password' => 'park1234']);
        $this->assertGuest();
        $this->post('/login', ['email' => 'ses2@park.example', 'password' => 'brandnew123']);
        $this->assertAuthenticated();
        $this->guest();
        $this->get('/reset/'.$token)->assertSee(T::LINK_UNUSABLE);
    }

    // ---------- S1-11：期限切れでも「忘れた」はリンクを変えない ----------
    public function test_s1_11_forgot_for_an_expired_link_keeps_the_link_and_sends_no_reset(): void
    {
        [, $uid, $token] = $this->create();
        DB::table('users')->where('id', $uid)->update(['token_expires' => date('Y-m-d H:i:s', time() - 60)]);
        $expires = $this->row($uid)->token_expires;
        $this->post('/forgot', ['email' => self::EMAIL])->assertOk()->assertSee(T::FORGOT_DONE);
        $this->assertSame($token, $this->row($uid)->token);
        $this->assertSame($expires, $this->row($uid)->token_expires);
        $this->assertStringNotContainsString('/reset/', (string) DB::table('mail_outbox')->where('to_email', self::EMAIL)->orderByDesc('id')->value('body'));
    }

    // ---------- S1-09：管理者の編集で空にできない ----------
    public function test_s1_09_admin_edit_cannot_empty_required_items(): void
    {
        [$sid, $uid] = $this->create();
        $this->as('admin@park.example');
        foreach (['name', 'rep_sei', 'rep_mei', 'email'] as $k) {
            $this->post("/admin/ses/$sid", ['name' => '受入商事株式会社', 'rep_sei' => '受入', 'rep_mei' => '花子', 'email' => self::EMAIL, $k => ''])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        }
        $this->assertSame('受入商事株式会社', DB::table('ses_companies')->find($sid)->name);
        $this->assertSame(self::EMAIL, $this->row($uid)->email);
    }

    // ---------- S1-17：B8 の文が残っていない ----------
    public function test_s1_17_no_old_invite_wording_in_the_flow(): void
    {
        [$sid, $uid, $token] = $this->create();
        $texts = [DB::table('mail_outbox')->where('to_email', self::EMAIL)->get()->map(fn ($m) => $m->subject.$m->body)->implode(''), $this->get('/invite/'.$token)->getContent(), $this->get('/invite/'.str_repeat('z', 40))->getContent()];
        $this->as('admin@park.example');
        $texts[] = $this->get('/admin/ses')->getContent();
        $texts[] = $this->get("/admin/ses/$sid")->getContent();
        foreach ($texts as $t) {
            $this->assertStringNotContainsString('ご招待：パスワードを設定', $t);
            $this->assertStringNotContainsString('ご招待:パスワードを設定', $t);
            $this->assertStringNotContainsString('パスワードを設定してください', $t);
        }
    }
}
