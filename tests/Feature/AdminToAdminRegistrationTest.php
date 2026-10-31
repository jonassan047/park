<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\TestCase;

// v0.2.0 段階 3：管理者 → 管理者の仮登録 → 本登録と、はじめの設定・コマンド・役割の追加・必須を空にしない・取り消し
// （PLAN 2026-10-31 の S3-01〜S3-09・S3-11）。アドレスはすべて example.com か見本のもの
class AdminToAdminRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const EMAIL = 'new-admin-s3@example.com';

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    // ログインしていない状態にする（メールのリンクを開く人）
    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', strtolower($email))->value('id');
    }

    private function row(int $id): ?object
    {
        return DB::table('users')->find($id);
    }

    private function adminPayload(array $o = []): array
    {
        return $o + ['company_name' => '登録企業', 'name_sei' => '登録', 'name_mei' => '次郎', 'email' => self::EMAIL];
    }

    // 管理者（見本の管理者）が管理者を仮登録して、[user id, token] を返す。そのあとはログインしていない状態
    private function register(array $o = [], string $by = 'admin@park.example'): array
    {
        $this->as($by)->post('/admin/admins', $this->adminPayload($o))->assertSessionHas('flash.type', 'ok');
        $u = DB::table('users')->where('email', strtolower($o['email'] ?? self::EMAIL))->first();
        $this->guest();
        return [(int) $u->id, $u->token];
    }

    private function regPayload(array $o = []): array
    {
        return $o + ['company_name' => '登録企業', 'name_sei' => '登録', 'name_mei' => '次郎', 'phone' => '03-1234-5678', 'password' => 'password12', 'password_confirmation' => 'password12'];
    }

    // 本登録を済ませる（確認コードまで）。済んだら、ログインしたままになる（ホームへ）
    private function finish(string $token, array $o = []): void
    {
        $this->post('/invite/'.$token, $this->regPayload($o))->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
    }

    // 本登録が済んだ、別の管理者を足す
    private function otherAdmin(string $email, string $name = '別 管理者', string $company = '別企業'): int
    {
        $id = W::createUser($email, $name, 'admin', 'park1234');
        $this->completeProfile($id, ['company_name' => $company]);
        return $id;
    }

    // @js で出る確認の文の一部（日本語は \u の形になるので、同じ形にして探す）
    private function enc(string $s): string
    {
        return trim(Js::from($s)->toHtml(), '"\'');
    }

    // ---------- S3-01：管理者が管理者を仮登録する ----------
    public function test_s3_01_admin_registers_admin_with_four_items_only(): void
    {
        $me = $this->uid('admin@park.example');
        $this->as('admin@park.example')->post('/admin/admins', $this->adminPayload(['position' => '営業部', 'phone' => '03-9999-9999', 'postal_code' => '1000001', 'address' => '混ぜた住所', 'kana_sei' => 'とうろく', 'kana_mei' => 'じろう']))
            ->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'ok');
        $u = DB::table('users')->where('email', self::EMAIL)->first();
        $this->assertSame(['admin', '登録企業', '登録 次郎'], [$u->role, $u->company_name, $u->name]);
        $this->assertSame([$me, 'register'], [(int) $u->registered_by, $u->token_kind]);
        $this->assertNull($u->registered_at);
        $this->assertNull($u->password);
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime($u->token_expires), 120);
        // 電話などを混ぜても入らない（本人が本登録で入れる）
        foreach (['position', 'phone', 'postal_code', 'address', 'kana_sei', 'kana_mei'] as $col) $this->assertNull($u->$col, $col);

        $mails = DB::table('mail_outbox')->where('to_email', self::EMAIL)->get();
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('仮登録のご案内', $mails[0]->subject);
        foreach (['管理者', url('/invite/'.$u->token), '7日以内', '登録企業', '電話番号'] as $part) $this->assertStringContainsString($part, $mails[0]->body);
        $this->assertStringNotContainsString('混ぜた住所', $mails[0]->body);
        $this->assertStringNotContainsString('ご招待', $mails[0]->subject.$mails[0]->body);
        $this->assertStringContainsString('仮登録', user_state($u));
    }

    public function test_s3_01_each_missing_item_is_refused_and_non_admins_get_403(): void
    {
        $users = DB::table('users')->count(); $mails = DB::table('mail_outbox')->count();
        $this->as('admin@park.example');
        foreach (['company_name', 'name_sei', 'name_mei', 'email'] as $k) {
            $this->post('/admin/admins', $this->adminPayload([$k => '']))->assertSessionHas('flash.type', 'bad');
        }
        $this->post('/admin/admins', $this->adminPayload(['email' => 'not-an-address']))->assertSessionHas('flash.type', 'bad');
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        foreach (['ses1@park.example', 'eg1@park.example'] as $who) {
            $this->as($who)->post('/admin/admins', $this->adminPayload())->assertForbidden();
            $this->get('/admin/admins/new')->assertForbidden();
        }
        $this->assertSame($users, DB::table('users')->count());
    }

    // ---------- S3-02：本登録。企業名・姓・名・電話・パスワードが必須。同意はなし。企業名を直せる ----------
    public function test_s3_02_form_shows_values_and_each_required_item_blocks(): void
    {
        [$id, $token] = $this->register();
        $this->get('/invite/'.$token)->assertOk()->assertSee('value="登録企業"', false)->assertSee('value="登録"', false)->assertSee('value="次郎"', false)
            ->assertSee(self::EMAIL)->assertDontSee('name="email"', false)->assertDontSee('name="agree"', false)->assertDontSee('name="consent"', false)->assertSee('心当たりがない');
        $codes = DB::table('mail_outbox')->where('kind', '2fa')->count();
        foreach (['company_name', 'name_sei', 'name_mei', 'phone', 'password'] as $k) {
            $this->post('/invite/'.$token, $this->regPayload([$k => '']))->assertSessionHasErrors($k);
        }
        $this->post('/invite/'.$token, $this->regPayload(['password_confirmation' => 'other']))->assertSessionHasErrors('password');
        $this->assertNull($this->row($id)->registered_at);
        $this->assertSame($codes, DB::table('mail_outbox')->where('kind', '2fa')->count());
    }

    public function test_s3_02_registration_completes_and_company_can_be_corrected(): void
    {
        [$id, $token] = $this->register();
        $this->finish($token, ['company_name' => '直した企業', 'name_sei' => '修正', 'position' => '部長']);
        $u = $this->row($id);
        $this->assertNotNull($u->registered_at);
        $this->assertNotNull($u->password);
        $this->assertNull($u->token);
        $this->assertSame(['直した企業', '修正 次郎', '03-1234-5678', '部長'], [$u->company_name, $u->name, $u->phone, $u->position]);
        $this->assertSame('参加中', user_state($u));
        $this->get('/admin')->assertOk(); // 入れる
        // 登録した管理者への知らせに、直した内容の前後が出る
        $note = DB::table('app_notifications')->where('user_id', $this->uid('admin@park.example'))->where('title', 'like', '本登録が完了しました%')->first();
        $this->assertNotNull($note);
        foreach (['企業名：登録企業 → 直した企業', '氏名：登録 次郎 → 修正 次郎'] as $part) $this->assertStringContainsString($part, $note->body);
        // 完了のあと、リンクは使えない
        $this->guest();
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
    }

    // ---------- S3-03：管理者全員に知らせる。登録した人に重ねない。登録した人が停止中でも、ほかの管理者には届く ----------
    public function test_s3_03_all_admins_are_told_once(): void
    {
        $b = $this->otherAdmin('admin-b-s3@example.com', '別 乙', '乙企業');
        $c = $this->otherAdmin('admin-c-s3@example.com', '別 丙', '丙企業');
        $a = $this->uid('admin@park.example');
        [$id, $token] = $this->register(['company_name' => '三番企業']);
        $this->finish($token, ['company_name' => '三番企業']);
        foreach ([$a, $b, $c] as $adminId) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '本登録が完了しました%三番企業%')->count(), "ベル #$adminId");
        }
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'admin@park.example')->where('subject', 'like', '%本登録が完了しました%三番企業%')->count());
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $id)->where('title', 'like', '本登録が完了しました%')->count()); // 本人には出さない

        // 登録した人が止まっていても、ほかの管理者には1通ずつ届く（止まっている人には届かない）
        $this->guest();
        [$id2, $token2] = $this->register(['company_name' => '四番企業', 'email' => 'fourth-s3@example.com']);
        DB::table('users')->where('id', $a)->update(['active' => 0]);
        $this->finish($token2, ['company_name' => '四番企業']);
        $this->assertNotNull($this->row($id2)->registered_at);
        foreach ([$b, $c] as $adminId) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '本登録が完了しました%四番企業%')->count(), "ベル #$adminId");
        }
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $a)->where('title', 'like', '本登録が完了しました%四番企業%')->count());
    }

    // ---------- S3-04：仮登録中は直せる（同じ行・メールを変えると新しいリンク）。本登録が済んだ人のメールは変えられない ----------
    public function test_s3_04_provisional_edit_keeps_the_row_and_resends_on_email_change(): void
    {
        [$id, $old] = $this->register();
        $users = DB::table('users')->count();
        $this->as('admin@park.example')->post("/admin/admins/$id", ['company_name' => '改め企業', 'name_sei' => '改', 'name_mei' => '三郎', 'email' => 'moved-s3@example.com',
            'phone' => '03-9999-9999', 'address' => '混ぜた住所'])->assertRedirect(url("/admin/admins/$id"))->assertSessionHas('flash.type', 'ok');
        $u = $this->row($id);
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame(['moved-s3@example.com', '改め企業', '改 三郎'], [$u->email, $u->company_name, $u->name]);
        $this->assertNull($u->phone); // 仮登録中は4つだけ
        $this->assertNull($u->address);
        $this->assertNotSame($old, $u->token);
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'moved-s3@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
        $this->assertNull(Registration::userByRegisterToken($old)); // 前のリンクは使えない
        $this->assertNotNull(Registration::userByRegisterToken($u->token));
        // 使われているアドレスには変えられない
        $this->post("/admin/admins/$id", ['company_name' => '改め企業', 'name_sei' => '改', 'name_mei' => '三郎', 'email' => 'ses1@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame('moved-s3@example.com', $this->row($id)->email);
    }

    public function test_s3_04_registered_admin_email_is_fixed_and_resend_is_for_admins_only(): void
    {
        $other = $this->otherAdmin('admin-b-s3@example.com');
        $me = $this->uid('admin@park.example');
        $this->as('admin@park.example');
        $base = ['company_name' => '別企業', 'name_sei' => '別', 'name_mei' => '管理者'];
        $this->post("/admin/admins/$other", $base + ['email' => 'changed-s3@example.com'])->assertSessionHas('flash.msg', '使用中の方のメールアドレスは変えられません。');
        $this->post("/admin/admins/$me", ['company_name' => '見本運営株式会社', 'name_sei' => '管理者', 'name_mei' => '太郎', 'email' => 'self-s3@example.com'])->assertSessionHas('flash.msg', 'ご自分のメールアドレスは変えられません。');
        $this->assertSame('admin-b-s3@example.com', $this->row($other)->email);
        // 送り直しは管理者だけ（古い reinvite の入口は無い）
        [$id] = $this->register();
        $this->as('ses1@park.example')->post("/people/$id/resend")->assertNotFound(); // 他社の行は見つからない扱い
        $this->as('eg1@park.example')->post("/people/$id/resend")->assertForbidden();
        $this->as('admin@park.example')->post("/admin/admins/$id/reinvite")->assertNotFound();
        $this->post("/people/$id/resend")->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'ok');
    }

    // ---------- S3-05：期限切れと「心当たりがない」（S1-20・S1-22 と同じ手順を管理者で） ----------
    public function test_s3_05_expired_link_is_unusable_and_resend_issues_a_new_one(): void
    {
        [$id, $old] = $this->register();
        DB::table('users')->where('id', $id)->update(['token_expires' => date('Y-m-d H:i:s', time() - 60)]);
        $this->assertSame('仮登録（期限切れ）', user_state($this->row($id)));
        $this->get('/invite/'.$old)->assertOk()->assertSee(T::LINK_UNUSABLE)->assertDontSee('登録企業');
        $this->as('admin@park.example')->get('/admin/admins')->assertSee('仮登録（期限切れ）')->assertSee('送り直し');
        $this->post("/people/$id/resend")->assertRedirect(url('/admin/admins'));
        $new = $this->row($id)->token;
        $this->assertNotSame($old, $new);
        $this->guest();
        $this->get('/invite/'.$old)->assertSee(T::LINK_UNUSABLE);
        $this->get('/invite/'.$new)->assertOk()->assertSee('登録企業');
    }

    public function test_s3_05_decline_invalidates_the_link_and_tells_admins(): void
    {
        $b = $this->otherAdmin('admin-b-s3@example.com');
        [$id, $token] = $this->register();
        $this->post('/invite/'.$token.'/decline')->assertOk()->assertSee(T::DECLINED_DONE);
        $u = $this->row($id);
        $this->assertNotNull($u->reg_declined_at);
        $this->assertNull($u->token);
        $this->assertSame('仮登録（心当たりなし）', user_state($u));
        foreach ([$this->uid('admin@park.example'), $b] as $adminId) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '%心当たりがない%')->count(), "ベル #$adminId");
        }
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
        $this->as('admin@park.example')->post("/people/$id/resend")->assertRedirect();
        $this->assertNull($this->row($id)->reg_declined_at);
        $this->assertSame('仮登録', user_state($this->row($id)));
    }

    // ---------- S3-06：はじめの設定。企業名・姓・名・電話・メール・パスワードを1画面で。確認コードなし ----------
    public function test_s3_06_setup_requires_every_item_and_completes_without_code(): void
    {
        DB::table('users')->delete();
        $ok = ['company_name' => '最初企業', 'name_sei' => '最初', 'name_mei' => '管理', 'phone' => '03-0000-0001', 'email' => 'first-s3@example.com', 'password' => 'abcd1234'];
        $this->get('/setup')->assertOk()->assertSee('name="company_name"', false)->assertSee('name="phone"', false)->assertDontSee('name="agree"', false);
        foreach (array_keys($ok) as $k) {
            $this->post('/setup', [$k => ''] + $ok)->assertSessionHasErrors($k);
            $this->assertSame(0, DB::table('users')->count(), $k);
        }
        $codes = DB::table('mail_outbox')->where('kind', '2fa')->count();
        $this->post('/setup', $ok)->assertRedirect(url('/admin'));
        $u = DB::table('users')->where('email', 'first-s3@example.com')->first();
        $this->assertSame(['admin', '最初企業', '最初 管理', '03-0000-0001'], [$u->role, $u->company_name, $u->name, $u->phone]);
        $this->assertNotNull($u->registered_at);
        $this->assertSame($codes, DB::table('mail_outbox')->where('kind', '2fa')->count()); // 確認コードは聞かない（誰もいない最初の1人の例外）
        $this->get('/admin')->assertOk(); // 足りない項目の画面は出ない
        $this->get('/register/complete')->assertRedirect(url('/admin'));
        // 管理者がいると、開けない
        $this->guest();
        $this->get('/setup')->assertRedirect(url('/login'));
        $this->post('/setup', ['email' => 'second-s3@example.com'] + $ok)->assertRedirect(url('/login'));
        $this->assertSame(1, DB::table('users')->count());
    }

    // ---------- S3-07：park:invite-admin で作った人も、同じ本登録を通る ----------
    public function test_s3_07_invite_admin_command_goes_through_the_same_registration(): void
    {
        $mails = DB::table('mail_outbox')->count();
        $this->assertSame(0, Artisan::call('park:invite-admin', ['email' => 'Cmd-S3@Example.com', 'sei' => '指令', 'mei' => '太郎', '--company' => '指令企業']));
        $out = Artisan::output();
        $u = DB::table('users')->where('email', 'cmd-s3@example.com')->first();
        $this->assertSame(['admin', '指令 太郎', '指令企業', 'register'], [$u->role, $u->name, $u->company_name, $u->token_kind]);
        $this->assertNull($u->registered_at);
        $this->assertNull($u->password);
        $this->assertSame($mails, DB::table('mail_outbox')->count()); // メールは送らない（リンクを出すだけ）
        $this->assertStringContainsString(url('/invite/'.$u->token), $out);
        // 出力のリンクを開いて、S3-02 の本登録を通す
        $this->get('/invite/'.$u->token)->assertOk()->assertSee('value="指令企業"', false);
        $this->finish($u->token, ['company_name' => '指令企業', 'name_sei' => '指令', 'name_mei' => '太郎']);
        $this->assertNotNull(DB::table('users')->find($u->id)->registered_at);
        $this->get('/admin')->assertOk();

        // 企業名は省いてもよい（本登録の画面で入れる）。もう一度流すと新しいリンクになる
        $this->guest();
        $this->assertSame(0, Artisan::call('park:invite-admin', ['email' => 'cmd2-s3@example.com', 'sei' => '無名', 'mei' => '企業']));
        $v = DB::table('users')->where('email', 'cmd2-s3@example.com')->first();
        $this->assertNull($v->company_name);
        $this->assertSame(0, Artisan::call('park:invite-admin', ['email' => 'cmd2-s3@example.com', 'sei' => '無名', 'mei' => '企業']));
        $this->assertNotSame($v->token, DB::table('users')->find($v->id)->token);
        $this->assertSame(1, DB::table('users')->where('email', 'cmd2-s3@example.com')->count());
    }

    public function test_s3_07_command_refuses_other_roles_and_used_addresses(): void
    {
        // 別の立場の仮登録の行（エンジニア・SES の担当者）があるアドレスは断る（終了コード 1）
        DB::table('users')->insert(['email' => 'prov-eg-s3@example.com', 'name' => '仮 エンジニア', 'role' => 'eg', 'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('users')->insert(['email' => 'prov-ses-s3@example.com', 'name' => '仮 担当', 'role' => 'ses', 'ses_id' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $count = DB::table('users')->count();
        foreach (['prov-eg-s3@example.com', 'prov-ses-s3@example.com', 'admin@park.example', 'ses1@park.example', 'abc'] as $email) {
            $this->assertSame(1, Artisan::call('park:invite-admin', ['email' => $email, 'sei' => '断る', 'mei' => '太郎']), $email);
        }
        $this->assertSame($count, DB::table('users')->count());
        $this->assertSame('eg', DB::table('users')->where('email', 'prov-eg-s3@example.com')->value('role'));
        $this->assertNull(DB::table('users')->where('email', 'prov-eg-s3@example.com')->value('token'));
        // 姓か名が無いときも断る
        $this->assertSame(1, Artisan::call('park:invite-admin', ['email' => 'noname-s3@example.com', 'sei' => '姓だけ', 'mei' => ' ']));
        $this->assertSame($count, DB::table('users')->count());
    }

    // ---------- S3-08：管理者の役割を足せるのは、本登録が済んだエンジニアだけ ----------
    public function test_s3_08_admin_role_only_for_registered_engineers(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        // 仮登録中のエンジニアのアドレスは断る（理由を出す）
        W::createEngineer(1, ['name_sei' => '仮', 'name_mei' => '登録', 'email' => 'pend-eg-s3@example.com'], $admin);
        $count = DB::table('users')->count();
        $this->as('admin@park.example')->post('/admin/admins', $this->adminPayload(['email' => 'pend-eg-s3@example.com']))
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && str_contains($f['msg'], '仮登録中'));
        $this->assertSame('eg', DB::table('users')->where('email', 'pend-eg-s3@example.com')->value('role'));
        $this->assertSame($count, DB::table('users')->count());

        // 本登録が済んだエンジニア：兼任になる。電話などの入っていた欄は、空の値で消えない
        $eg = $this->uid('eg1@park.example');
        DB::table('users')->where('id', $eg)->update(['phone' => '03-1111-1111', 'position' => '開発', 'postal_code' => '100-0001', 'address' => '東京都（元）', 'company_name' => null]);
        $mails = DB::table('mail_outbox')->where('kind', 'invite')->count();
        $this->post('/admin/admins', $this->adminPayload(['email' => 'eg1@park.example', 'company_name' => '兼任企業']))->assertSessionHas('flash.type', 'ok');
        $u = $this->row($eg);
        $this->assertSame(['admin', null, '兼任企業'], [$u->role, $u->ses_id, $u->company_name]);
        $this->assertSame(['03-1111-1111', '開発', '100-0001', '東京都（元）'], [$u->phone, $u->position, $u->postal_code, $u->address]);
        $this->assertNotNull($u->engineer_id);
        $this->assertSame($mails, DB::table('mail_outbox')->where('kind', 'invite')->count()); // 仮登録のご案内は出ない
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $eg)->where('for_role', 'admin')->count());

        // addAdminRole は、空の値で既にある値を消さない
        $eg2 = $this->uid('eg2@park.example');
        DB::table('users')->where('id', $eg2)->update(['phone' => '03-2222-2222', 'position' => '設計', 'postal_code' => '100-0002', 'address' => '東京都（二）', 'company_name' => '前の企業']);
        W::addAdminRole($eg2, ['company_name' => '', 'position' => '', 'phone' => '', 'postal_code' => '', 'address' => ''], $admin);
        $v = $this->row($eg2);
        $this->assertSame(['admin', '前の企業', '03-2222-2222', '設計', '100-0002', '東京都（二）'], [$v->role, $v->company_name, $v->phone, $v->position, $v->postal_code, $v->address]);
    }

    public function test_s3_08_next_login_asks_for_missing_admin_items(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        $eid = W::createEngineer(1, ['name_sei' => '兼任', 'name_mei' => '次郎', 'email' => 'dual-s3@example.com'], $admin, false);
        $uid = W::createUser('dual-s3@example.com', '兼任 次郎', 'eg', 'park1234', ['ses_id' => 1, 'engineer_id' => $eid]);
        $this->completeProfile($uid);
        $this->as('admin@park.example')->post('/admin/admins', $this->adminPayload(['email' => 'dual-s3@example.com', 'company_name' => '兼任企業']))->assertSessionHas('flash.type', 'ok');
        DB::table('users')->where('id', $uid)->update(['phone' => null]); // 管理者の立場の電話が足りない
        $this->as('dual-s3@example.com')->withSession(['acting_role' => 'admin', TwoFactor::SESSION_KEY => $uid])->get('/admin')->assertRedirect(url('/register/complete'));
        $this->get('/register/complete')->assertOk()->assertSee('電話番号');
        $this->post('/register/complete', ['phone' => '03-7777-0000'])->assertRedirect();
        $this->assertSame('03-7777-0000', $this->row($uid)->phone);
        $this->get('/admin')->assertOk();
    }

    // ---------- S3-09：管理者の必須（企業名・姓・名・電話）を空にできない ----------
    public function test_s3_09_required_items_cannot_be_emptied_in_update_admin_and_account(): void
    {
        $other = $this->otherAdmin('admin-b-s3@example.com');
        $this->as('admin@park.example');
        $before = (array) $this->row($other);
        $base = ['company_name' => '別企業', 'name_sei' => '別', 'name_mei' => '管理者', 'phone' => '03-0000-0009', 'email' => 'admin-b-s3@example.com'];
        foreach (['company_name', 'name_sei', 'name_mei', 'phone'] as $k) {
            $this->post("/admin/admins/$other", [$k => ''] + $base)->assertSessionHas('flash.type', 'bad');
            $this->assertEquals($before, (array) $this->row($other), "管理者の編集：$k");
        }
        // 自分の「アカウント」の画面
        $me = $this->uid('admin@park.example');
        $mine = (array) $this->row($me);
        foreach (['company_name', 'phone'] as $k) {
            $this->post('/account', [$k => ''])->assertSessionHas('flash.type', 'bad');
            $this->assertSame($mine[$k], $this->row($me)->$k, "アカウント：$k");
        }
        $this->post('/account', ['name_sei' => '管理者', 'name_mei' => ''])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($mine['name_mei'], $this->row($me)->name_mei);
    }

    public function test_s3_09_missing_items_screen_requires_each_missing_item(): void
    {
        $id = $this->otherAdmin('admin-b-s3@example.com');
        DB::table('users')->where('id', $id)->update(['phone' => null, 'name_mei' => null, 'company_name' => null]);
        $this->as('admin-b-s3@example.com')->get('/admin')->assertRedirect(url('/register/complete'));
        $full = ['company_name' => '別企業', 'name_sei' => '別', 'name_mei' => '管理者', 'phone' => '03-0000-0009'];
        foreach (['company_name', 'name_mei', 'phone'] as $k) {
            $this->post('/register/complete', [$k => ''] + $full)->assertSessionHasErrors($k);
        }
        $this->assertNull($this->row($id)->phone);
        $this->assertNull($this->row($id)->company_name);
        $this->post('/register/complete', $full)->assertRedirect(url('/admin'));
        $this->get('/admin')->assertOk();
    }

    // ---------- S3-11：管理者の取り消しと画面 ----------
    public function test_s3_11_cancel_removes_a_provisional_admin_and_keeps_the_audit_clean(): void
    {
        $b = $this->otherAdmin('admin-b-s3@example.com');
        [$id] = $this->register();
        $this->as('admin-b-s3@example.com')->post("/people/$id/cancel")->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'ok'); // 別の管理者が取り消す
        $this->assertNull($this->row($id));
        $log = (string) DB::table('audit_log')->where('action', 'reg-cancel')->value('detail');
        $this->assertNotSame('', $log);
        foreach ([self::EMAIL, '登録 次郎', '登録企業'] as $secret) $this->assertStringNotContainsString($secret, $log);
        // 同じアドレスで、また仮登録できる
        $this->register();
        $this->assertNotNull(DB::table('users')->where('email', self::EMAIL)->first());
        // 本登録が済んだ人は取り消せない
        $this->as('admin@park.example')->post("/people/$b/cancel")->assertSessionHas('flash.type', 'bad');
        $this->assertNotNull($this->row($b));
        // 権限のない人は 403（エンジニア）・見つからない（他社の SES）
        $x = (int) DB::table('users')->where('email', self::EMAIL)->value('id');
        $this->as('eg1@park.example')->post("/people/$x/cancel")->assertForbidden();
        $this->as('ses1@park.example')->post("/people/$x/cancel")->assertNotFound();
        $this->assertNotNull($this->row($x));
    }

    public function test_s3_11_cancel_button_with_confirm_only_on_provisional_rows_for_admins(): void
    {
        $b = $this->otherAdmin('admin-b-s3@example.com');
        [$id] = $this->register();
        $confirm = $this->enc('この仮登録を取り消します。');
        $list = $this->as('admin@park.example')->get('/admin/admins')->assertOk()->getContent();
        $this->assertStringContainsString("/people/$id/cancel", $list);
        $this->assertStringContainsString($confirm, $list); // 確認ダイアログ
        $this->assertStringNotContainsString("/people/$b/cancel", $list);
        $this->assertStringNotContainsString('/people/'.$this->uid('admin@park.example').'/cancel', $list);
        $show = $this->get("/admin/admins/$id")->assertOk()->getContent();
        $this->assertStringContainsString("/people/$id/cancel", $show);
        $this->assertStringContainsString($confirm, $show);
        $this->assertStringContainsString("/people/$id/resend", $show);
        $this->assertStringNotContainsString("/people/$b/cancel", $this->get("/admin/admins/$b")->assertOk()->getContent());
        // 取り消したあとの行は、一覧に出ない
        $this->post("/people/$id/cancel")->assertSessionHas('flash.type', 'ok');
        $this->get('/admin/admins')->assertDontSee(self::EMAIL);
    }
}
