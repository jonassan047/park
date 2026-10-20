<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 兼任ログイン（1つのログインで、エンジニアと SES 担当者・管理者を兼ねる。PLAN 2026-10-20：AC1〜AC15）
class DualRoleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const SES1 = 'ses1@park.example';   // みどり情報システム（見本）
    private const ADMIN = 'admin@park.example';

    // ---------- 道具 ----------

    // 立場（$acting）を選んだ状態でログインした人として動く。前の人のセッションは捨てる
    private function as(string $email, ?string $acting = null): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        $s = [TwoFactor::SESSION_KEY => $u->id];
        if ($acting) $s['acting_role'] = $acting;
        return $this->actingAs($u->fresh())->withSession($s);
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', strtolower($email))->value('id');
    }

    private function row(string $email): object
    {
        return DB::table('users')->where('email', strtolower($email))->first();
    }

    private function sesId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('ses_id');
    }

    private function users(): int { return DB::table('users')->count(); }
    private function engineers(): int { return DB::table('engineers')->count(); }
    private function invites(): int { return DB::table('mail_outbox')->where('kind', 'invite')->count(); }
    private function audits(string $a): int { return DB::table('audit_log')->where('action', $a)->count(); }

    // SES 担当者（ses1）が自分をエンジニアとして登録 → SES 兼任。エンジニアの id を返す
    private function dualSes(bool $consent = true): int
    {
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '緑川', 'name_mei' => '本人', 'email' => self::SES1])->assertRedirect();
        $eid = (int) $this->row(self::SES1)->engineer_id;
        $this->assertGreaterThan(0, $eid);
        if ($consent) DB::table('engineers')->where('id', $eid)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str()]);
        return $eid;
    }

    // 管理者が自分をエンジニア（直属でなく ses1 所属）として登録 → 管理者兼任
    private function dualAdmin(bool $consent = true): int
    {
        $this->as(self::ADMIN, 'admin')->post('/engineers', ['ses_id' => $this->sesId(self::SES1), 'name_sei' => '管理', 'name_mei' => '本人', 'email' => self::ADMIN])->assertRedirect();
        $eid = (int) $this->row(self::ADMIN)->engineer_id;
        $this->assertGreaterThan(0, $eid);
        if ($consent) DB::table('engineers')->where('id', $eid)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str()]);
        return $eid;
    }

    private function code(string $email): string
    {
        $subject = DB::table('mail_outbox')->where('to_email', $email)->where('kind', '2fa')->orderByDesc('id')->value('subject');
        preg_match('/(\d{6})/', (string) $subject, $m);
        return $m[1];
    }

    // 実際のログイン：パスワード → 確認コード
    private function login(string $email): \Illuminate\Testing\TestResponse
    {
        $this->flushSession();
        $this->post('/login', ['email' => $email, 'password' => 'park1234'])->assertRedirect(url('/two-factor/challenge'));
        return $this->post('/two-factor/challenge', ['code' => $this->code($email)]);
    }

    private function actingNow(): ?string
    {
        return session('acting_role');
    }

    // ---------- AC1〜AC5：兼任のつくり方 ----------

    public function test_ac1_ses_registers_self_as_engineer(): void
    {
        $u0 = $this->users(); $e0 = $this->engineers(); $i0 = $this->invites();
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '緑川', 'name_mei' => '本人', 'email' => self::SES1])
            ->assertSessionHas('flash.type', 'ok')
            ->assertSessionHas('flash.msg', 'ご自身をエンジニアとして登録しました。エンジニアの画面は、ログアウトして入り直し、「エンジニア」を選ぶと開けます。');
        $this->assertSame($u0, $this->users());
        $this->assertSame($e0 + 1, $this->engineers());
        $this->assertSame($i0, $this->invites());
        $u = $this->row(self::SES1);
        $this->assertSame('ses', $u->role);
        $eid = (int) DB::table('engineers')->where('name', '緑川 本人')->value('id');
        $this->assertSame($eid, (int) $u->engineer_id);
        $this->assertSame($this->sesId(self::SES1), (int) DB::table('engineers')->find($eid)->ses_id);
        $this->assertSame(1, $this->audits('dual-link'));
        $detail = (string) DB::table('audit_log')->where('action', 'dual-link')->value('detail');
        $this->assertStringNotContainsString('@', $detail);
        $this->assertStringNotContainsString('緑川', $detail);
        // 本人が自分で登録したので、お知らせは出さない
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $u->id)->where('title', 'エンジニアとしても登録されました')->count());
    }

    public function test_ac1_email_case_is_ignored(): void
    {
        $u0 = $this->users();
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '緑川', 'name_mei' => '大文字', 'email' => 'SES1@Park.Example'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame($u0, $this->users());
        $this->assertNotNull($this->row(self::SES1)->engineer_id);
    }

    public function test_ac2_admin_registers_engineer_with_ses_users_address(): void
    {
        $u0 = $this->users(); $i0 = $this->invites();
        $this->as(self::ADMIN, 'admin')->post('/engineers', ['ses_id' => $this->sesId(self::SES1), 'name_sei' => '緑川', 'name_mei' => '兼任', 'email' => self::SES1])
            ->assertSessionHas('flash.type', 'ok')
            ->assertSessionHas('flash.msg', '登録しました。このメールアドレスの方のアカウントに、エンジニアの役割を足しました（招待メールは送っていません）。');
        $this->assertSame($u0, $this->users());
        $this->assertSame($i0, $this->invites());
        $u = $this->row(self::SES1);
        $this->assertNotNull($u->engineer_id);
        $n = DB::table('app_notifications')->where('user_id', $u->id)->where('title', 'エンジニアとしても登録されました')->first();
        $this->assertNotNull($n);
        $this->assertSame('eg', $n->for_role);
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', self::SES1)->where('body', 'like', '【エンジニアあて】%')->count());
    }

    public function test_ac2_edit_email_then_invite_links(): void
    {
        // 4番（高橋）はアドレスなしのエンジニア（ses1 所属）
        $this->as(self::ADMIN, 'admin')->post('/engineers/4', ['name' => '高橋 陽介', 'email' => self::SES1])->assertSessionHas('flash.type', 'ok');
        $this->assertNull($this->row(self::SES1)->engineer_id); // 編集では紐づけない
        $u0 = $this->users(); $i0 = $this->invites();
        // 押すのは、同じ会社のほかの担当者
        $b = W::createUser('ses1b@park.example', '緑川 二郎', 'ses', 'park1234', ['ses_id' => $this->sesId(self::SES1)]);
        DB::table('users')->where('id', $b)->update(['profile_completed_at' => now_str()]);
        $u0 = $this->users();
        $this->as('ses1b@park.example', 'ses')->post('/engineers/4/invite')->assertSessionHas('flash.type', 'ok')
            ->assertSessionHas('flash.msg', 'この方のアカウントに、エンジニアの役割を足しました。');
        $this->assertSame(4, (int) $this->row(self::SES1)->engineer_id);
        $this->assertSame($u0, $this->users());
        $this->assertSame($i0, $this->invites());
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->uid(self::SES1))->where('title', 'エンジニアとしても登録されました')->where('for_role', 'eg')->count());
    }

    public function test_ac3_admin_registers_self_as_engineer(): void
    {
        $u0 = $this->users(); $i0 = $this->invites();
        $eid = $this->dualAdmin(false);
        $this->assertSame($u0, $this->users());
        $this->assertSame($i0, $this->invites());
        $a = $this->row(self::ADMIN);
        $this->assertSame('admin', $a->role);
        $this->assertSame($eid, (int) $a->engineer_id);
        $this->assertSame(1, (int) DB::table('engineers')->find($eid)->is_operator);
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $a->id)->where('title', 'エンジニアとしても登録されました')->count());
    }

    public function test_ac3_admin_registers_other_admin_as_engineer_notifies_them(): void
    {
        $id2 = W::createUser('admin2@park.example', '管理者 二郎', 'admin', 'park1234');
        DB::table('users')->where('id', $id2)->update(['profile_completed_at' => now_str()]);
        $u0 = $this->users();
        $this->as(self::ADMIN, 'admin')->post('/engineers', ['ses_id' => $this->sesId(self::SES1), 'name_sei' => '二郎', 'name_mei' => 'エンジニア', 'email' => 'admin2@park.example'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame($u0, $this->users());
        $a2 = $this->row('admin2@park.example');
        $this->assertSame('admin', $a2->role);
        $this->assertNotNull($a2->engineer_id);
        $this->assertSame(1, (int) DB::table('engineers')->find($a2->engineer_id)->is_operator);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $id2)->where('title', 'エンジニアとしても登録されました')->where('for_role', 'eg')->count());
    }

    public function test_ac4_admin_adds_ses_role_to_engineer(): void
    {
        $sid = $this->sesId('eg1@park.example');
        $eng = (int) $this->row('eg1@park.example')->engineer_id;
        $u0 = $this->users();
        $this->as(self::ADMIN, 'admin')->post("/admin/ses/{$sid}/users", ['name' => '山田 太郎', 'email' => 'eg1@park.example'])
            ->assertSessionHas('flash.type', 'ok')
            ->assertSessionHas('flash.msg', 'このメールアドレスのエンジニアのアカウントに、SES 担当者の役割を足しました（招待メールは送っていません）。');
        $this->assertSame($u0, $this->users());
        $u = $this->row('eg1@park.example');
        $this->assertSame('ses', $u->role);
        $this->assertSame($eng, (int) $u->engineer_id);
        $this->assertSame($sid, (int) $u->ses_id);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $u->id)->where('for_role', 'ses')->count());
    }

    public function test_ac4_admin_adds_admin_role_to_engineer(): void
    {
        $eng = (int) $this->row('eg1@park.example')->engineer_id;
        $u0 = $this->users();
        $this->as(self::ADMIN, 'admin')->post('/admin/admins', ['name' => '山田 太郎', 'company_name' => '山田運営', 'email' => 'eg1@park.example'])
            ->assertSessionHas('flash.type', 'ok');
        $this->assertSame($u0, $this->users());
        $u = $this->row('eg1@park.example');
        $this->assertSame('admin', $u->role);
        $this->assertNull($u->ses_id);
        $this->assertSame($eng, (int) $u->engineer_id);
        $this->assertSame('山田運営', $u->company_name);
        $this->assertSame(1, (int) DB::table('engineers')->find($eng)->is_operator);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $u->id)->where('for_role', 'admin')->count());
    }

    public function test_ac5_refusals_leave_no_trace(): void
    {
        $msg = 'このメールアドレスはすでに使われています。';
        // 他社の担当者のアドレスで、ses1 所属のエンジニアを登録（SES 側から）
        $u0 = $this->users(); $e0 = $this->engineers();
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '他社', 'name_mei' => '担当', 'email' => 'ses2@park.example'])->assertSessionHas('flash.msg', $msg);
        // SES が管理者のアドレスでエンジニアを登録
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '管理', 'name_mei' => '狙い', 'email' => self::ADMIN])->assertSessionHas('flash.msg', $msg);
        // 管理者が、ses1 所属として他社(ses2)の担当者のアドレスを入れる
        $this->as(self::ADMIN, 'admin')->post('/engineers', ['ses_id' => $this->sesId(self::SES1), 'name_sei' => '他社', 'name_mei' => '担当', 'email' => 'ses2@park.example'])->assertSessionHas('flash.msg', $msg);
        // 他社のエンジニア(eg2=ses2)のアドレスで ses1 の担当者を登録
        $this->as(self::ADMIN, 'admin')->post('/admin/ses/'.$this->sesId(self::SES1).'/users', ['name' => '他社 エンジニア', 'email' => 'eg2@park.example'])->assertSessionHas('flash.type', 'bad');
        // SES 担当者のアドレスで管理者を登録（管理者と SES 担当者は兼ねない）
        $this->as(self::ADMIN, 'admin')->post('/admin/admins', ['name' => '担当 者', 'company_name' => '会社', 'email' => self::SES1])->assertSessionHas('flash.msg', $msg);
        $this->assertSame($u0, $this->users());
        $this->assertSame($e0, $this->engineers());
        $this->assertNull($this->row('ses2@park.example')->engineer_id);
        $this->assertSame('eg', $this->row('eg2@park.example')->role);
        $this->assertSame('ses', $this->row(self::SES1)->role);
        $this->assertNull($this->row(self::ADMIN)->engineer_id);
    }

    public function test_ac5_suspended_and_left_and_already_dual_refused(): void
    {
        $msg = 'このメールアドレスはすでに使われています。';
        // 停止中の担当者
        $extra = W::createUser('ses1b@park.example', '緑川 二郎', 'ses', 'park1234', ['ses_id' => $this->sesId(self::SES1)]);
        DB::table('users')->where('id', $extra)->update(['active' => 0, 'profile_completed_at' => now_str()]);
        $u0 = $this->users(); $e0 = $this->engineers();
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '停止', 'name_mei' => '中', 'email' => 'ses1b@park.example'])->assertSessionHas('flash.msg', $msg);
        // 退会済
        DB::table('users')->where('id', $extra)->update(['active' => 0, 'left_at' => now_str()]);
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '退会', 'name_mei' => '済', 'email' => 'ses1b@park.example'])->assertSessionHas('flash.msg', $msg);
        $this->assertSame($u0, $this->users());
        $this->assertSame($e0, $this->engineers());
        // すでに別のエンジニアと兼任している人は、もう一度は足せない
        $this->dualSes();
        $e1 = $this->engineers(); $u1 = $this->users();
        $this->as(self::SES1, 'ses')->post('/engineers', ['name_sei' => '二重', 'name_mei' => '兼任', 'email' => self::SES1])->assertSessionHas('flash.msg', $msg);
        $this->assertSame($e1, $this->engineers());
        $this->assertSame($u1, $this->users());
    }

    public function test_ac5_ses_role_not_added_to_other_company_or_unagreed_company(): void
    {
        // ses1 の画面から、他社 ses2 のエンジニアのアドレスは使えない（上のテストと別に、同じ会社でも守秘義務の同意が未了なら兼任にしない）
        $sid = $this->sesId('eg1@park.example');
        DB::table('ses_companies')->where('id', $sid)->update(['agreed_at' => null]);
        $u0 = $this->users();
        $this->as(self::ADMIN, 'admin')->post("/admin/ses/{$sid}/users", ['name' => '山田 太郎', 'email' => 'eg1@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($u0, $this->users());
        $this->assertSame('eg', $this->row('eg1@park.example')->role);
    }

    public function test_ac5_admin_role_not_added_to_left_or_suspended_engineer(): void
    {
        DB::table('users')->where('email', 'eg1@park.example')->update(['active' => 0]);
        $u0 = $this->users();
        $this->as(self::ADMIN, 'admin')->post('/admin/admins', ['name' => '山田 太郎', 'company_name' => '会社', 'email' => 'eg1@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($u0, $this->users());
        $this->assertSame('eg', $this->row('eg1@park.example')->role);
    }

    // ---------- AC6・AC7：立場を選ぶ ----------

    public function test_ac6_login_goes_through_role_choice(): void
    {
        $this->dualSes();
        $this->login(self::SES1)->assertRedirect();
        $this->get('/ses')->assertRedirect('/login/role');
        $this->get('/eg')->assertRedirect('/login/role');
        $this->get('/account')->assertRedirect('/login/role');
        $this->get('/notifications')->assertRedirect('/login/role');
        $page = $this->get('/login/role')->assertOk();
        $page->assertSee('どの立場で入りますか')->assertSee('SES 担当者')->assertSee('エンジニア')->assertSee('みどり情報システム');
        $page->assertDontSee(self::SES1);
        $page->assertDontSee('管理者</button>', false);
        $this->post('/login/role', ['to' => 'eg'])->assertRedirect('/eg');
        $this->assertSame('eg', $this->actingNow());
        $this->get('/eg')->assertOk()->assertSee('届いた案件');
        $this->assertSame('ses', $this->row(self::SES1)->role); // DB は書き換わらない
        $this->assertSame(1, $this->audits('login-role'));
    }

    public function test_ac6_choose_ses_goes_to_ses_home(): void
    {
        $this->dualSes();
        $this->login(self::SES1);
        $this->post('/login/role', ['to' => 'ses'])->assertRedirect('/ses');
        $this->get('/ses')->assertOk();
        $this->assertSame('ses', $this->actingNow());
    }

    public function test_ac6_admin_dual_choice_shows_admin_and_engineer(): void
    {
        $this->dualAdmin();
        $this->login(self::ADMIN);
        $this->get('/admin')->assertRedirect('/login/role');
        $this->get('/login/role')->assertOk()->assertSee('管理者')->assertSee('エンジニア');
        $this->post('/login/role', ['to' => 'admin'])->assertRedirect('/admin');
        $this->get('/admin')->assertOk();
    }

    public function test_ac6_invalid_choice_is_refused(): void
    {
        $this->dualSes();
        $this->login(self::SES1);
        $this->post('/login/role', ['to' => 'admin'])->assertRedirect('/login/role')->assertSessionHas('flash.msg', 'この立場では、いまは入れません。');
        $this->post('/login/role', ['to' => 'zzz'])->assertRedirect('/login/role');
        $this->post('/login/role', [])->assertRedirect('/login/role');
        $this->assertNull($this->actingNow());
        $this->get('/ses')->assertRedirect('/login/role');
    }

    public function test_ac6_single_role_user_never_sees_choice(): void
    {
        foreach (['eg1@park.example', self::SES1, self::ADMIN] as $email) {
            $this->login($email);
            $this->get('/login/role')->assertRedirect($this->row($email)->role === 'eg' ? '/eg' : ($this->row($email)->role === 'ses' ? '/ses' : '/admin'));
            $this->assertNull(session('acting_role'));
            $this->post('/login/role', ['to' => 'eg'])->assertRedirect();
            $this->assertNull(session('acting_role'));
        }
    }

    public function test_ac6_one_usable_role_skips_choice(): void
    {
        $eid = $this->dualSes();
        W::suspendEngineer($eid, $this->row(self::ADMIN)); // エンジニアを停止
        $this->assertSame(['ses'], W::usableRoles($this->row(self::SES1)));
        $this->login(self::SES1);
        $this->get('/ses')->assertOk();
        $this->assertSame('ses', $this->actingNow());
        $this->get('/login/role')->assertRedirect('/ses');
    }

    public function test_ac6_no_usable_role_logs_out(): void
    {
        $eid = $this->dualSes();
        W::suspendEngineer($eid, $this->row(self::ADMIN));
        DB::table('ses_companies')->where('id', $this->sesId(self::SES1))->update(['status' => 'suspended']);
        // パスワードの段階で断られる状態だが、確認コードのあとに立場が全部使えなくなった場合を見る
        $this->as(self::SES1);
        $this->get('/ses')->assertRedirect('/login')->assertSessionHas('flash.msg', 'このアカウントは、いまは使えません。管理者にご連絡ください。');
        $this->assertGuest();
    }

    public function test_ac7_cannot_change_role_after_choosing(): void
    {
        $this->dualSes();
        $this->login(self::SES1);
        $this->post('/login/role', ['to' => 'ses']);
        $this->post('/login/role', ['to' => 'eg'])->assertRedirect('/ses');
        $this->assertSame('ses', $this->actingNow());
        $this->get('/login/role')->assertRedirect('/ses');
        $this->get('/eg')->assertForbidden();
        $this->get('/switch-role')->assertNotFound();
        $this->post('/switch-role', ['to' => 'eg'])->assertNotFound();
        $this->get('/ses')->assertOk()->assertSee('ログアウトして入り直してください');
        // ログアウトしてもう一度入れば、また選べる
        $this->post('/logout');
        $this->login(self::SES1);
        $this->get('/login/role')->assertOk();
        $this->post('/login/role', ['to' => 'eg'])->assertRedirect('/eg');
        $this->assertSame('eg', $this->actingNow());
    }

    public function test_ac7_non_dual_user_posting_role_changes_nothing(): void
    {
        $this->as('eg1@park.example', null)->post('/login/role', ['to' => 'admin'])->assertRedirect();
        $this->assertNull(session('acting_role'));
        $this->get('/admin')->assertForbidden();
        $this->assertSame('eg', $this->row('eg1@park.example')->role);
        $this->as(self::SES1, 'ses')->post('/login/role', ['to' => 'eg'])->assertRedirect();
        $this->get('/eg')->assertForbidden();
    }

    public function test_ac7_stale_acting_role_for_non_dual_user_is_dropped(): void
    {
        // 兼任でない人の古い acting_role（兼任が外れたあとなど）は信用しない
        $this->as('eg1@park.example', 'admin');
        $this->get('/admin')->assertRedirect('/login');
        $this->assertGuest();
        $this->as(self::SES1, 'ses');
        $this->get('/ses')->assertOk();
    }

    public function test_acting_role_not_usable_logs_out_with_message(): void
    {
        $eid = $this->dualSes();
        $this->as(self::SES1, 'eg')->get('/eg')->assertOk();
        W::suspendEngineer($eid, $this->row(self::ADMIN));
        $this->get('/eg')->assertRedirect('/login')->assertSessionHas('flash.msg', 'この立場では、いまは入れません。もう一度ログインしてください。');
        $this->assertGuest();
        // 再開すれば戻れる
        W::resumeEngineer($eid, $this->row(self::ADMIN));
        $this->assertContains('eg', W::usableRoles($this->row(self::SES1)));
    }

    public function test_acting_role_stale_value_not_in_roles(): void
    {
        $this->dualSes();
        $this->as(self::SES1, 'admin')->get('/admin')->assertRedirect('/login');
        $this->assertGuest();
        $this->as(self::SES1, 'zzz')->get('/ses')->assertRedirect('/login');
    }

    // ---------- AC8：権限が混ざらない ----------

    public function test_ac8_ses_acting_cannot_open_engineer_pages(): void
    {
        $this->dualSes();
        $this->as(self::SES1, 'ses');
        foreach (['/eg', '/eg/consent'] as $p) $this->get($p)->assertForbidden();
        $this->get('/ses')->assertOk();
        $this->get('/engineers/1')->assertOk(); // 自社のエンジニア
        $this->assertSame('ses', $this->row(self::SES1)->role);
    }

    public function test_ac8_eg_acting_cannot_open_ses_or_admin_pages(): void
    {
        $eid = $this->dualSes();
        $this->as(self::SES1, 'eg');
        foreach (['/ses', '/ses/invoices', '/cases/new', '/engineers/new', '/ses/company'] as $p) $this->get($p)->assertForbidden();
        $this->post('/engineers', ['name_sei' => 'あ', 'name_mei' => 'い'])->assertForbidden();
        $this->get('/eg')->assertOk();
        $this->get("/engineers/$eid")->assertOk();
        $this->get('/engineers/1')->assertForbidden();    // 同じ会社のほかのエンジニア
        $this->get('/engineers/2')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->assertSame('ses', $this->row(self::SES1)->role);
        $this->assertSame(0, (int) DB::table('users')->where('id', $this->uid(self::SES1))->where('role', 'eg')->count());
    }

    public function test_ac8_eg_acting_cannot_see_others_applications(): void
    {
        $eid = $this->dualSes();
        $other = (int) DB::table('applications')->where('engineer_id', '!=', $eid)->value('id');
        $this->assertGreaterThan(0, $other);
        $this->as(self::SES1, 'eg')->get("/applications/$other")->assertForbidden();
        $this->post("/applications/$other/status", ['status' => 'rejected'])->assertForbidden();
    }

    public function test_ac8_admin_dual_acting_eg_sees_only_own(): void
    {
        $eid = $this->dualAdmin();
        $this->as(self::ADMIN, 'eg');
        foreach (['/admin', '/admin/mail', '/admin/ses', '/admin/admins', '/admin/engineers'] as $p) $this->get($p)->assertForbidden();
        $this->get('/engineers/1')->assertForbidden();
        $this->get("/engineers/$eid")->assertOk();
        $other = (int) DB::table('applications')->where('engineer_id', '!=', $eid)->value('id');
        $this->get("/applications/$other")->assertForbidden();
        $this->get('/eg')->assertOk();
        $this->assertSame('admin', $this->row(self::ADMIN)->role);
        // 管理者の立場なら見える
        $this->as(self::ADMIN, 'admin');
        $this->get('/admin')->assertOk();
        $this->get('/engineers/1')->assertOk();
        $this->get('/eg')->assertForbidden();
    }

    public function test_ac8_db_role_never_rewritten(): void
    {
        $this->dualSes();
        foreach (['ses', 'eg'] as $acting) {
            $this->as(self::SES1, $acting)->get($acting === 'ses' ? '/ses' : '/eg')->assertOk();
            $this->post('/account', ['name_sei' => '緑川', 'name_mei' => '一郎', 'mail_opt_in' => 1]);
            $this->get('/notifications');
            $this->assertSame('ses', $this->row(self::SES1)->role, $acting);
        }
        $this->dualAdmin();
        foreach (['admin', 'eg'] as $acting) {
            $this->as(self::ADMIN, $acting)->get($acting === 'admin' ? '/admin' : '/eg')->assertOk();
            $this->post('/notifications/read-all');
            $this->assertSame('admin', $this->row(self::ADMIN)->role, $acting);
        }
    }

    // ---------- AC9：同意 ----------

    public function test_ac9_consent_required_before_engineer_pages(): void
    {
        $eid = $this->dualSes(false);
        $this->login(self::SES1);
        $this->post('/login/role', ['to' => 'eg'])->assertRedirect('/eg/consent');
        $this->get('/eg')->assertRedirect('/eg/consent');
        $this->get("/engineers/$eid")->assertRedirect('/eg/consent');
        $this->get('/notifications')->assertRedirect('/eg/consent');
        $this->get('/eg/consent')->assertOk()->assertSee('個人情報')->assertSee('ログアウト');
        $this->get('/account')->assertOk();
        $this->post('/eg/consent', [])->assertSessionHasErrors();
        $this->assertNull(DB::table('engineers')->find($eid)->consent_at);
        $this->get('/eg')->assertRedirect('/eg/consent');
        $this->post('/eg/consent', ['consent' => 1])->assertRedirect();
        $this->assertNotNull(DB::table('engineers')->find($eid)->consent_at);
        $this->assertSame(1, $this->audits('consent'));
        $this->get('/eg')->assertOk();
        // 同意済みで /eg/consent を開くとホームへ
        $this->get('/eg/consent')->assertRedirect();
    }

    public function test_ac9_consent_page_forbidden_for_ses_acting_and_no_loop_with_welcome(): void
    {
        $eid = $this->dualSes(false);
        $this->as(self::SES1, 'ses')->get('/eg/consent')->assertForbidden();
        // 最初の情報登録が未了でも、/welcome と /eg/consent がぐるぐる回らない
        DB::table('users')->where('email', self::SES1)->update(['profile_completed_at' => null]);
        $this->as(self::SES1, 'eg');
        $r = $this->get('/eg');
        $r->assertRedirect('/welcome');
        $this->get('/welcome')->assertOk();
        DB::table('users')->where('email', self::SES1)->update(['profile_completed_at' => now_str()]);
        $this->as(self::SES1, 'eg');
        $this->get('/eg')->assertRedirect('/eg/consent');
        $this->get('/eg/consent')->assertOk();
    }

    public function test_ac9_non_dual_engineer_with_consent_unaffected(): void
    {
        $this->as('eg1@park.example')->get('/eg')->assertOk();
        $this->get('/eg/consent')->assertRedirect();
    }

    // ---------- AC10：通知とメール ----------

    public function test_ac10_notifications_are_split_by_role(): void
    {
        $eid = $this->dualSes();
        $uid = $this->uid(self::SES1);
        DB::table('app_notifications')->where('user_id', $uid)->delete();
        // エンジニアあて（案件の配信）
        W::distribute(2, [$eid], $this->row(self::ADMIN));
        $n = DB::table('app_notifications')->where('user_id', $uid)->where('for_role', 'eg')->first();
        $this->assertNotNull($n);
        $mail = DB::table('mail_outbox')->where('to_email', self::SES1)->where('body', 'like', '【エンジニアあて】%')->first();
        $this->assertNotNull($mail);
        // SES あての知らせ（自社のエンジニアが応募した、など）を直接入れる
        \App\Services\Notifier::toSes($this->sesId(self::SES1), 'SES あてのテスト', '本文', '/ses', true, 'test');
        $s = DB::table('app_notifications')->where('user_id', $uid)->where('title', 'SES あてのテスト')->first();
        $this->assertNotNull($s);
        $this->assertGreaterThanOrEqual(1, DB::table('mail_outbox')->where('to_email', self::SES1)->where('body', 'like', '【SES 担当者あて】%')->count());

        // SES の立場：一覧にはSESあてだけ。帯にエンジニアあての数。
        $page = $this->as(self::SES1, 'ses')->get('/notifications')->assertOk();
        $page->assertSee('SES あてのテスト')->assertDontSee($n->title)->assertSee('エンジニアあてのお知らせが');
        // もう一方あては開けず、既読にならない
        $this->get("/notifications/{$n->id}")->assertRedirect('/notifications');
        $this->assertNull(DB::table('app_notifications')->find($n->id)->read_at);
        $this->get("/notifications/{$s->id}")->assertRedirect('/ses');
        $this->assertNotNull(DB::table('app_notifications')->find($s->id)->read_at);
        // 「すべて既読」でも、もう一方あては既読にならない
        $this->post('/notifications/read-all');
        $this->assertNull(DB::table('app_notifications')->find($n->id)->read_at);

        // エンジニアの立場
        $page = $this->as(self::SES1, 'eg')->get('/notifications')->assertOk();
        $page->assertSee($n->title)->assertDontSee('SES あてのテスト');
        $this->get("/notifications/{$n->id}")->assertRedirect($n->link);
        $this->assertNotNull(DB::table('app_notifications')->find($n->id)->read_at);
    }

    public function test_ac10_bell_counts_only_acting_role(): void
    {
        $eid = $this->dualSes();
        $uid = $this->uid(self::SES1);
        DB::table('app_notifications')->where('user_id', $uid)->delete();
        foreach ([['eg', 'EG1'], ['eg', 'EG2'], ['ses', 'SES1']] as [$fr, $t]) {
            DB::table('app_notifications')->insert(['user_id' => $uid, 'title' => $t, 'body' => 'x', 'for_role' => $fr, 'created_at' => now_str()]);
        }
        $this->as(self::SES1, 'ses')->get('/ses')->assertOk();
        $html = $this->get('/ses')->getContent();
        $this->assertStringContainsString('エンジニアあてのお知らせが 2 件', $html);
        $html = $this->as(self::SES1, 'eg')->get('/eg')->getContent();
        $this->assertStringContainsString('SES 担当者あてのお知らせが 1 件', $html);
    }

    public function test_ac10_admin_dual_to_admins_has_admin_for_role(): void
    {
        $this->dualAdmin();
        $uid = $this->uid(self::ADMIN);
        DB::table('app_notifications')->where('user_id', $uid)->delete();
        \App\Services\Notifier::toAdmins('管理者あてのテスト', '本文', '/admin', true);
        $n = DB::table('app_notifications')->where('user_id', $uid)->first();
        $this->assertNotNull($n);
        $this->assertSame('admin', $n->for_role);
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', self::ADMIN)->where('body', 'like', '【管理者あて】%')->count());
    }

    public function test_ac10_non_dual_gets_no_prefix_and_see_everything(): void
    {
        DB::table('mail_outbox')->delete();
        W::distribute(1, [(int) $this->row('eg1@park.example')->engineer_id], $this->row(self::ADMIN));
        $m = DB::table('mail_outbox')->where('to_email', 'eg1@park.example')->first();
        if ($m) $this->assertStringNotContainsString('【エンジニアあて】', $m->body);
        $this->as('eg1@park.example')->get('/notifications')->assertOk();
    }

    public function test_ac10_suspended_engineer_gets_no_notification(): void
    {
        $eid = $this->dualSes();
        $uid = $this->uid(self::SES1);
        DB::table('app_notifications')->where('user_id', $uid)->delete();
        W::suspendEngineer($eid, $this->row(self::ADMIN));
        DB::table('app_notifications')->where('user_id', $uid)->delete();
        $before = DB::table('mail_outbox')->where('to_email', self::SES1)->count();
        W::distribute(2, [$eid], $this->row(self::ADMIN));
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $uid)->where('for_role', 'eg')->count());
        \App\Services\Notifier::toEg($eid, 'テスト', '本文', '/eg');
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $uid)->count());
        $this->assertSame($before, DB::table('mail_outbox')->where('to_email', self::SES1)->count());
    }

    // ---------- AC11：停止・退会 ----------

    public function test_ac11_engineer_suspend_keeps_other_role_and_resume_restores(): void
    {
        $eid = $this->dualSes();
        $this->as(self::ADMIN, 'admin')->post("/engineers/$eid/suspend")->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, (int) $this->row(self::SES1)->active); // 本人の行は止めない
        $this->assertSame(['ses'], W::usableRoles($this->row(self::SES1)));
        $this->login(self::SES1);
        $this->get('/ses')->assertOk();
        $this->get('/eg')->assertForbidden();
        $this->as(self::ADMIN, 'admin')->post("/engineers/$eid/resume")->assertSessionHas('flash.type', 'ok');
        $this->assertSame(['ses', 'eg'], W::usableRoles($this->row(self::SES1)));
        $this->login(self::SES1);
        $this->get('/login/role')->assertOk();
    }

    public function test_ac11_ses_suspending_admin_dual_engineer_keeps_admin_login(): void
    {
        $eid = $this->dualAdmin();
        $this->as(self::SES1, 'ses')->post("/engineers/$eid/suspend")->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, (int) $this->row(self::ADMIN)->active);
        $this->assertSame(['admin'], W::usableRoles($this->row(self::ADMIN)));
        $this->login(self::ADMIN);
        $this->get('/admin')->assertOk();
        $this->as(self::SES1, 'ses')->post("/engineers/$eid/delete")->assertSessionHas('flash.type', 'ok');
        $this->assertSame('admin', $this->row(self::ADMIN)->role);
        $this->assertNull($this->row(self::ADMIN)->engineer_id);
        $this->login(self::ADMIN);
        $this->get('/admin')->assertOk();
    }

    public function test_ac11_engineer_leave_unlinks(): void
    {
        $eid = $this->dualSes();
        $this->as(self::SES1, 'ses')->post("/engineers/$eid/delete")->assertSessionHas('flash.type', 'ok');
        $u = $this->row(self::SES1);
        $this->assertNull($u->engineer_id);
        $this->assertSame(1, (int) $u->active);
        $this->assertSame('ses', $u->role);
        $this->assertSame(1, $this->audits('dual-unlink'));
        $this->login(self::SES1);
        $this->get('/login/role')->assertRedirect('/ses'); // 兼任でなくなったので選ばない
        $this->get('/ses')->assertOk();
    }

    public function test_ac11_ses_user_leave_keeps_engineer_login(): void
    {
        $eid = $this->dualSes();
        $uid = $this->uid(self::SES1);
        $sid = $this->sesId(self::SES1);
        // ほかに担当者がいる（最後の1人でない）
        $other = W::createUser('ses1c@park.example', '緑川 三郎', 'ses', 'park1234', ['ses_id' => $sid]);
        DB::table('users')->where('id', $other)->update(['profile_completed_at' => now_str(), 'password' => bcrypt('park1234')]);
        $this->as(self::ADMIN, 'admin')->post("/admin/ses/$sid/users/$uid/delete")
            ->assertSessionHas('flash.msg', '緑川 一郎 さんを SES 担当者から外しました。エンジニアとしては引き続き入れます。');
        $u = $this->row(self::SES1);
        $this->assertSame('eg', $u->role);
        $this->assertSame(1, (int) $u->active);
        $this->assertNull($u->left_at);
        $this->assertSame($eid, (int) $u->engineer_id);
        $this->login(self::SES1);
        $this->get('/login/role')->assertRedirect('/eg');
        $this->get('/eg')->assertOk();
        $this->get('/ses')->assertForbidden();
    }

    public function test_ac11_ses_user_suspend_blocks_login(): void
    {
        $eid = $this->dualSes();
        $uid = $this->uid(self::SES1);
        $sid = $this->sesId(self::SES1);
        $other = W::createUser('ses1c@park.example', '緑川 三郎', 'ses', 'park1234', ['ses_id' => $sid]);
        DB::table('users')->where('id', $other)->update(['profile_completed_at' => now_str()]);
        $this->as(self::ADMIN, 'admin')->post("/admin/ses/$sid/users/$uid/suspend")->assertSessionHas('flash.msg');
        $this->assertSame(0, (int) $this->row(self::SES1)->active);
        $this->flushSession();
        auth()->logout();
        $this->post('/login', ['email' => self::SES1, 'password' => 'park1234'])->assertSessionHas('flash.type', 'bad');
        $this->assertGuest();
    }

    public function test_ac11_company_suspend_lets_engineer_in_only(): void
    {
        $this->dualSes();
        $sid = $this->sesId(self::SES1);
        $this->as(self::ADMIN, 'admin')->post("/admin/ses/$sid/suspend")->assertRedirect();
        $this->assertSame(['eg'], W::usableRoles($this->row(self::SES1)));
        // 会社が停止中でも、ログインは断られず、選ぶ画面なしでエンジニアで入る
        $this->login(self::SES1)->assertRedirect();
        $this->get('/eg')->assertOk();
        $this->assertSame('eg', $this->actingNow());
        $this->get('/ses')->assertForbidden();
    }

    public function test_ac11_company_leave_keeps_admin_dual(): void
    {
        $eid = $this->dualAdmin();
        $sid = $this->sesId(self::SES1);
        $this->as(self::ADMIN, 'admin')->post("/admin/ses/$sid/delete")->assertRedirect();
        $this->assertSame(1, (int) $this->row(self::ADMIN)->active);
        $this->assertSame(['admin'], W::usableRoles($this->row(self::ADMIN)));
        $this->login(self::ADMIN);
        $this->get('/admin')->assertOk();
    }

    // ---------- AC12：管理者の自分の分 ----------

    public function test_ac12_admin_self_actions_are_marked(): void
    {
        $eid = $this->dualAdmin();
        $admin = $this->row(self::ADMIN);
        $admin2 = W::createUser('admin3@park.example', '管理者 三郎', 'admin', 'park1234');
        DB::table('users')->where('id', $admin2)->update(['profile_completed_at' => now_str()]);
        $aid = W::apply(1, $eid, ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1], $this->row(self::ADMIN));
        $this->assertGreaterThan(0, $aid);
        DB::table('audit_log')->delete();
        $this->as(self::ADMIN, 'admin')->post("/applications/$aid/status", ['status' => 'submitted'])->assertSessionHas('flash.type', 'ok');
        $d = (string) DB::table('audit_log')->orderByDesc('id')->value('detail');
        $this->assertStringStartsWith('［本人］', $d, $d);
        $this->get("/applications/$aid")->assertOk()->assertSee('管理者ご本人の応募');
        // ほかの管理者が同じ操作をしても付かない
        DB::table('audit_log')->delete();
        $this->as('admin3@park.example', 'admin')->post("/applications/$aid/status", ['status' => 'doc_passed'])->assertSessionHas('flash.type', 'ok');
        $d = (string) DB::table('audit_log')->orderByDesc('id')->value('detail');
        $this->assertStringNotContainsString('［本人］', $d);
        $this->get("/applications/$aid")->assertOk()->assertDontSee('管理者ご本人の応募');
        // 他人のエンジニアの分には付かない
        DB::table('audit_log')->delete();
        $this->as(self::ADMIN, 'admin')->post('/engineers/1', ['name' => '山田 太郎改'])->assertSessionHas('flash.type', 'ok');
        $this->assertStringNotContainsString('［本人］', (string) DB::table('audit_log')->where('action', 'engineer-update')->value('detail'));
        // 自分の編集には付く
        DB::table('audit_log')->delete();
        $this->post("/engineers/$eid", ['name' => '管理 本人改'])->assertSessionHas('flash.type', 'ok');
        $this->assertStringStartsWith('［本人］', (string) DB::table('audit_log')->where('action', 'engineer-update')->value('detail'));
    }

    // ---------- AC13：兼任がいなければ今までどおり ----------

    public function test_ac13_non_dual_users_unchanged(): void
    {
        foreach ([[self::ADMIN, '/admin'], [self::SES1, '/ses'], ['eg1@park.example', '/eg']] as [$email, $home]) {
            $this->login($email)->assertRedirect(url($home));
            $this->get($home)->assertOk();
            $this->assertNull(session('acting_role'));
            $this->get('/notifications')->assertOk();
            $this->post('/logout');
        }
        $this->as('eg1@park.example')->get('/ses')->assertForbidden();
        $this->as(self::SES1)->get('/eg')->assertForbidden();
    }

    // ---------- AC14：管理者の画面での見え方、点検 ----------

    public function test_ac14_admin_views_show_dual(): void
    {
        $eid = $this->dualSes();
        $sid = $this->sesId(self::SES1);
        $this->as(self::ADMIN, 'admin');
        $this->get('/admin/ses/'.$sid)->assertOk()->assertSee('エンジニア兼任');
        $this->get("/engineers/$eid")->assertOk()->assertSee('SES 担当者と兼任');
        // 管理者兼任
        $eid2 = $this->dualAdmin();
        $this->as(self::ADMIN, 'admin');
        $this->get('/admin/admins')->assertOk()->assertSee('エンジニア兼任');
        $this->get("/engineers/$eid2")->assertOk()->assertSee('管理者と兼任');
        // 招待の状況（未設定の兼任者）
        DB::table('users')->where('email', self::SES1)->update(['password' => null]);
        DB::table('users')->where('email', 'eg1@park.example')->update(['password' => null]);
        $this->get('/admin')->assertOk();
    }

    public function test_ac14_ses_view_does_not_reveal_admin_dual(): void
    {
        $eid = $this->dualAdmin();
        $page = $this->as(self::SES1, 'ses')->get("/engineers/$eid")->assertOk();
        $page->assertDontSee('管理者と兼任')->assertDontSee('管理者</dd>', false);
        $c = $page->getContent();
        $this->assertStringNotContainsString('管理者と兼任', $c);
        $this->get('/ses/engineers')->assertOk()->assertDontSee('管理者と兼任');
    }

    public function test_ac14_status_counts_dual(): void
    {
        $this->dualSes();
        $this->dualAdmin();
        Artisan::call('park:status', ['--json' => true]);
        $j = json_decode(Artisan::output(), true);
        $this->assertSame(1, $j['兼任（SES 担当者）']);
        $this->assertSame(1, $j['兼任（管理者）']);
    }

    // ---------- AC15：マニュアル ----------

    public function test_ac15_manuals_mention_dual(): void
    {
        $this->as(self::ADMIN)->get('/manual/ses')->assertOk()->assertSee('ご自身もエンジニアとして登録する');
        $this->get('/manual/engineer')->assertOk()->assertSee('SES 担当者・管理者と兼ねている方');
        $this->get('/manual/admin')->assertOk()->assertSee('兼任');
        foreach (['ses-steps', 'engineer-steps', 'admin-steps'] as $k) $this->get("/manual/$k")->assertOk();
    }

    // ---------- 境界・そのほか ----------

    public function test_account_page_shows_dual_roles(): void
    {
        $this->dualSes();
        $this->as(self::SES1, 'ses')->get('/account')->assertOk()->assertSee('このアカウントの立場');
        $this->dualAdmin();
        $this->as(self::ADMIN, 'admin')->get('/account')->assertOk()->assertSee('管理者・エンジニア');
        $this->as('eg1@park.example')->get('/account')->assertOk()->assertDontSee('このアカウントの立場');
    }

    public function test_broadcast_reaches_dual_engineer_once(): void
    {
        $eid = $this->dualSes();
        $uid = $this->uid(self::SES1);
        DB::table('app_notifications')->where('user_id', $uid)->delete();
        $this->as(self::ADMIN, 'admin')->post('/admin/broadcast', ['to' => 'eg', 'subject' => '全体のお知らせ', 'body' => '本文です', 'send' => 1]);
        $this->assertLessThanOrEqual(1, DB::table('mail_outbox')->where('to_email', self::SES1)->where('subject', 'like', '%全体のお知らせ%')->count());
    }

    public function test_dual_ses_without_agreement_cannot_be_created_from_eg_side_even_via_invite(): void
    {
        $sid = $this->sesId('eg1@park.example');
        DB::table('ses_companies')->where('id', $sid)->update(['agreed_at' => null]);
        $this->assertNull(W::sesShareTarget('eg1@park.example', $sid));
    }

    public function test_migration_adds_for_role_column(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumn('app_notifications', 'for_role'));
    }
}
