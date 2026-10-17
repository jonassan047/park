<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// 最初の情報登録・立場の帯・管理者の登録・SES の登録・管理者直属のエンジニア（PLAN 2026-10-17 の A〜E）
class ProfileAndDirectTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function unfinish(string ...$emails): void
    {
        DB::table('users')->whereIn('email', $emails)->update(['profile_completed_at' => null]);
    }

    // 管理者の直属のエンジニアを1人登録して、その engineers.id を返す（運営として操作する）
    private function directEngineer(string $email = 'direct-eg@park.example', string $name = '直属 一郎'): int
    {
        $this->as('admin@park.example')->post('/engineers', ['ses_id' => 'direct', 'name' => $name, 'email' => $email])->assertRedirect();
        return (int) DB::table('engineers')->where('name', $name)->value('id');
    }

    // ---------- A. 最初の情報登録 ----------

    public function test_a1_a13_columns_and_seed(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['kana', 'phone', 'position', 'profile_completed_at']));
        $this->assertTrue(Schema::hasColumn('ses_companies', 'kind'));
        $this->assertSame(0, DB::table('users')->whereNull('profile_completed_at')->count());
    }

    public function test_a2_a3_redirect_to_welcome_until_profile_done(): void
    {
        $this->unfinish('admin@park.example', 'ses1@park.example', 'eg1@park.example');
        $this->as('admin@park.example');
        $this->get('/admin')->assertRedirect(url('/welcome'));
        $this->get('/cases/1')->assertRedirect(url('/welcome'));
        $this->get('/notifications')->assertRedirect(url('/welcome'));
        $this->as('ses1@park.example')->get('/ses')->assertRedirect(url('/welcome'));
        $this->as('eg1@park.example')->get('/eg')->assertRedirect(url('/welcome'));
        // 役割が合わない画面でも、まず情報の登録へ
        $this->get('/admin')->assertRedirect(url('/welcome'));
        // 転送されない画面
        $this->as('admin@park.example');
        $this->get('/welcome')->assertOk();
        $this->get('/account')->assertOk();
        $this->post('/account/password', ['current' => 'まちがい', 'password' => 'abcd1234', 'password_confirmation' => 'abcd1234'])->assertRedirect(url('/account'));
        $this->get('/manual')->assertOk();
        $this->post('/logout')->assertRedirect(url('/login'));
    }

    public function test_a4_two_factor_not_done_goes_to_login(): void
    {
        $this->unfinish('admin@park.example');
        $u = User::where('email', 'admin@park.example')->first();
        $this->actingAs($u->fresh())->get('/admin')->assertRedirect();
        $this->assertStringNotContainsString('/welcome', (string) session()->get('_previous.url') . $this->get('/admin')->headers->get('Location'));
        auth()->logout();
        $this->flushSession();
        $this->get('/admin')->assertRedirect(url('/login'));
    }

    public function test_a5_welcome_form(): void
    {
        $this->unfinish('admin@park.example', 'ses1@park.example', 'eg1@park.example');
        DB::table('users')->where('email', 'ses1@park.example')->update(['kana' => 'しす いち', 'position' => '営業部']);
        $this->as('admin@park.example')->get('/welcome')->assertOk()->assertSee('あなたの情報')->assertSee('役職');
        $this->as('ses1@park.example')->get('/welcome')->assertOk()->assertSee('役職')->assertSee('しす いち')->assertSee('営業部');
        $name = DB::table('users')->where('email', 'eg1@park.example')->value('name');
        $this->as('eg1@park.example')->get('/welcome')->assertOk()->assertSee($name)->assertDontSee('役職');
        // users.name が空なら engineers.name
        DB::table('users')->where('email', 'eg1@park.example')->update(['name' => '']);
        $this->as('eg1@park.example')->get('/welcome')->assertSee('山田 太郎');
    }

    public function test_a6_empty_name_is_rejected(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->from('/welcome')->post('/welcome', ['name' => '', 'kana' => 'x'])->assertSessionHasErrors('name');
        $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
        $this->post('/welcome', ['name' => 'あ', 'phone' => 'abc'])->assertSessionHasErrors('phone');
        $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
    }

    public function test_a7_a8_save_and_destination(): void
    {
        $this->unfinish('admin@park.example', 'ses1@park.example', 'eg1@park.example');
        $in = ['name' => '登録 太郎', 'kana' => 'とうろく たろう', 'phone' => '03-0000-0000', 'position' => '部長'];
        $this->as('admin@park.example')->post('/welcome', $in)->assertRedirect(url('/admin'));
        $this->assertDatabaseHas('users', ['email' => 'admin@park.example', 'name' => '登録 太郎', 'kana' => 'とうろく たろう', 'phone' => '03-0000-0000', 'position' => '部長']);
        $this->assertNotNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));

        // SES：会社の確認がまだなら /ses/company
        $sesId = User::where('email', 'ses1@park.example')->value('ses_id');
        DB::table('ses_companies')->where('id', $sesId)->update(['self_confirmed_at' => null]);
        $this->as('ses1@park.example')->post('/welcome', $in)->assertRedirect(url('/ses/company'));
        $this->assertDatabaseHas('users', ['email' => 'ses1@park.example', 'position' => '部長']);
        $this->unfinish('ses1@park.example');
        DB::table('ses_companies')->where('id', $sesId)->update(['self_confirmed_at' => now()]);
        $this->as('ses1@park.example')->post('/welcome', $in)->assertRedirect(url('/ses'));

        // エンジニア：本人の確認がまだなら自分の画面
        $eid = User::where('email', 'eg1@park.example')->value('engineer_id');
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => null]);
        $this->as('eg1@park.example')->post('/welcome', $in + ['position' => '送っても保存しない'])->assertRedirect(url("/engineers/$eid"));
        $this->assertDatabaseHas('users', ['email' => 'eg1@park.example', 'name' => '登録 太郎', 'phone' => '03-0000-0000']);
        $this->assertNull(DB::table('users')->where('email', 'eg1@park.example')->value('position'));
        $this->unfinish('eg1@park.example');
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now()]);
        $this->as('eg1@park.example')->post('/welcome', $in)->assertRedirect(url('/eg'));
    }

    public function test_a9_welcome_does_not_touch_engineers(): void
    {
        $eid = User::where('email', 'eg1@park.example')->value('engineer_id');
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => null]);
        $this->unfinish('eg1@park.example');
        $before = (array) DB::table('engineers')->find($eid);
        $this->as('eg1@park.example')->post('/welcome', ['name' => '別の名前', 'kana' => 'べつ'])->assertRedirect();
        $this->assertSame($before, (array) DB::table('engineers')->find($eid));
        $this->assertNull(DB::table('engineers')->where('id', $eid)->value('self_confirmed_at'));
    }

    public function test_a10_done_users_and_double_post(): void
    {
        $this->as('admin@park.example')->get('/admin')->assertOk();
        $this->get('/welcome')->assertRedirect(url('/account'));
        $this->unfinish('admin@park.example');
        $this->post('/welcome', ['name' => '一回目'])->assertRedirect();
        $first = DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at');
        $this->assertNotNull($first);
        $this->travel(2)->hours();
        $this->post('/welcome', ['name' => '二回目'])->assertRedirect();
        $this->assertSame($first, DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
    }

    public function test_a11_account_basic_fields(): void
    {
        $this->as('ses1@park.example')->post('/account', ['name' => '直した 名前', 'kana' => 'なおした', 'phone' => '090-1111-2222', 'position' => '課長'])->assertRedirect(url('/account'));
        $this->assertDatabaseHas('users', ['email' => 'ses1@park.example', 'kana' => 'なおした', 'phone' => '090-1111-2222', 'position' => '課長']);
        $this->as('eg1@park.example')->post('/account', ['kana' => 'えじ', 'phone' => '080-0000-0000', 'position' => '保存されない'])->assertRedirect();
        $this->assertDatabaseHas('users', ['email' => 'eg1@park.example', 'kana' => 'えじ']);
        $this->assertNull(DB::table('users')->where('email', 'eg1@park.example')->value('position'));
        // 未登録の人は、ここからでも登録済みになる
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->post('/account', ['name' => '管理 者'])->assertRedirect();
        $this->assertNotNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
    }

    public function test_a12_setup_goes_to_welcome(): void
    {
        DB::table('users')->delete();
        $this->post('/setup', ['name' => '最初 の運営', 'email' => 'first@park.example', 'password' => 'abcd1234'])->assertRedirect(url('/welcome'));
        $this->get('/welcome')->assertOk()->assertSee('最初 の運営');
        $this->get('/admin')->assertRedirect(url('/welcome'));
    }

    // ---------- B. 立場の帯 ----------

    public function test_b_login_bar(): void
    {
        $this->as('admin@park.example')->get('/admin')->assertSee('管理者（運営）としてログインしています：'.User::where('email', 'admin@park.example')->value('name'));
        $this->as('ses1@park.example')->get('/ses')->assertSee('SES（みどり情報システム）のご担当者としてログインしています');
        $this->as('eg1@park.example')->get('/eg')->assertSee('エンジニアとしてログインしています');
        // 印刷用
        $this->as('admin@park.example')->get('/engineers/1/sheet')->assertOk()->assertDontSee('としてログインしています');
        $this->assertSame('運営', lbl('role', 'admin'));
    }

    public function test_b3_no_bar_before_two_factor(): void
    {
        $this->post('/login', ['email' => 'admin@park.example', 'password' => 'park1234'])->assertRedirect(url('/two-factor/challenge'));
        $this->get('/two-factor/challenge')->assertOk()->assertDontSee('としてログインしています');
        auth()->logout();
        $this->flushSession();
        $this->get('/login')->assertOk()->assertDontSee('としてログインしています');
    }

    // ---------- C. 管理者の登録 ----------

    public function test_c_admin_invite_and_reinvite(): void
    {
        $this->as('admin@park.example');
        $this->post('/admin/settings/admins', ['name' => '新任 管理', 'email' => 'new-admin@park.example'])->assertRedirect();
        $nu = DB::table('users')->where('email', 'new-admin@park.example')->first();
        $this->assertSame('admin', $nu->role);
        $this->assertNull($nu->password);
        $this->get('/admin/settings')->assertOk()->assertSee('管理者（運営メンバー）')->assertSee('招待中')->assertSee('使用中')->assertSee('招待を送り直す');
        // 送り直しは毎回 token が新しくなる
        $mails = DB::table('mail_outbox')->where('kind', 'invite')->count();
        $t0 = $nu->token;
        $this->post("/admin/settings/admins/{$nu->id}/reinvite")->assertRedirect(url('/admin/settings'));
        $t1 = DB::table('users')->where('id', $nu->id)->value('token');
        $this->assertNotSame($t0, $t1);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->where('kind', 'invite')->count());
        $this->post("/admin/settings/admins/{$nu->id}/reinvite");
        $this->assertNotSame($t1, DB::table('users')->where('id', $nu->id)->value('token'));
        $this->assertSame($mails + 2, DB::table('mail_outbox')->where('kind', 'invite')->count());
    }

    public function test_c3_reinvite_refused(): void
    {
        $this->as('admin@park.example');
        $me = User::where('email', 'admin@park.example')->first();
        $ses = User::where('email', 'ses1@park.example')->first();
        $mails = DB::table('mail_outbox')->count();
        foreach ([$me->id, $ses->id, 99999] as $id) {
            $this->post("/admin/settings/admins/$id/reinvite")->assertRedirect(url('/admin/settings'))->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame($me->token, DB::table('users')->where('id', $me->id)->value('token'));
        $this->assertSame($ses->token, DB::table('users')->where('id', $ses->id)->value('token'));
    }

    public function test_c_reinvite_refused_for_stopped_admin(): void
    {
        $this->as('admin@park.example');
        $this->post('/admin/settings/admins', ['name' => '止める 人', 'email' => 'stop-admin@park.example'])->assertRedirect();
        $id = DB::table('users')->where('email', 'stop-admin@park.example')->value('id');
        DB::table('users')->where('id', $id)->update(['active' => 0]);
        $token = DB::table('users')->where('id', $id)->value('token');
        $mails = DB::table('mail_outbox')->count();
        $this->post("/admin/settings/admins/$id/reinvite")->assertRedirect(url('/admin/settings'))->assertSessionHas('flash.type', 'bad');
        $this->assertSame($token, DB::table('users')->where('id', $id)->value('token'));
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->get('/admin/settings')->assertSee('停止');
    }

    public function test_c5_others_cannot_use_settings(): void
    {
        $this->as('ses1@park.example');
        $this->get('/admin/settings')->assertForbidden();
        $this->post('/admin/settings/admins/1/reinvite')->assertForbidden();
        $this->as('eg1@park.example')->post('/admin/settings/admins/1/reinvite')->assertForbidden();
    }

    // ---------- D. SES の登録 ----------

    public function test_d_admin_registers_ses(): void
    {
        $this->as('admin@park.example');
        $this->get('/admin/ses/new')->assertOk()->assertSee('登録すると参加中になり、担当の方に招待メールが届きます。');
        $notes = DB::table('app_notifications')->count();
        $this->post('/admin/ses', ['name' => '運営登録 SES', 'contact_name' => '担当 花子', 'email' => 'reg-ses@park.example'])->assertRedirect();
        $s = DB::table('ses_companies')->where('name', '運営登録 SES')->first();
        $this->assertSame('active', $s->status);
        $this->assertSame(date('Y-m-d'), substr($s->joined_at, 0, 10));
        $this->assertDatabaseHas('users', ['email' => 'reg-ses@park.example', 'role' => 'ses', 'ses_id' => $s->id]);
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'reg-ses@park.example', 'kind' => 'invite']);
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', 'ses-apply')->count());
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'like', '%参加の申し込み%')->count());
        $this->assertSame($notes, DB::table('app_notifications')->count());
    }

    public function test_d_no_email_still_active_without_user(): void
    {
        $this->as('admin@park.example');
        $users = DB::table('users')->count();
        $this->post('/admin/ses', ['name' => 'メール無し SES', 'contact_name' => '誰か'])->assertRedirect();
        $this->assertDatabaseHas('ses_companies', ['name' => 'メール無し SES', 'status' => 'active']);
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_d_registration_refused_when_email_in_use(): void
    {
        $this->as('admin@park.example');
        // 招待中（パスワード未設定）の別の立場の人と同じメール
        $this->post('/admin/settings/admins', ['name' => '招待中 管理', 'email' => 'pending-admin@park.example'])->assertRedirect();
        $ses = DB::table('ses_companies')->count();
        $mails = DB::table('mail_outbox')->count();
        $this->post('/admin/ses', ['name' => '重なる SES', 'contact_name' => '誰か', 'email' => 'pending-admin@park.example'])->assertRedirect()->assertSessionHas('flash.type', 'bad');
        $this->assertSame($ses, DB::table('ses_companies')->count());
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame('admin', DB::table('users')->where('email', 'pending-admin@park.example')->value('role'));
        // 使用中の人と同じメールも同様
        $this->post('/admin/ses', ['name' => '重なる SES 2', 'contact_name' => '誰か', 'email' => 'ses1@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($ses, DB::table('ses_companies')->count());
    }

    public function test_d4_public_apply_stays_pending(): void
    {
        $this->post('/apply', ['name' => '公開 SES', 'contact_name' => '申込 太郎', 'email' => 'pub-ses@park.example', 'agree' => '1'])->assertOk();
        $this->assertDatabaseHas('ses_companies', ['name' => '公開 SES', 'status' => 'pending']);
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'pub-ses@park.example', 'kind' => 'ses-apply']);
        $this->assertDatabaseHas('app_notifications', ['title' => 'SES から参加の申し込みがありました']);
    }

    // ---------- E. 管理者に紐づくエンジニア ----------

    public function test_e1_e2_direct_row(): void
    {
        $this->assertSame(0, DB::table('ses_companies')->where('kind', '!=', 'ses')->count());
        $a = W::directSesId();
        $this->assertSame($a, W::directSesId());
        $this->assertSame(1, DB::table('ses_companies')->where('kind', 'direct')->count());
        $row = DB::table('ses_companies')->find($a);
        $this->assertSame('active', $row->status);
        $this->assertNotNull($row->self_confirmed_at);
        $this->assertTrue(W::isDirect($a));
        $this->assertFalse(W::isDirect(1));
    }

    public function test_e12_existing_engineers_unchanged(): void
    {
        $this->assertSame(1, DB::table('engineers')->where('name', '山田 太郎')->value('ses_id'));
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count());
    }

    public function test_e3_options_and_zero_rows(): void
    {
        $this->as('admin@park.example');
        $this->get('/engineers/new')->assertOk()->assertSee('管理者の直属（SES なし）');
        $this->get('/admin/engineers?ses=direct')->assertOk(); // 0件でも開ける
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count()); // 開いただけでは作らない
        W::directSesId();
        $this->assertStringNotContainsString('PARK 運営（直属）', $this->get('/engineers/new')->getContent());
        $this->as('ses1@park.example')->get('/engineers/new')->assertOk()->assertDontSee('管理者の直属');
    }

    public function test_e4_register_direct_engineer(): void
    {
        $eid = $this->directEngineer();
        $direct = (int) DB::table('ses_companies')->where('kind', 'direct')->value('id');
        $this->assertSame($direct, (int) DB::table('engineers')->find($eid)->ses_id);
        $this->assertDatabaseHas('users', ['email' => 'direct-eg@park.example', 'role' => 'eg', 'ses_id' => $direct, 'engineer_id' => $eid]);
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'direct-eg@park.example', 'kind' => 'invite']);
    }

    public function test_e4_blank_or_bad_affiliation_is_refused(): void
    {
        $this->as('admin@park.example');
        $n = DB::table('engineers')->count();
        // 所属を空にすると登録されない（直属にならない）
        $this->post('/engineers', ['ses_id' => '', 'name' => '所属なし 太郎', 'email' => 'blank@park.example'])->assertRedirect()->assertSessionHas('flash.type', 'bad');
        $this->post('/engineers', ['name' => '所属欄なし 太郎', 'email' => 'blank2@park.example'])->assertSessionHas('flash.type', 'bad');
        // 参加前の SES・存在しない SES・直属の行の id 直接指定は受けない
        $pending = DB::table('ses_companies')->where('status', 'pending')->value('id');
        $this->post('/engineers', ['ses_id' => $pending, 'name' => '保留 太郎'])->assertSessionHas('flash.type', 'bad');
        $this->post('/engineers', ['ses_id' => 99999, 'name' => '幻 太郎'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($n, DB::table('engineers')->count());
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count());
        // 所属に direct を選ぶと kind=direct の行に入る
        $this->post('/engineers', ['ses_id' => 'direct', 'name' => '直属 選択'])->assertRedirect();
        $e = DB::table('engineers')->where('name', '直属 選択')->first();
        $this->assertSame('direct', DB::table('ses_companies')->where('id', $e->ses_id)->value('kind'));
        // 名前が空なら、直属の行も作らない
        DB::table('engineers')->where('ses_id', $e->ses_id)->delete();
        DB::table('users')->where('ses_id', $e->ses_id)->delete();
        DB::table('ses_companies')->where('kind', 'direct')->delete();
        $this->post('/engineers', ['ses_id' => 'direct', 'name' => ''])->assertSessionHas('flash.type', 'bad');
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count());
    }

    public function test_e5_e6_application_flow_and_ses_isolation(): void
    {
        $eid = $this->directEngineer();
        $direct = W::directSesId();
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now()]);
        $caseId = DB::table('cases')->where('status', 'open')->orderBy('id')->value('id');
        $this->as('admin@park.example');
        $this->post("/cases/$caseId/apply", ['engineer_id' => $eid, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $app = DB::table('applications')->where('engineer_id', $eid)->first();
        $this->assertSame($direct, (int) $app->ses_id);
        $this->post("/applications/{$app->id}/status", ['status' => 'submitted'])->assertRedirect();
        $this->assertSame('submitted', DB::table('applications')->find($app->id)->status);
        $this->post("/applications/{$app->id}/interview", ['scheduled_at' => '2026-12-01T10:00', 'place' => 'オンライン'])->assertRedirect();
        $this->post("/applications/{$app->id}/contract", ['price' => 70, 'start_date' => date('Y-m-01')])->assertRedirect();
        $k = DB::table('contracts')->where('application_id', $app->id)->first();
        $this->assertSame($direct, (int) $k->ses_id);
        $this->assertSame('won', DB::table('applications')->find($app->id)->status);

        // どの SES の担当者も見られない
        $this->as('ses1@park.example');
        $this->get("/engineers/$eid")->assertForbidden();
        $this->get("/applications/{$app->id}")->assertForbidden();
        $this->get("/contracts/{$k->id}")->assertForbidden();
        $this->as('ses2@park.example');
        $this->get("/engineers/$eid")->assertForbidden();
        $this->get("/applications/{$app->id}")->assertForbidden();
    }

    public function test_e7_direct_is_not_ses(): void
    {
        $direct = W::directSesId();
        Artisan::call('park:status', ['--json' => true]);
        $s0 = json_decode(Artisan::output(), true);
        $this->as('admin@park.example');
        $list = $this->get('/admin/ses')->assertOk();
        $this->assertStringNotContainsString('PARK 運営（直属）', $list->getContent());
        foreach (["/admin/ses/$direct"] as $p) $this->get($p)->assertNotFound();
        $this->post("/admin/ses/$direct", ['name' => 'x'])->assertNotFound();
        $this->post("/admin/ses/$direct/approve")->assertNotFound();
        $this->post("/admin/ses/$direct/suspend")->assertNotFound();
        $this->post("/admin/ses/$direct/users", ['name' => 'x', 'email' => 'x@park.example'])->assertNotFound();
        $this->assertSame('active', DB::table('ses_companies')->find($direct)->status);
        $this->assertSame(0, DB::table('users')->where('email', 'x@park.example')->count());
        // ダッシュボードの数
        $d = $this->get('/admin');
        $expect = DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->count();
        $this->assertSame($expect, $d->viewData('counts')['ses']);
        // 分析・案件の持ち込み SES
        $this->assertStringNotContainsString('PARK 運営（直属）', $this->get('/admin/reports')->assertOk()->getContent());
        $this->assertStringNotContainsString('PARK 運営（直属）', $this->get('/cases/new')->assertOk()->getContent());
        // park:status
        $this->assertSame(0, $s0['SES：会社の情報の確認がまだ']);
        $this->assertSame(0, $s0['SES：承認待ち'] - 1); // 見本の保留 1 社だけ
        // role=ses の人が直属の行に属しても、ログインできない
        W::createUser('ghost-ses@park.example', '幽霊 担当', 'ses', 'park1234', ['ses_id' => $direct]);
        DB::table('users')->where('email', 'ghost-ses@park.example')->update(['profile_completed_at' => now()]);
        auth()->logout();
        $this->flushSession();
        $this->post('/login', ['email' => 'ghost-ses@park.example', 'password' => 'park1234'])->assertRedirect()->assertSessionHas('flash.type', 'bad');
        $this->assertGuest();
    }

    public function test_e7_status_command_counts_direct_engineers_only_as_direct(): void
    {
        $this->directEngineer();
        Artisan::call('park:status', ['--json' => true]);
        $d = json_decode(Artisan::output(), true);
        $this->assertSame(1, $d['エンジニア：管理者の直属']);
        $this->assertSame(0, $d['SES：会社の情報の確認がまだ']);
    }

    public function test_e8_no_invoice_for_direct(): void
    {
        $eid = $this->directEngineer();
        $direct = W::directSesId();
        $caseId = DB::table('cases')->where('status', 'open')->value('id');
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now()]);
        $appId = W::apply($caseId, $eid, ['start_ok' => 1], User::where('email', 'admin@park.example')->first());
        $month = date('Y-m');
        $kid = W::makeContract($appId, ['price' => 80, 'start_date' => date('Y-m-01')], User::where('email', 'admin@park.example')->first());
        $this->assertNull(Billing::build($direct, $month));
        $this->assertSame(0, DB::table('invoices')->where('ses_id', $direct)->count());
        $with = Billing::monthTotal($month);
        DB::table('contracts')->where('id', $kid)->delete();
        $this->assertSame(Billing::monthTotal($month), $with); // 直属の契約の有無で変わらない
        $this->as('admin@park.example')->post('/admin/invoices/build', ['period' => $month])->assertRedirect();
        $this->assertSame(0, DB::table('invoices')->where('ses_id', $direct)->count());
    }

    public function test_e9_e10_notifications_and_greeting(): void
    {
        $eid = $this->directEngineer();
        $direct = W::directSesId();
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now()]);
        $admin = User::where('email', 'admin@park.example')->first();
        $caseId = DB::table('cases')->where('status', 'open')->value('id');
        DB::table('users')->where('role', 'admin')->update(['mail_opt_in' => 1]);
        $adminIds = DB::table('users')->where('role', 'admin')->where('active', 1)->pluck('id');

        $kisha0 = DB::table('app_notifications')->where('title', 'like', '貴社のエンジニアが応募しました%')->count();
        $apps0 = DB::table('app_notifications')->whereIn('user_id', $adminIds)->where('title', 'like', '応募がありました%')->count();
        $appId = W::apply($caseId, $eid, ['start_ok' => 1], $admin);
        // 応募の知らせは、運営あてに1回分だけ（二重にしない）
        $this->assertSame($apps0 + $adminIds->count(), DB::table('app_notifications')->whereIn('user_id', $adminIds)->where('title', 'like', '応募がありました%')->count());
        $this->assertSame($kisha0, DB::table('app_notifications')->where('title', 'like', '貴社のエンジニアが応募しました%')->count());

        // 書類通過 → 運営に届く
        $before = DB::table('app_notifications')->whereIn('user_id', $adminIds)->count();
        W::setStatus($appId, 'doc_passed', $admin, '', '');
        $this->assertGreaterThan($before, DB::table('app_notifications')->whereIn('user_id', $adminIds)->count());

        // 成約の知らせに請求の一文が入らない（通知もメールも）。SES あての成約には入る
        $mailMax = (int) DB::table('mail_outbox')->max('id');
        W::makeContract($appId, ['price' => 70, 'start_date' => date('Y-m-01')], $admin);
        $won = DB::table('app_notifications')->whereIn('user_id', $adminIds)->where('title', 'like', '成約しました%')->get();
        $this->assertCount($adminIds->count(), $won);
        foreach ($won as $n) $this->assertStringNotContainsString('請求', $n->body);
        $mail = DB::table('mail_outbox')->where('id', '>', $mailMax)->where('subject', 'like', '%成約しました%')->get();
        $this->assertNotEmpty($mail);
        foreach ($mail as $m) $this->assertStringNotContainsString('請求', $m->body);

        // 宛名
        $invite = DB::table('mail_outbox')->where('to_email', 'direct-eg@park.example')->where('kind', 'invite')->first();
        $this->assertStringStartsWith('直属 一郎 様', $invite->body);
        $this->assertStringNotContainsString('PARK 運営（直属）', $invite->body);
    }

    public function test_e9_ses_contract_still_mentions_billing(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        W::makeContract(1, ['price' => 68, 'start_date' => date('Y-m-d')], $admin);
        $n = DB::table('app_notifications')->where('title', 'like', '成約しました%')->where('user_id', User::where('email', 'ses1@park.example')->value('id'))->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('請求', $n->body);
    }

    public function test_e9_withdraw_by_direct_notifies_admins_once(): void
    {
        $eid = $this->directEngineer();
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now()]);
        $admin = User::where('email', 'admin@park.example')->first();
        $caseId = DB::table('cases')->where('status', 'open')->value('id');
        $appId = W::apply($caseId, $eid, ['start_ok' => 1], $admin);
        $adminIds = DB::table('users')->where('role', 'admin')->where('active', 1)->pluck('id');
        $before = DB::table('app_notifications')->whereIn('user_id', $adminIds)->count();
        W::setStatus($appId, 'withdrawn', $admin, '', '');
        $after = DB::table('app_notifications')->whereIn('user_id', $adminIds)->count();
        $this->assertSame($adminIds->count(), $after - $before);
    }

    public function test_e11_admin_filter(): void
    {
        $this->directEngineer();
        $this->as('admin@park.example');
        $this->get('/admin/engineers?ses=direct')->assertOk()->assertSee('直属 一郎')->assertDontSee('山田 太郎');
        $this->get('/admin/engineers')->assertOk()->assertSee('直属 一郎')->assertSee('山田 太郎')->assertSee('直属</span>', false);
    }
}
