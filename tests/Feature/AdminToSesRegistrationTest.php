<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

// 管理者 → SES の仮登録 → 本登録の流れ（PLAN 2026-10-31 の S1-06〜S1-28・S1-35・S1-36）。アドレスはすべて example.com
class AdminToSesRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const EMAIL = 'flow@example.com';

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

    private function adminPayload(array $o = []): array
    {
        return $o + ['name' => '流れ商事株式会社', 'rep_sei' => '流れ', 'rep_mei' => '太郎', 'email' => self::EMAIL];
    }

    // 管理者が SES を仮登録して、[会社 id, SES の user id, token] を返す。そのあとはログインしていない状態
    private function createSes(array $o = []): array
    {
        $this->as('admin@park.example')->post('/admin/ses', $this->adminPayload($o))->assertRedirect();
        $u = DB::table('users')->where('email', strtolower($o['email'] ?? self::EMAIL))->first();
        $this->guest();
        return [(int) $u->ses_id, (int) $u->id, $u->token];
    }

    private function regPayload(array $o = []): array
    {
        return $o + ['ses_name' => '流れ商事株式会社', 'name_sei' => '流れ', 'name_mei' => '太郎', 'phone' => '03-1234-5678', 'postal_code' => '100-0001', 'address' => '東京都千代田区1-1',
            'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
    }

    private function row(int $id): object
    {
        return DB::table('users')->find($id);
    }

    // 本登録の入力を送って、確認コードの画面に進む
    private function submit(string $token, array $o = []): void
    {
        $this->post('/invite/'.$token, $this->regPayload($o))->assertRedirect(url('/two-factor/challenge'));
    }

    // ---------- S1-06・S1-11 ----------
    public function test_s1_06_s1_11_admin_registers_ses_and_mail_goes_out(): void
    {
        $this->as('admin@park.example');
        $this->post('/admin/ses', $this->adminPayload())->assertRedirect();
        $u = DB::table('users')->where('email', self::EMAIL)->first();
        $s = DB::table('ses_companies')->find($u->ses_id);
        $admin = User::where('email', 'admin@park.example')->first();
        $this->assertSame('provisional', $s->status);
        $this->assertSame((int) $u->id, (int) $s->rep_user_id);
        $this->assertNull($s->joined_at);
        $this->assertNull($s->phone);
        $this->assertNull($s->address);
        $this->assertSame('ses', $u->role);
        $this->assertSame((int) $admin->id, (int) $u->registered_by);
        $this->assertNull($u->registered_at);
        $this->assertNull($u->password);
        $this->assertSame('register', $u->token_kind);
        $this->assertEqualsWithDelta(time() + 7 * 86400, strtotime($u->token_expires), 120);

        $mails = DB::table('mail_outbox')->where('to_email', self::EMAIL)->get();
        $this->assertCount(1, $mails);
        $m = $mails[0];
        $this->assertStringContainsString('仮登録のご案内', $m->subject);
        foreach (['流れ商事株式会社', '流れ', '太郎', 'SES', url('/invite/'.$u->token), '7日以内', '心当たりがない'] as $part) $this->assertStringContainsString($part, $m->body);
        $this->assertStringContainsString('見本運営', $m->body); // 登録した人の企業名（見本の管理者）
        $this->assertStringContainsString('会社の電話番号', $m->body);
        $this->assertStringNotContainsString('ご招待', $m->body.$m->subject);
        $this->assertStringNotContainsString('パスワードを設定', $m->body.$m->subject);
    }

    // ---------- S1-12 ----------
    public function test_s1_12_missing_or_bad_fields_and_used_addresses_are_refused(): void
    {
        $this->as('admin@park.example');
        $count = DB::table('users')->count(); $companies = DB::table('ses_companies')->count();
        foreach (['name', 'rep_sei', 'rep_mei', 'email'] as $k) {
            $this->post('/admin/ses', $this->adminPayload([$k => '']))->assertSessionHas('flash');
        }
        $this->post('/admin/ses', $this->adminPayload(['email' => 'not-an-address']))->assertSessionHas('flash');
        $this->assertSame($count, DB::table('users')->count());
        $this->assertSame($companies, DB::table('ses_companies')->count());

        // 使われているアドレスは、理由を出す
        DB::table('users')->where('email', 'ses2@park.example')->update(['active' => 0]);
        DB::table('users')->where('email', 'ses3@park.example')->update(['left_at' => now_str(), 'active' => 0]);
        [$sid, $uid] = $this->createSes(['email' => 'prov@example.com']);
        $this->as('admin@park.example');
        foreach (['ses1@park.example' => 'すでに使われて', 'ses2@park.example' => '停止中', 'ses3@park.example' => '退会済', 'prov@example.com' => '仮登録中'] as $email => $word) {
            $this->post('/admin/ses', $this->adminPayload(['email' => $email]))->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && str_contains($f['msg'], $word));
        }
        $this->assertSame($count + 1, DB::table('users')->count());
    }

    // ---------- S1-08 ----------
    public function test_s1_08_list_shows_states_and_resend(): void
    {
        [$sid, $uid, $token] = $this->createSes();
        $this->as('admin@park.example')->get('/admin/ses')->assertOk()->assertSee('流れ商事株式会社')->assertSee('仮登録')->assertSee('送り直し')->assertDontSee('仮登録（期限切れ）');
        DB::table('users')->where('id', $uid)->update(['token_expires' => date('Y-m-d H:i:s', time() - 60)]);
        $this->get('/admin/ses')->assertSee('仮登録（期限切れ）')->assertSee('送り直し');
        $this->get("/admin/ses/$sid")->assertOk()->assertSee('送り直し')->assertSee('取り消し')->assertDontSee("/admin/ses/$sid/suspend", false);
        // 本登録のあとは「参加中」で、送り直しはない
        DB::table('users')->where('id', $uid)->update(['registered_at' => now_str(), 'password' => Hash::make('x'), 'token' => null]);
        DB::table('ses_companies')->where('id', $sid)->update(['status' => 'active']);
        $html = $this->get('/admin/ses')->assertOk()->getContent();
        $this->assertStringContainsString('参加中', $html);
        $this->assertStringNotContainsString('/people/'.$uid.'/resend', $html);
    }

    // ---------- S1-13・S1-14 ----------
    public function test_s1_13_form_shows_company_and_names_and_email_as_text(): void
    {
        [, , $token] = $this->createSes();
        $this->get('/invite/'.$token)->assertOk()->assertSee('value="流れ商事株式会社"', false)->assertSee('value="流れ"', false)->assertSee('value="太郎"', false)
            ->assertSee(self::EMAIL)->assertDontSee('name="email"', false)->assertSee('心当たりがない')->assertSee(T::consent('ses', true)['text']);
    }

    public function test_s1_14_each_required_item_blocks_registration(): void
    {
        [, $uid, $token] = $this->createSes();
        $codes = DB::table('mail_outbox')->where('kind', '2fa')->count();
        foreach (['ses_name', 'name_sei', 'name_mei', 'phone', 'postal_code', 'address', 'agree', 'password'] as $k) {
            $this->post('/invite/'.$token, $this->regPayload([$k => '']))->assertSessionHasErrors($k);
        }
        $this->post('/invite/'.$token, $this->regPayload(['postal_code' => '12345']))->assertSessionHasErrors('postal_code');
        $this->post('/invite/'.$token, $this->regPayload(['password_confirmation' => 'other']))->assertSessionHasErrors('password');
        // 担当者の姓名は聞かない
        $this->get('/invite/'.$token)->assertDontSee('contact_sei', false);
        $this->assertNull($this->row($uid)->registered_at);
        $this->assertSame($codes, DB::table('mail_outbox')->where('kind', '2fa')->count());
    }

    // ---------- S1-15・S1-16 ----------
    public function test_s1_15_nothing_is_saved_until_the_code_is_entered(): void
    {
        [$sid, $uid, $token] = $this->createSes();
        $notes = DB::table('app_notifications')->count();
        $this->submit($token);
        $this->assertNull($this->row($uid)->password);
        $this->assertNull($this->row($uid)->registered_at);
        $this->assertSame('provisional', DB::table('ses_companies')->find($sid)->status);
        $this->assertNull(DB::table('ses_companies')->find($sid)->phone);
        $this->assertSame($notes, DB::table('app_notifications')->count());
        // やめて別の人が開いても、リンクはまだ使える
        $this->guest();
        $this->get('/invite/'.$token)->assertOk();
    }

    public function test_s1_16_code_completes_registration_and_tells_all_admins_once(): void
    {
        $second = W::createUser('admin2@example.com', '第二 管理', 'admin', 'park1234');
        $this->completeProfile($second);
        [$sid, $uid, $token] = $this->createSes();
        $this->submit($token);
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/ses'));

        $u = $this->row($uid); $s = DB::table('ses_companies')->find($sid);
        $this->assertNotNull($u->registered_at);
        $this->assertTrue(Hash::check('password12', $u->password));
        $this->assertNull($u->token);
        $this->assertNotNull($u->secrecy_agreed_at);
        $this->assertSame('active', $s->status);
        $this->assertSame(today_str(), $s->joined_at);
        $this->assertNotNull($s->agreed_at);
        $this->assertSame('03-1234-5678', $s->phone);
        $this->get('/ses')->assertOk();
        foreach (['admin@park.example', 'admin2@example.com'] as $email) {
            $adminId = User::where('email', $email)->value('id');
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '本登録が完了しました%')->count(), $email);
            $this->assertSame(1, DB::table('mail_outbox')->where('to_email', $email)->where('subject', 'like', '%本登録が完了しました%')->count(), $email);
        }
        // 完了のあと、リンクは使えない
        $this->guest();
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
    }

    public function test_s1_17_code_can_be_skipped_for_test_addresses(): void
    {
        config(['app.skip_2fa_domain' => 'skip.example']);
        [, $uid, $token] = $this->createSes(['email' => 'who@skip.example']);
        $this->post('/invite/'.$token, $this->regPayload())->assertRedirect(url('/ses'));
        $this->assertNotNull($this->row($uid)->registered_at);
    }

    // ---------- S1-18 ----------
    public function test_s1_18_corrections_are_told_with_before_and_after(): void
    {
        DB::table('ses_companies')->insert(['name' => '別の正式名称株式会社', 'kind' => 'ses', 'status' => 'active', 'created_at' => now_str(), 'updated_at' => now_str()]);
        [$sid, $uid, $token] = $this->createSes();
        $this->submit($token, ['ses_name' => '別の正式名称株式会社', 'name_sei' => '修正']);
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
        $this->assertSame('別の正式名称株式会社', DB::table('ses_companies')->find($sid)->name);
        $this->assertSame('修正 太郎', $this->row($uid)->name);
        $note = DB::table('app_notifications')->where('title', 'like', '本登録が完了しました%')->first();
        foreach (['流れ商事株式会社 → 別の正式名称株式会社', '流れ 太郎 → 修正 太郎', '同じ名前の SES がほかにもあります'] as $part) $this->assertStringContainsString($part, $note->body);
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'SES の名前が変わりました')->count()); // 名前変更のベルと重ねない
    }

    // ---------- S1-19 ----------
    public function test_s1_19_provisional_person_cannot_log_in_or_open_pages(): void
    {
        [, , $token] = $this->createSes();
        $this->post('/login', ['email' => self::EMAIL, 'password' => 'password12'])->assertSessionHas('flash');
        $this->assertGuest();
        $this->submit($token);
        $this->get('/ses')->assertRedirect(url('/two-factor/challenge'));
        $this->get('/account')->assertRedirect(url('/two-factor/challenge'));
    }

    // ---------- S1-20・S1-21 ----------
    public function test_s1_20_expired_link_is_unusable_and_resend_issues_a_new_one(): void
    {
        [$sid, $uid, $old] = $this->createSes();
        DB::table('users')->where('id', $uid)->update(['token_expires' => date('Y-m-d H:i:s', time() - 60)]);
        $this->get('/invite/'.$old)->assertOk()->assertSee(T::LINK_UNUSABLE)->assertDontSee('流れ商事株式会社');
        $mails = DB::table('mail_outbox')->where('to_email', self::EMAIL)->count();
        $this->as('admin@park.example')->post("/people/$uid/resend")->assertRedirect();
        $new = $this->row($uid)->token;
        $this->assertNotSame($old, $new);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->where('to_email', self::EMAIL)->count());
        $this->guest();
        $this->get('/invite/'.$old)->assertSee(T::LINK_UNUSABLE);
        $this->get('/invite/'.$new)->assertOk()->assertSee('流れ商事株式会社');
    }

    public function test_s1_21_resend_during_registration_discards_the_old_input(): void
    {
        [, $uid, $old] = $this->createSes();
        $this->submit($old);
        Registration::resend($this->row($uid), User::where('email', 'admin@park.example')->first());
        $new = $this->row($uid)->token;
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/login'));
        $this->assertGuest();
        $this->assertNull($this->row($uid)->registered_at);
        $this->assertNull($this->row($uid)->password);
        $this->get('/invite/'.$new)->assertOk();
    }

    // ---------- S1-22 ----------
    public function test_s1_22_decline_invalidates_the_link_and_tells_the_registrar(): void
    {
        [, $uid, $token] = $this->createSes();
        $this->post('/invite/'.$token.'/decline')->assertOk()->assertSee(T::DECLINED_DONE);
        $u = $this->row($uid);
        $this->assertNotNull($u->reg_declined_at);
        $this->assertNull($u->token);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', User::where('email', 'admin@park.example')->value('id'))->where('title', 'like', '%心当たりがない%')->count());
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
        $this->assertSame('仮登録（心当たりなし）', user_state($u));
        $this->as('admin@park.example')->post("/people/$uid/resend")->assertRedirect();
        $this->assertNull($this->row($uid)->reg_declined_at);
        $this->assertSame('仮登録', user_state($this->row($uid)));
    }

    // ---------- S1-23・S1-24 ----------
    public function test_s1_23_provisional_edit_keeps_the_row_and_resends_on_email_change(): void
    {
        [$sid, $uid, $old] = $this->createSes();
        $users = DB::table('users')->count();
        $this->as('admin@park.example')->post("/admin/ses/$sid", ['name' => '改め商事株式会社', 'rep_sei' => '改', 'rep_mei' => '次郎', 'email' => 'changed@example.com'])->assertRedirect("/admin/ses/$sid");
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame('changed@example.com', $this->row($uid)->email);
        $this->assertSame('changed@example.com', DB::table('ses_companies')->find($sid)->email);
        $this->assertSame('改 次郎', $this->row($uid)->name);
        $this->assertSame('改め商事株式会社', DB::table('ses_companies')->find($sid)->name);
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'changed@example.com')->count());
        $this->assertNotSame($old, $this->row($uid)->token);
        $this->guest();
        $this->get('/invite/'.$old)->assertSee(T::LINK_UNUSABLE);
        // 使われているアドレスには変えられない
        $this->as('admin@park.example')->post("/admin/ses/$sid", ['name' => '改め商事株式会社', 'rep_sei' => '改', 'rep_mei' => '次郎', 'email' => 'ses1@park.example'])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertSame('changed@example.com', $this->row($uid)->email);
    }

    public function test_s1_24_provisional_edit_takes_only_four_items(): void
    {
        [$sid] = $this->createSes();
        $this->as('admin@park.example')->post("/admin/ses/$sid", ['name' => '流れ商事株式会社', 'rep_sei' => '流れ', 'rep_mei' => '太郎', 'email' => self::EMAIL, 'phone' => '03-9999-9999', 'address' => '混ぜた住所', 'employees' => 5])->assertRedirect();
        $s = DB::table('ses_companies')->find($sid);
        $this->assertNull($s->phone);
        $this->assertNull($s->address);
        $this->assertNull($s->employees);
        $this->get("/admin/ses/$sid/edit")->assertOk()->assertDontSee('name="phone"', false);
    }

    // ---------- S1-25 ----------
    public function test_s1_25_suspended_company_blocks_links(): void
    {
        [$sid, $uid, $token] = $this->createSes();
        DB::table('ses_companies')->where('id', $sid)->update(['status' => 'suspended']);
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
        $this->as('admin@park.example')->post("/people/$uid/resend")->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        // 本登録済みの人の「忘れた」も、会社が止まっていればリンクを出さない
        $s1 = $this->user('ses1@park.example')->ses_id;
        DB::table('ses_companies')->where('id', $s1)->update(['status' => 'suspended']);
        $this->guest();
        $this->post('/forgot', ['email' => 'ses1@park.example'])->assertOk()->assertSee(T::FORGOT_DONE);
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'ses1@park.example')->where('kind', 'reset')->count());
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    // ---------- S1-26 ----------
    private function applyAndGetId(string $email): int
    {
        $this->post('/apply', ['name' => '申込商事', 'email' => $email, 'contact_sei' => '申込', 'contact_mei' => '花子', 'agree' => '1'])->assertOk();
        $this->guest();
        return (int) DB::table('ses_companies')->where('name', '申込商事')->where('email', $email)->value('id');
    }

    public function test_s1_26_approval_goes_through_the_same_form_and_asks_for_consent_again(): void
    {
        $id = $this->applyAndGetId('applicant@example.com');
        $this->as('admin@park.example')->get("/admin/ses/$id/approve")->assertOk()->assertSee('申込商事')->assertSee('applicant@example.com')->assertSee('value="申込"', false);
        $before = DB::table('mail_outbox')->count();
        $this->post("/admin/ses/$id/approve", ['name' => '申込商事株式会社', 'rep_sei' => '代表', 'rep_mei' => '花子', 'email' => 'rep@example.com'])->assertRedirect("/admin/ses/$id");
        $s = DB::table('ses_companies')->find($id);
        $this->assertSame('provisional', $s->status);
        $this->assertNull($s->agreed_at);
        $this->assertSame($before + 2, DB::table('mail_outbox')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'rep@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'applicant@example.com')->where('subject', 'like', '%承認%')->count());
        // 同意なしの本登録は断る
        $token = User::where('email', 'rep@example.com')->value('token');
        $this->guest();
        $this->post('/invite/'.$token, $this->regPayload(['agree' => '']))->assertSessionHasErrors('agree');
    }

    public function test_s1_26_same_address_gets_one_mail_with_the_approval_sentence(): void
    {
        $id = $this->applyAndGetId('same@example.com');
        $before = DB::table('mail_outbox')->count();
        $this->as('admin@park.example')->post("/admin/ses/$id/approve", ['name' => '申込商事', 'rep_sei' => '代表', 'rep_mei' => '花子', 'email' => 'same@example.com'])->assertRedirect();
        $this->assertSame($before + 1, DB::table('mail_outbox')->count());
        $this->assertStringContainsString('承認', DB::table('mail_outbox')->where('to_email', 'same@example.com')->orderByDesc('id')->value('body'));
    }

    // ---------- S1-27・S1-28：再設定 ----------
    public function test_s1_27_forgot_for_unregistered_person_sends_help_only(): void
    {
        [, $uid, $token] = $this->createSes();
        $expires = $this->row($uid)->token_expires;
        $mails = DB::table('mail_outbox')->count();
        $this->post('/forgot', ['email' => self::EMAIL])->assertOk()->assertSee(T::FORGOT_DONE);
        $this->assertSame($token, $this->row($uid)->token);
        $this->assertSame($expires, $this->row($uid)->token_expires);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->count());
        $m = DB::table('mail_outbox')->orderByDesc('id')->first();
        $this->assertSame(self::EMAIL, $m->to_email);
        $this->assertStringNotContainsString('/reset/', $m->body);
        $this->assertStringNotContainsString('/invite/', $m->body);
        // 1時間に1通まで
        $this->post('/forgot', ['email' => self::EMAIL])->assertSee(T::FORGOT_DONE);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->count());
        // 登録のないアドレスも同じ画面で、メールは出ない
        $this->post('/forgot', ['email' => 'nobody@example.com'])->assertOk()->assertSee(T::FORGOT_DONE);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->count());
    }

    public function test_s1_28_forgot_for_registered_person_sends_a_reset_link_with_password_only(): void
    {
        $this->post('/forgot', ['email' => 'ses2@park.example'])->assertSee(T::FORGOT_DONE);
        $u = $this->user('ses2@park.example');
        $this->assertSame('reset', $u->token_kind);
        $this->assertEqualsWithDelta(time() + 86400, strtotime($u->token_expires), 120);
        $this->assertStringContainsString(url('/reset/'.$u->token), DB::table('mail_outbox')->where('to_email', 'ses2@park.example')->where('kind', 'reset')->value('body'));
        $this->get('/invite/'.$u->token)->assertRedirect('/reset/'.$u->token);
        $page = $this->get('/reset/'.$u->token)->assertOk();
        $page->assertSee('name="password"', false)->assertDontSee('agree')->assertDontSee('name_sei', false);
        $registered = $u->registered_at;
        $this->post('/reset/'.$u->token, ['password' => 'newpass123', 'password_confirmation' => 'newpass123'])->assertRedirect();
        $after = $this->user('ses2@park.example');
        $this->assertTrue(Hash::check('newpass123', $after->password));
        $this->assertSame($registered, $after->registered_at);
        $this->assertNull($after->token);
    }

    // ---------- S1-33（簡易）・S1-35 ----------
    public function test_s1_35_manuals_explain_provisional_and_full_registration(): void
    {
        // 管理者のマニュアルは管理者だけが読める（ログインしていないと 403）
        $this->get('/manual/admin')->assertForbidden();
        foreach (['admin', 'admin-steps', 'ses', 'ses-steps'] as $kind) {
            $this->as('admin@park.example')->get("/manual/$kind")->assertOk()->assertSee('仮登録')->assertSee('本登録');
        }
        // SES のマニュアルは、ログインしていなくても読める
        $this->guest();
        $this->get('/manual/ses')->assertOk()->assertSee('仮登録')->assertSee('本登録');
        $this->get('/manual/ses-steps')->assertOk()->assertSee('仮登録')->assertSee('本登録');
    }

    // ---------- S1-09（続き）：仮登録のエンジニアは推薦・応募もできない ----------
    public function test_s1_09_provisional_engineer_cannot_be_recommended_or_apply(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        $ses1 = (int) $this->user('ses1@park.example')->ses_id;
        $ses2 = (int) $this->user('ses2@park.example')->ses_id;
        $recommended = W::createEngineer($ses2, ['name' => 'テスト 推薦', 'kana' => 'てすと すいせん', 'email' => null], $admin, false);
        $applying = W::createEngineer($ses1, ['name' => 'テスト 応募', 'kana' => 'てすと おうぼ', 'email' => null], $admin, false);
        $caseId = (int) DB::table('cases')->where('status', 'open')->orderBy('id')->value('id'); // 見本の案件1（SES1 が持ち込んだ案件）
        $this->as('ses2@park.example')->post("/cases/$caseId/recommend", ['engineer_id' => $recommended])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertSame(0, DB::table('audit_log')->where('action', 'recommend')->count());
        $this->as('ses1@park.example')->post("/cases/$caseId/apply", ['engineer_id' => $applying, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertFalse(DB::table('applications')->where('engineer_id', $applying)->exists());
        // 本登録が済めば通る
        DB::table('engineers')->where('id', $applying)->update(['registered_at' => now_str(), 'self_confirmed_at' => now_str()]);
        $this->post("/cases/$caseId/apply", ['engineer_id' => $applying, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $this->assertTrue(DB::table('applications')->where('engineer_id', $applying)->exists());
    }

    // ---------- S1-37：承認は申込中の会社だけ ----------
    public function test_s1_37_approval_is_only_for_pending_companies(): void
    {
        $make = fn (string $status) => DB::table('ses_companies')->insertGetId(['name' => "承認確認 $status", 'kind' => 'ses', 'status' => $status, 'email' => "approve-$status@example.com", 'created_at' => now_str(), 'updated_at' => now_str()]);
        $ids = ['provisional' => $make('provisional'), 'suspended' => $make('suspended'), 'left' => $make('left'), 'active' => (int) $this->user('ses1@park.example')->ses_id];
        $this->as('admin@park.example');
        $mails = DB::table('mail_outbox')->count(); $users = DB::table('users')->count();
        foreach ($ids as $status => $id) {
            $this->get("/admin/ses/$id/approve")->assertRedirect("/admin/ses/$id")->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
            $this->post("/admin/ses/$id/approve", $this->adminPayload(['email' => "approved-$status@example.com"]))->assertRedirect("/admin/ses/$id")->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
            $this->assertSame($status, DB::table('ses_companies')->find($id)->status, $status);
        }
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame($users, DB::table('users')->count());
        // 申込中の会社だけが、承認の入力画面に進める
        $pending = (int) DB::table('ses_companies')->where('status', 'pending')->value('id');
        $this->get("/admin/ses/$pending/approve")->assertOk();
    }

    // ---------- S1-38：確認コードの途中で会社が止まったら、本登録は完了しない ----------
    public function test_s1_38_company_stopped_during_the_code_step_blocks_completion(): void
    {
        $sid = (int) $this->user('ses1@park.example')->ses_id;
        // 参加中の会社の担当者（仮登録）。担当者の本登録は参加中の会社だけが通す
        $uid = DB::table('users')->insertGetId(['email' => 'late-staff@example.com', 'name' => '仮 担当', 'name_sei' => '仮', 'name_mei' => '担当', 'role' => 'ses', 'ses_id' => $sid, 'active' => 1,
            'registered_by' => User::where('email', 'admin@park.example')->value('id'), 'created_at' => now_str(), 'updated_at' => now_str()]);
        $token = Registration::issueToken($uid);
        $this->guest();
        $this->post('/invite/'.$token, ['name_sei' => '仮', 'name_mei' => '担当', 'phone' => '03-0000-0001', 'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'])
            ->assertRedirect(url('/two-factor/challenge'));
        // 確認コードを入れる前に、会社が止まる（管理者の停止と同じ状態を、画面を替えずにそのまま作る）
        DB::table('ses_companies')->where('id', $sid)->update(['status' => 'suspended']);
        $notes = DB::table('app_notifications')->count();
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/login'));
        $this->assertGuest();
        $this->assertNull($this->row($uid)->registered_at);
        $this->assertNull($this->row($uid)->password);
        $this->assertSame($notes, DB::table('app_notifications')->count());
    }

    public function test_s1_38_cancelled_row_is_not_a_server_error_even_when_the_code_is_skipped(): void
    {
        config(['app.skip_2fa_domain' => 'skip.example']);
        [, $uid, $token] = $this->createSes(['email' => 'gone@skip.example']);
        $this->as('admin@park.example')->post("/people/$uid/cancel")->assertRedirect();
        $this->guest();
        $this->post('/invite/'.$token, $this->regPayload())->assertOk()->assertSee(T::LINK_UNUSABLE);
        $this->assertNull(DB::table('users')->find($uid));
        // 停止中の会社の担当者も同じ（500 にならず、共通の文）
        $sid = (int) $this->user('ses1@park.example')->ses_id;
        $staff = DB::table('users')->insertGetId(['email' => 'stopped@skip.example', 'name' => '仮 担当', 'role' => 'ses', 'ses_id' => $sid, 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $t = Registration::issueToken($staff);
        DB::table('ses_companies')->where('id', $sid)->update(['status' => 'suspended']);
        $this->post('/invite/'.$t, ['name_sei' => '仮', 'name_mei' => '担当', 'phone' => '03-0000-0001', 'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'])->assertOk()->assertSee(T::LINK_UNUSABLE);
        $this->assertNull($this->row($staff)->registered_at);
    }

    // ---------- S1-39：仮登録の会社の退会・再開・ホームの数 ----------
    public function test_s1_39_provisional_company_can_leave_but_not_resume_and_is_counted_on_home(): void
    {
        $before = $this->as('admin@park.example')->get('/admin')->assertOk()->viewData('counts')['sesInvited'];
        [$sid, $uid, $token] = $this->createSes();
        $home = $this->as('admin@park.example')->get('/admin')->assertOk()->assertSee('流れ商事株式会社');
        $this->assertSame($before + 1, $home->viewData('counts')['sesInvited']);
        // 再開は押せない
        $this->post("/admin/ses/$sid/resume")->assertRedirect();
        $this->assertSame('provisional', DB::table('ses_companies')->find($sid)->status);
        // 退会は通り、リンクも使えなくなる
        $this->post("/admin/ses/$sid/delete")->assertRedirect();
        $this->assertSame('left', DB::table('ses_companies')->find($sid)->status);
        $this->guest();
        $this->get('/invite/'.$token)->assertSee(T::LINK_UNUSABLE);
        // 会社が1つも無い画面でも開く（0件の画面）は EmptyStateTest で確かめている
    }

    // ---------- レビュー指摘の回帰（L1〜L5・L7・L8） ----------
    // L1：本登録が完了しなかったとき、リンクが新しくなった場合と、使えなくなった場合（会社が止まった）で、画面の文を分ける
    public function test_review_l1_message_differs_between_renewed_and_unusable(): void
    {
        [, $uid, $old] = $this->createSes();
        $this->submit($old);
        Registration::resend($this->row($uid), User::where('email', 'admin@park.example')->first());
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/login'))
            ->assertSessionHas('flash', fn ($f) => $f['msg'] === T::LINK_RENEWED);

        $sid = (int) $this->user('ses1@park.example')->ses_id;
        $staff = DB::table('users')->insertGetId(['email' => 'l1-staff@example.com', 'name' => '仮 担当', 'role' => 'ses', 'ses_id' => $sid, 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $token = Registration::issueToken($staff);
        $this->guest();
        $this->post('/invite/'.$token, ['name_sei' => '仮', 'name_mei' => '担当', 'phone' => '03-0000-0001', 'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'])->assertRedirect(url('/two-factor/challenge'));
        DB::table('ses_companies')->where('id', $sid)->update(['status' => 'suspended']);
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/login'))
            ->assertSessionHas('flash', fn ($f) => $f['msg'] === T::LINK_UNUSABLE);
    }

    // L2：確認コードを省く道でも、同じ名前の SES がある注意を出す
    public function test_review_l2_duplicate_name_warning_on_the_skip_path(): void
    {
        config(['app.skip_2fa_domain' => 'skip.example']);
        [, , $token] = $this->createSes(['email' => 'dup@skip.example']);
        $this->post('/invite/'.$token, $this->regPayload(['ses_name' => 'あおぞらテック']))->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'warn' && str_contains($f['msg'], '同じ名前の SES'));
    }

    // L3：同じ入力で二重に完了を呼んでも、完了として扱い、知らせを重ねない。別の内容なら完了にしない
    public function test_review_l3_double_finish_is_done_without_second_notice(): void
    {
        [, $uid] = $this->createSes();
        $req = \Illuminate\Http\Request::create('/x');
        $req->setLaravelSession(app('session.store'));
        $req->setUserResolver(fn () => User::find($uid));
        $data = ['name_sei' => '流れ', 'name_mei' => '太郎'];
        DB::table('users')->where('id', $uid)->update(['registered_at' => now_str(), 'token' => null, 'password' => 'HASH-SAME']);
        $notes = DB::table('app_notifications')->count();
        Registration::stash($req, $this->row($uid), 'old-token', $data, 'HASH-SAME');
        $this->assertSame('done', Registration::finishPending($req));
        $this->assertSame($notes, DB::table('app_notifications')->count());
        Registration::stash($req, $this->row($uid), 'old-token', $data, 'HASH-OTHER');
        $this->assertSame('unusable', Registration::finishPending($req));
    }

    // L4：配列などを送られても 500 にならない
    public function test_review_l4_arrays_do_not_cause_server_errors(): void
    {
        $this->post('/forgot', ['email' => ['x@example.com']])->assertOk()->assertSee(T::FORGOT_DONE);
        [, , $token] = $this->createSes();
        $this->post('/invite/'.$token, $this->regPayload(['password' => ['a'], 'password_confirmation' => ['a']]))->assertSessionHasErrors('password');
        $this->post('/invite/'.$token, $this->regPayload(['name_sei' => ['x']]))->assertSessionHasErrors('name_sei');
        $this->post('/forgot', ['email' => 'ses2@park.example'])->assertOk();
        $t = $this->user('ses2@park.example')->token;
        $this->post('/reset/'.$t, ['password' => ['abc'], 'password_confirmation' => ['abc']])->assertSessionHasErrors('password');
        $this->as('admin@park.example');
        $n = DB::table('ses_companies')->count();
        $this->post('/admin/ses', $this->adminPayload(['name' => ['x']]))->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->post('/admin/ses', $this->adminPayload(['email' => ['a@example.com'], 'name' => '配列確認商事']))->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertSame($n, DB::table('ses_companies')->count());
    }

    // L5：同意は元の値で確かめる（「no」などは同意にならない）
    public function test_review_l5_consent_value_is_checked_as_sent(): void
    {
        [, $uid, $token] = $this->createSes();
        foreach (['no', '0', 'off', 'false'] as $v) {
            $this->post('/invite/'.$token, $this->regPayload(['agree' => $v]))->assertSessionHasErrors('agree');
        }
        $this->assertNull($this->row($uid)->registered_at);
        $this->post('/invite/'.$token, $this->regPayload(['agree' => 'on']))->assertRedirect(url('/two-factor/challenge'));
    }

    // L7：仮登録メールの記録に氏名・アドレスを書かない
    public function test_review_l7_invite_audit_has_no_name_or_address(): void
    {
        [, $uid] = $this->createSes();
        $d = (string) DB::table('audit_log')->where('action', 'invite')->orderByDesc('id')->value('detail');
        $this->assertStringContainsString("#$uid", $d);
        $this->assertStringNotContainsString(self::EMAIL, $d);
        $this->assertStringNotContainsString('流れ', $d);
    }

    // L8：取り消しの判定のあとに本登録が済んだ行は、読み直して断る
    public function test_review_l8_cancel_rechecks_inside_the_transaction(): void
    {
        [, $uid] = $this->createSes();
        $stale = $this->row($uid); // 判定のときの古い行（パスワードなし）
        DB::table('users')->where('id', $uid)->update(['registered_at' => now_str(), 'password' => Hash::make('x')]);
        $res = Registration::cancel($stale, User::where('email', 'admin@park.example')->first());
        $this->assertSame('bad', $res['type']);
        $this->assertNotNull(DB::table('users')->find($uid));
    }

    // ---------- S1-36：取り消し ----------
    public function test_s1_36_cancel_removes_provisional_rows_and_frees_the_address(): void
    {
        [$sid, $uid] = $this->createSes();
        $this->as('admin@park.example')->get("/admin/ses/$sid")->assertSee('取り消し');
        $this->post("/people/$uid/cancel")->assertRedirect('/admin/ses');
        $this->assertNull(DB::table('users')->find($uid));
        $this->assertNull(DB::table('ses_companies')->find($sid));
        $log = DB::table('audit_log')->where('action', 'reg-cancel')->orderByDesc('id')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('流れ', (string) $log->detail);
        $this->assertStringNotContainsString(self::EMAIL, (string) $log->detail);
        // 同じアドレスで、また仮登録できる
        $this->post('/admin/ses', $this->adminPayload())->assertRedirect();
        $this->assertSame(1, DB::table('users')->where('email', self::EMAIL)->count());
    }

    public function test_s1_36_cannot_cancel_started_rows_and_others_cannot_cancel(): void
    {
        $rep = $this->user('ses1@park.example');
        $this->as('admin@park.example')->post("/people/{$rep->id}/cancel")->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertNotNull(DB::table('users')->find($rep->id));
        // パスワードのない行でも、本登録の日時がある人・参加中の会社の SES は断る
        DB::table('users')->where('id', $rep->id)->update(['password' => null, 'registered_at' => null]);
        $this->post("/people/{$rep->id}/cancel")->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertNotNull(DB::table('users')->find($rep->id));
        // 権限のない人：同じ会社の担当者は、ほかの担当者の取り消しができない（403）。他社は 404
        $staff = W::createUser('staff-c@example.com', '担当 三郎', 'ses', 'park1234', ['ses_id' => $rep->ses_id]);
        $this->completeProfile($staff);
        $prov = DB::table('users')->insertGetId(['email' => 'prov-staff@example.com', 'name' => '仮 担当', 'role' => 'ses', 'ses_id' => $rep->ses_id, 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->as('staff-c@example.com')->post("/people/$prov/cancel")->assertForbidden();
        $this->as('ses2@park.example')->post("/people/$prov/cancel")->assertNotFound();
        $this->assertNotNull(DB::table('users')->find($prov));
        $this->as('eg1@park.example')->post("/people/$prov/cancel")->assertForbidden();
    }
}
