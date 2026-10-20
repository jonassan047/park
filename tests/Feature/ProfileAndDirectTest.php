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

    // 管理者の直属のエンジニアを1人作って、その engineers.id を返す（管理者として操作する）。
    // 直属は画面からは新しく登録できない（PLAN V1）ので、今いる直属の人と同じ形で直接作る
    private function directEngineer(string $email = 'direct-eg@park.example', string $name = '直属 一郎'): int
    {
        $this->as('admin@park.example');
        return W::createEngineer(W::directSesId(), ['name' => $name, 'email' => $email], User::where('email', 'admin@park.example')->first());
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
        $this->as('ses1@park.example')->get('/welcome')->assertOk()->assertSee('役職')->assertSee('value="しす"', false)->assertSee('value="いち"', false)->assertSee('営業部');
        [$sei, $mei] = split_name(DB::table('users')->where('email', 'eg1@park.example')->value('name'));
        $this->as('eg1@park.example')->get('/welcome')->assertOk()->assertSee('value="'.$sei.'"', false)->assertSee('value="'.$mei.'"', false)->assertDontSee('役職');
        // users.name が空なら engineers.name
        DB::table('users')->where('email', 'eg1@park.example')->update(['name' => '']);
        $this->as('eg1@park.example')->get('/welcome')->assertSee('value="山田"', false)->assertSee('value="太郎"', false);
    }

    public function test_a6_empty_name_is_rejected(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->from('/welcome')->post('/welcome', ['name_sei' => '', 'name_mei' => '', 'kana_sei' => 'x'])->assertSessionHasErrors(['name_sei', 'name_mei']);
        // 名だけ空でもエラー（姓・名とも必須）
        $this->post('/welcome', ['name_sei' => '姓だけ', 'name_mei' => ''])->assertSessionHasErrors('name_mei');
        $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
        $this->post('/welcome', ['name_sei' => 'あ', 'name_mei' => '太郎', 'phone' => 'abc'])->assertSessionHasErrors('phone');
        $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
    }

    public function test_a7_a8_save_and_destination(): void
    {
        $this->unfinish('admin@park.example', 'ses1@park.example', 'eg1@park.example');
        $in = ['name_sei' => '登録', 'name_mei' => '太郎', 'kana_sei' => 'とうろく', 'kana_mei' => 'たろう', 'phone' => '03-0000-0000', 'position' => '部長'];
        $this->as('admin@park.example')->post('/welcome', $in + ['company_name' => '見本運営株式会社'])->assertRedirect(url('/admin'));
        $this->assertDatabaseHas('users', ['email' => 'admin@park.example', 'name' => '登録 太郎', 'kana' => 'とうろく たろう', 'phone' => '03-0000-0000', 'position' => '部長']);
        $this->assertNotNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));

        // SES：会社の確認がまだなら /ses/company
        $sesId = User::where('email', 'ses1@park.example')->value('ses_id');
        DB::table('ses_companies')->where('id', $sesId)->update(['self_confirmed_at' => null]);
        $sesIn = $in + ['ses_name' => (string) DB::table('ses_companies')->where('id', $sesId)->value('name')];
        $this->as('ses1@park.example')->post('/welcome', $sesIn)->assertRedirect(url('/ses/company'));
        $this->assertDatabaseHas('users', ['email' => 'ses1@park.example', 'position' => '部長']);
        $this->unfinish('ses1@park.example');
        DB::table('ses_companies')->where('id', $sesId)->update(['self_confirmed_at' => now()]);
        $this->as('ses1@park.example')->post('/welcome', $sesIn)->assertRedirect(url('/ses'));

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
        $this->as('eg1@park.example')->post('/welcome', ['name_sei' => '別の名前', 'name_mei' => '太郎', 'kana' => 'べつ'])->assertRedirect();
        $this->assertSame($before, (array) DB::table('engineers')->find($eid));
        $this->assertNull(DB::table('engineers')->where('id', $eid)->value('self_confirmed_at'));
    }

    public function test_a10_done_users_and_double_post(): void
    {
        $this->as('admin@park.example')->get('/admin')->assertOk();
        $this->get('/welcome')->assertRedirect(url('/account'));
        $this->unfinish('admin@park.example');
        $this->post('/welcome', ['name_sei' => '一回目', 'name_mei' => '太郎', 'company_name' => '見本運営株式会社'])->assertRedirect();
        $first = DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at');
        $this->assertNotNull($first);
        $this->travel(2)->hours();
        $this->post('/welcome', ['name_sei' => '二回目', 'name_mei' => '太郎', 'company_name' => '見本運営株式会社'])->assertRedirect();
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
        // ただし、企業名（管理者）・SES名（SES）が空なら弾かれる
        $this->as('admin@park.example')->from('/account')->post('/account', ['name' => '管理 者'])->assertSessionHasErrors('company_name');
        $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example')->from('/account')->post('/account', ['name' => '担当 者'])->assertSessionHasErrors('ses_name');
        $this->assertNull(DB::table('users')->where('email', 'ses1@park.example')->value('profile_completed_at'));
        $this->as('admin@park.example')->post('/account', ['name' => '管理 者', 'company_name' => '見本運営株式会社'])->assertRedirect();
        $this->assertNotNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
    }

    public function test_a12_setup_goes_to_welcome(): void
    {
        DB::table('users')->delete();
        $this->post('/setup', ['name' => '最初 の管理者', 'email' => 'first@park.example', 'password' => 'abcd1234'])->assertRedirect(url('/welcome'));
        $this->get('/welcome')->assertOk()->assertSee('最初 の管理者');
        $this->get('/admin')->assertRedirect(url('/welcome'));
    }

    // ---------- B. 立場の帯 ----------

    public function test_b_login_bar(): void
    {
        $this->as('admin@park.example')->get('/admin')->assertSee('管理者としてログインしています：'.User::where('email', 'admin@park.example')->value('name'));
        $this->as('ses1@park.example')->get('/ses')->assertSee('SES（みどり情報システム）のご担当者としてログインしています');
        $this->as('eg1@park.example')->get('/eg')->assertSee('エンジニアとしてログインしています');
        // 印刷用
        $this->as('admin@park.example')->get('/engineers/1/sheet')->assertOk()->assertDontSee('としてログインしています');
        $this->assertSame('管理者', lbl('role', 'admin'));
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
        $this->post('/admin/admins', ['name' => '新任 管理', 'company_name' => 'テスト企業', 'email' => 'new-admin@park.example'])->assertRedirect();
        $nu = DB::table('users')->where('email', 'new-admin@park.example')->first();
        $this->assertSame('admin', $nu->role);
        $this->assertNull($nu->password);
        $this->get('/admin/admins/new')->assertOk()->assertSee('登録して招待メールを送る');
        $this->get('/admin/admins')->assertOk()->assertDontSee('登録すると')->assertSee('招待中')->assertSee('使用中')->assertSee('招待を送り直す');
        // 送り直しは毎回 token が新しくなる
        $mails = DB::table('mail_outbox')->where('kind', 'invite')->count();
        $t0 = $nu->token;
        $this->post("/admin/admins/{$nu->id}/reinvite")->assertRedirect(url('/admin/admins'));
        $t1 = DB::table('users')->where('id', $nu->id)->value('token');
        $this->assertNotSame($t0, $t1);
        $this->assertSame($mails + 1, DB::table('mail_outbox')->where('kind', 'invite')->count());
        $this->post("/admin/admins/{$nu->id}/reinvite");
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
            $this->post("/admin/admins/$id/reinvite")->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame($me->token, DB::table('users')->where('id', $me->id)->value('token'));
        $this->assertSame($ses->token, DB::table('users')->where('id', $ses->id)->value('token'));
    }

    public function test_c_reinvite_refused_for_stopped_admin(): void
    {
        $this->as('admin@park.example');
        $this->post('/admin/admins', ['name' => '止める 人', 'company_name' => 'テスト企業', 'email' => 'stop-admin@park.example'])->assertRedirect();
        $id = DB::table('users')->where('email', 'stop-admin@park.example')->value('id');
        DB::table('users')->where('id', $id)->update(['active' => 0]);
        $token = DB::table('users')->where('id', $id)->value('token');
        $mails = DB::table('mail_outbox')->count();
        $this->post("/admin/admins/$id/reinvite")->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'bad');
        $this->assertSame($token, DB::table('users')->where('id', $id)->value('token'));
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->get('/admin/admins')->assertSee('停止');
    }

    public function test_c5_others_cannot_use_settings(): void
    {
        $this->as('ses1@park.example');
        $this->get('/admin/settings')->assertForbidden();
        $this->post('/admin/admins/1/reinvite')->assertForbidden();
        $this->as('eg1@park.example')->post('/admin/admins/1/reinvite')->assertForbidden();
    }

    // ---------- D. SES の登録 ----------

    public function test_d_admin_registers_ses(): void
    {
        $this->as('admin@park.example');
        $this->get('/admin/ses/new')->assertOk()->assertSee('登録すると参加中になり、担当の方に招待メール（7日間有効のリンク）が届きます。');
        $notes = DB::table('app_notifications')->count();
        $this->post('/admin/ses', ['name' => '管理者登録 SES', 'contact_name' => '担当 花子', 'email' => 'reg-ses@park.example'])->assertRedirect();
        $s = DB::table('ses_companies')->where('name', '管理者登録 SES')->first();
        $this->assertSame('active', $s->status);
        $this->assertSame(date('Y-m-d'), substr($s->joined_at, 0, 10));
        $this->assertDatabaseHas('users', ['email' => 'reg-ses@park.example', 'role' => 'ses', 'ses_id' => $s->id]);
        $this->assertDatabaseHas('mail_outbox', ['to_email' => 'reg-ses@park.example', 'kind' => 'invite']);
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', 'ses-apply')->count());
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'like', '%参加の申し込み%')->count());
        $this->assertSame($notes, DB::table('app_notifications')->count());
    }

    public function test_d_no_email_is_refused(): void
    {
        $this->as('admin@park.example');
        $users = DB::table('users')->count();
        $this->post('/admin/ses', ['name' => 'メール無し SES', 'contact_name' => '誰か'])->assertRedirect()->assertSessionHas('flash.type', 'bad');
        $this->assertDatabaseMissing('ses_companies', ['name' => 'メール無し SES']);
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_d_registration_refused_when_email_in_use(): void
    {
        $this->as('admin@park.example');
        // 招待中（パスワード未設定）の別の立場の人と同じメール
        $this->post('/admin/admins', ['name' => '招待中 管理', 'company_name' => 'テスト企業', 'email' => 'pending-admin@park.example'])->assertRedirect();
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
        // V1-1：直属を新しく登録する入口はない（SES を選んでから登録する）
        $this->get('/engineers/new?ses=direct')->assertRedirect(url('/admin/ses'))->assertSessionHas('flash.type', 'warn');
        $this->get('/admin/engineers?ses=direct')->assertOk(); // 0件でも開ける
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count()); // 開いただけでは作らない
        $this->get('/admin/engineers')->assertOk()->assertDontSee('直属だけ'); // 直属の人がいないときはボタンが出ない
        W::directSesId();
        $this->get('/admin/engineers')->assertOk()->assertDontSee('直属だけ'); // 入れ物だけでは出ない（直属のエンジニアがいるときだけ）
        $this->assertStringNotContainsString('<select name="ses_id"', $this->get('/engineers/new?ses=1')->getContent()); // 所属は選ばせない（読むだけ）
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
        // V1-2：所属に direct を送っても受けない（直属の行も、エンジニアも、ユーザーも、メールも増えない）
        $u = DB::table('users')->count(); $m = DB::table('mail_outbox')->count();
        $this->post('/engineers', ['ses_id' => 'direct', 'name' => '直属 選択', 'email' => 'direct-pick@park.example'])->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', '所属と氏名を入れてください。');
        $this->assertSame($n, DB::table('engineers')->count());
        $this->assertSame($u, DB::table('users')->count());
        $this->assertSame($m, DB::table('mail_outbox')->count());
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
        // 一覧に「直属」の行は出ない（V1-3）。SES の詳細（/admin/ses/{id}）へのリンクも無い
        $this->assertStringNotContainsString('管理者の直属', $list->getContent());
        $this->assertStringNotContainsString(url("/admin/ses/$direct").'"', $list->getContent());
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
        $this->assertStringNotContainsString('管理者の直属', $this->get('/admin/reports')->assertOk()->getContent());
        $this->assertStringNotContainsString('管理者の直属', $this->get('/cases/new')->assertOk()->getContent());
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
        // 応募の知らせは、管理者あてに1回分だけ（二重にしない）
        $this->assertSame($apps0 + $adminIds->count(), DB::table('app_notifications')->whereIn('user_id', $adminIds)->where('title', 'like', '応募がありました%')->count());
        $this->assertSame($kisha0, DB::table('app_notifications')->where('title', 'like', '貴社のエンジニアが応募しました%')->count());

        // 書類通過 → 管理者に届く
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
        $this->assertStringNotContainsString('管理者の直属', $invite->body);
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

    // ---------- H〜J. 企業名（管理者）・SES名（SES）（PLAN 2026-10-17） ----------

    private function sesName(int $id = 1): string
    {
        return (string) DB::table('ses_companies')->where('id', $id)->value('name');
    }

    private function renameNotes(): int
    {
        return DB::table('app_notifications')->where('title', 'SES の名前が変わりました')->count();
    }

    private function renameLogs(): int
    {
        return DB::table('audit_log')->where('action', 'ses-rename')->count();
    }

    private function adminCount(): int
    {
        return DB::table('users')->where('role', 'admin')->where('active', 1)->count();
    }

    public function test_h1_company_name_column_null_for_all(): void
    {
        $this->assertTrue(Schema::hasColumn('users', 'company_name'));
        $this->assertSame(0, DB::table('users')->whereNotNull('company_name')->count());
    }

    public function test_h2_welcome_shows_company_field(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->get('/welcome')->assertOk()->assertSee('企業名')->assertSee('メールの差出人の名前にも使います')->assertDontSee('SES名');
        DB::table('users')->where('email', 'admin@park.example')->update(['company_name' => '初期値会社']);
        $this->as('admin@park.example')->get('/welcome')->assertSee('value="初期値会社"', false);
    }

    public function test_h3_blank_company_is_refused(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example');
        foreach (['', '   ', '　　', " \t　"] as $blank) {
            $this->from('/welcome')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'company_name' => $blank])->assertSessionHasErrors('company_name');
            $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
            $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
        }
        $this->from('/welcome')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎'])->assertSessionHasErrors('company_name');
    }

    public function test_h4_company_length_boundary(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example');
        $this->from('/welcome')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'company_name' => str_repeat('あ', 101)])->assertSessionHasErrors('company_name');
        $this->assertNull(DB::table('users')->where('email', 'admin@park.example')->value('profile_completed_at'));
        $this->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'company_name' => str_repeat('あ', 100)])->assertRedirect(url('/admin'));
        $this->assertSame(str_repeat('あ', 100), DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
    }

    public function test_h5_company_trimmed_and_saved(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'company_name' => '  見本運営株式会社　'])->assertRedirect(url('/admin'));
        $this->assertSame('見本運営株式会社', DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
    }

    public function test_h6_account_company_optional_and_limited(): void
    {
        $this->as('admin@park.example')->get('/account')->assertOk()->assertSee('企業名')->assertSee('メールの差出人の名前にも使います');
        $this->post('/account', ['name' => '管理者 太郎', 'company_name' => '新社名'])->assertRedirect();
        $this->assertSame('新社名', DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
        $this->post('/account', ['name' => '管理者 太郎'])->assertRedirect();
        $this->assertSame('新社名', DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
        $this->post('/account', ['name' => '管理者 太郎', 'company_name' => '　'])->assertRedirect();
        $this->assertSame('新社名', DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
        $this->from('/account')->post('/account', ['name' => '管理者 太郎', 'company_name' => str_repeat('あ', 101)])->assertSessionHasErrors('company_name');
        $this->assertSame('新社名', DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
    }

    public function test_h7_login_bar_with_company(): void
    {
        $this->as('admin@park.example')->get('/admin')->assertOk()->assertSee('管理者としてログインしています：')->assertDontSee('管理者（');
        DB::table('users')->where('email', 'admin@park.example')->update(['company_name' => '見本運営株式会社']);
        $this->as('admin@park.example')->get('/admin')->assertSee('管理者（見本運営株式会社）としてログインしています：', false);
    }

    public function test_h8_settings_lists_company(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['company_name' => '見本運営株式会社']);
        $this->as('admin@park.example')->get('/admin/admins')->assertOk()->assertSee('企業名')->assertSee('<td>見本運営株式会社</td>', false);
    }

    public function test_i1_welcome_shows_ses_name(): void
    {
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example')->get('/welcome')->assertOk()->assertSee('SES名')
            ->assertSee('value="みどり情報システム"', false)->assertSee('管理者が入れた名前です。正式名称に直してください。')->assertDontSee('企業名');
    }

    public function test_i2_blank_ses_name_is_refused(): void
    {
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example');
        $before = DB::table('ses_companies')->where('id', 1)->first();
        foreach (['', '   ', '　　'] as $blank) {
            $this->from('/welcome')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => $blank])->assertSessionHasErrors('ses_name');
        }
        $this->from('/welcome')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎'])->assertSessionHasErrors('ses_name');
        $after = DB::table('ses_companies')->where('id', 1)->first();
        $this->assertSame('みどり情報システム', $after->name);
        $this->assertSame($before->updated_at, $after->updated_at);
        $this->assertNull(DB::table('users')->where('email', 'ses1@park.example')->value('profile_completed_at'));
        $this->assertSame(0, $this->renameLogs());
    }

    public function test_i3_ses_name_length_boundary(): void
    {
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example');
        $this->from('/welcome')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => str_repeat('あ', 101)])->assertSessionHasErrors('ses_name');
        $this->assertSame('みどり情報システム', $this->sesName());
        $this->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => str_repeat('あ', 100)])->assertRedirect();
        $this->assertSame(str_repeat('あ', 100), $this->sesName());
    }

    public function test_i4_i5_rename_records_and_notifies(): void
    {
        $this->unfinish('ses1@park.example');
        $confirmed = DB::table('ses_companies')->where('id', 1)->value('self_confirmed_at');
        DB::table('ses_companies')->where('id', 1)->update(['updated_at' => '2020-01-01 00:00:00']);
        $mails = DB::table('mail_outbox')->count();
        $this->as('ses1@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => '  株式会社みどり情報システム　'])->assertRedirect(url('/ses'));
        $row = DB::table('ses_companies')->where('id', 1)->first();
        $this->assertSame('株式会社みどり情報システム', $row->name);
        $this->assertSame($confirmed, $row->self_confirmed_at);
        $this->assertNotSame('2020-01-01 00:00:00', $row->updated_at);
        $this->assertSame(1, $this->renameLogs());
        $this->assertDatabaseHas('audit_log', ['action' => 'ses-rename', 'detail' => 'みどり情報システム → 株式会社みどり情報システム']);
        $this->assertSame($this->adminCount(), $this->renameNotes());
        $n = DB::table('app_notifications')->where('title', 'SES の名前が変わりました')->first();
        $this->assertSame('みどり情報システム が名前を 株式会社みどり情報システム に変えました。', $n->body);
        $this->assertSame('/admin/ses/1', $n->link);
        $this->assertSame($mails, DB::table('mail_outbox')->count(), '名前の変更ではメールを出さない');
    }

    public function test_i6_same_name_changes_nothing(): void
    {
        $this->unfinish('ses1@park.example');
        DB::table('ses_companies')->where('id', 1)->update(['updated_at' => '2020-01-01 00:00:00']);
        $this->as('ses1@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => ' みどり情報システム '])->assertRedirect(url('/ses'));
        $this->assertSame('2020-01-01 00:00:00', DB::table('ses_companies')->where('id', 1)->value('updated_at'));
        $this->assertSame(0, $this->renameLogs());
        $this->assertSame(0, $this->renameNotes());
    }

    public function test_i7_new_name_shows_everywhere(): void
    {
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => '株式会社みどり情報システム']);
        $this->as('ses1@park.example')->get('/ses')->assertSee('SES（株式会社みどり情報システム）');
        $this->get('/ses/company')->assertSee('株式会社みどり情報システム');
        $this->as('admin@park.example')->get('/admin/ses')->assertOk()->assertSee('株式会社みどり情報システム');
        $this->get('/admin/ses/1')->assertOk()->assertSee('株式会社みどり情報システム');
        $this->get('/admin/engineers')->assertOk()->assertSee('株式会社みどり情報システム');
        $this->assertStringContainsString('株式会社みどり情報システム', user_label(User::where('email', 'ses1@park.example')->first()));
    }

    public function test_i8_duplicate_name_warns(): void
    {
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => 'あおぞらテック'])->assertRedirect();
        $this->assertSame('あおぞらテック', $this->sesName());
        $flash = session('flash');
        $this->assertSame('warn', $flash['type']);
        $this->assertStringContainsString('同じ名前の SES がほかにもあります', $flash['msg']);
        $n = DB::table('app_notifications')->where('title', 'SES の名前が変わりました')->first();
        $this->assertStringContainsString('（同じ名前の SES がほかにもあります）', $n->body);
    }

    public function test_i9_second_contact_sees_updated_name(): void
    {
        W::createUser('ses1b@park.example', '緑川 二郎', 'ses', 'park1234', ['ses_id' => 1]);
        $this->unfinish('ses1@park.example', 'ses1b@park.example');
        $this->as('ses1@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => '新名称株式会社'])->assertRedirect();
        $first = DB::table('users')->where('email', 'ses1@park.example')->value('profile_completed_at');
        $this->assertNotNull($first);
        $this->as('ses1b@park.example')->get('/welcome')->assertOk()->assertSee('value="新名称株式会社"', false);
        $notes = $this->renameNotes();
        $this->post('/welcome', ['name_sei' => '二郎', 'name_mei' => '太郎', 'ses_name' => '新名称株式会社'])->assertRedirect();
        $this->assertSame($notes, $this->renameNotes());
        $this->assertSame(1, $this->renameLogs());
        $this->assertSame($first, DB::table('users')->where('email', 'ses1@park.example')->value('profile_completed_at'));
        // 後から直せば後の名前になる
        $this->unfinish('ses1b@park.example');
        $this->post('/welcome', ['name_sei' => '二郎', 'name_mei' => '太郎', 'ses_name' => '後の名前'])->assertRedirect();
        $this->assertSame('後の名前', $this->sesName());
        $this->assertSame(2, $this->renameLogs());
    }

    public function test_i10_account_ses_name(): void
    {
        $this->as('ses1@park.example')->get('/account')->assertOk()->assertSee('SES名')->assertSee('value="みどり情報システム"', false);
        $this->post('/account', ['name' => '緑川 一郎', 'ses_name' => '新しい名前'])->assertRedirect();
        $this->assertSame('新しい名前', $this->sesName());
        $this->assertSame(1, $this->renameLogs());
        $this->assertSame($this->adminCount(), $this->renameNotes());
        $this->post('/account', ['name' => '緑川 一郎'])->assertRedirect();
        $this->post('/account', ['name' => '緑川 一郎', 'ses_name' => '　'])->assertRedirect();
        $this->assertSame('新しい名前', $this->sesName());
        $this->from('/account')->post('/account', ['name' => '緑川 一郎', 'ses_name' => str_repeat('あ', 101)])->assertSessionHasErrors('ses_name');
        $this->assertSame('新しい名前', $this->sesName());
        $this->post('/account', ['name' => '緑川 一郎', 'ses_name' => 'あおぞらテック'])->assertRedirect(url('/account'));
        $this->assertSame('warn', session('flash')['type']);
    }

    public function test_i11_company_screen_rename(): void
    {
        $this->as('ses1@park.example')->get('/ses/company')->assertOk()->assertSee('SES名（会社名）');
        $this->post('/ses/company', ['name' => '会社画面で変更'])->assertRedirect(url('/ses/company'));
        $this->assertSame('会社画面で変更', $this->sesName());
        $this->assertSame(1, $this->renameLogs());
        $this->assertSame($this->adminCount(), $this->renameNotes());
        $this->from('/ses/company')->post('/ses/company', ['name' => str_repeat('あ', 101)])->assertSessionHasErrors('name');
        $this->post('/ses/company', ['name' => ''])->assertRedirect();
        $this->assertSame('会社画面で変更', $this->sesName());
    }

    public function test_i12_ses_name_hint_for_admin(): void
    {
        $this->as('admin@park.example')->get('/admin/ses/new')->assertOk()->assertSee('略称でもかまいません');
        $this->get('/admin/ses/1')->assertOk()->assertSee('略称でもかまいません');
        $this->post('/admin/ses/1', ['name' => '管理者が直した名前']);
        $this->assertSame(0, $this->renameNotes());
        $this->assertNull(DB::table('ses_companies')->where('id', 1)->value('self_confirmed_at'));
    }

    public function test_j1_engineer_has_no_company_fields(): void
    {
        $this->unfinish('eg1@park.example');
        $eg = User::where('email', 'eg1@park.example')->first();
        $sesBefore = $this->sesName((int) $eg->ses_id);
        $this->as('eg1@park.example')->get('/welcome')->assertOk()->assertDontSee('企業名')->assertDontSee('SES名');
        $this->post('/welcome', ['name_sei' => '山田', 'name_mei' => '太郎', 'company_name' => '勝手な会社', 'ses_name' => '勝手なSES'])->assertRedirect();
        $this->assertNotNull(DB::table('users')->where('id', $eg->id)->value('profile_completed_at'));
        $this->assertNull(DB::table('users')->where('id', $eg->id)->value('company_name'));
        $this->assertSame($sesBefore, $this->sesName((int) $eg->ses_id));
        $this->get('/account')->assertOk()->assertDontSee('企業名')->assertDontSee('SES名');
        $this->post('/account', ['name' => '山田 太郎', 'company_name' => '勝手な会社', 'ses_name' => '勝手なSES'])->assertRedirect();
        $this->assertNull(DB::table('users')->where('id', $eg->id)->value('company_name'));
        $this->assertSame($sesBefore, $this->sesName((int) $eg->ses_id));
        $this->assertSame(0, $this->renameLogs());
    }

    public function test_j2_other_roles_fields_ignored(): void
    {
        $this->unfinish('admin@park.example', 'ses1@park.example');
        $this->as('admin@park.example')->get('/welcome')->assertDontSee('SES名');
        $this->post('/welcome', ['name_sei' => '管理', 'name_mei' => '太郎', 'company_name' => '運営社', 'ses_name' => '混ぜた名前'])->assertRedirect(url('/admin'));
        $this->assertSame('みどり情報システム', $this->sesName());
        $this->assertSame('運営社', DB::table('users')->where('email', 'admin@park.example')->value('company_name'));
        $this->as('ses1@park.example')->get('/welcome')->assertDontSee('企業名');
        $this->post('/welcome', ['name_sei' => '担当', 'name_mei' => '太郎', 'ses_name' => 'みどり情報システム', 'company_name' => '混ぜた会社'])->assertRedirect();
        $this->assertNull(DB::table('users')->where('email', 'ses1@park.example')->value('company_name'));
    }

    public function test_j3_done_user_redirected_from_welcome(): void
    {
        $this->as('admin@park.example')->get('/welcome')->assertRedirect(url('/account'));
        $this->as('ses1@park.example')->get('/welcome')->assertRedirect(url('/account'));
    }

    public function test_j4_direct_row_is_not_renamed(): void
    {
        $direct = W::directSesId();
        $before = $this->sesName($direct);
        $admin = User::where('email', 'admin@park.example')->first();
        $this->assertNull(W::renameSes($direct, 'x', $admin));
        $this->assertSame($before, $this->sesName($direct));
        $this->assertSame(0, $this->renameLogs());
        $this->assertSame(0, $this->renameNotes());
    }

    public function test_hi_control_chars_and_isolation(): void
    {
        $this->unfinish('ses1@park.example');
        $this->as('ses1@park.example')->post('/welcome', ['name_sei' => '氏名', 'name_mei' => '太郎', 'ses_name' => "\u{0007}　制御入り\u{0000}　"])->assertRedirect();
        $this->assertSame('制御入り', $this->sesName(1));
        $this->assertSame('あおぞらテック', $this->sesName(2));
        $this->assertSame('ひだまりソリューションズ', $this->sesName(3));
        $this->as("ses1@park.example")->get('/admin/settings')->assertForbidden();
    }
}
