<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\TestCase;

// SES の詳細の「担当者」「エンジニア」の表の 停止・退会・詳細、担当者の詳細画面（PLAN 2026-10-20：AC1〜AC11）
class SesDetailButtonsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email, ?string $acting = null): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        $s = [TwoFactor::SESSION_KEY => $u->id];
        if ($acting) $s['acting_role'] = $acting;
        return $this->actingAs($u->fresh())->withSession($s);
    }

    private function admin(): static
    {
        return $this->as('admin@park.example', DB::table('users')->where('email', 'admin@park.example')->value('engineer_id') ? 'admin' : null);
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function egId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('engineer_id');
    }

    private function sesId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('ses_id');
    }

    private function addSesUser(int $sesId, string $email, string $name): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sesId]);
        DB::table('users')->where('id', $id)->update(['profile_completed_at' => now()]);
        return $id;
    }

    private function flash(string $key)
    {
        return session('flash')[$key] ?? null;
    }

    private function enc(string $s): string
    {
        return trim(Js::from($s)->toHtml(), '"\'');
    }

    // ---------- AC1 ----------

    public function test_ac1_card_title_is_tantosha(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $r = $this->admin()->get("/admin/ses/$sid")->assertOk();
        $r->assertSee('<h2>担当者</h2>', false)->assertSee('担当者を登録')->assertDontSee('担当者のアカウント');
    }

    public function test_ac1_card_title_markup_fallback(): void
    {
        // 見出しのタグの形に依らず、題の文字が出ること
        $sid = $this->sesId('ses1@park.example');
        $c = $this->admin()->get("/admin/ses/$sid")->getContent();
        $this->assertStringContainsString('担当者', $c);
        $this->assertStringNotContainsString('担当者のアカウント', $c);
    }

    // ---------- AC2 / AC10 ----------

    public function test_ac2_ses_user_rows_have_buttons(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $mine = $this->uid('ses1@park.example');
        $stopped = $this->addSesUser($sid, 'ses1b@park.example', '停止 次郎');
        DB::table('users')->where('id', $stopped)->update(['active' => 0]);
        $left = $this->addSesUser($sid, 'ses1c@park.example', '退会 三郎');
        DB::table('users')->where('id', $left)->update(['active' => 0, 'left_at' => now()]);

        $r = $this->admin()->get("/admin/ses/$sid")->assertOk();
        foreach ([$mine, $stopped, $left] as $u) $r->assertSee("href=\"" . url("/admin/ses/$sid/users/$u") . "\"", false);
        $r->assertSee("/admin/ses/$sid/users/$mine/suspend")->assertSee("/admin/ses/$sid/users/$mine/delete");
        $r->assertSee("/admin/ses/$sid/users/$stopped/resume")->assertSee("/admin/ses/$sid/users/$stopped/delete")->assertDontSee("/admin/ses/$sid/users/$stopped/suspend");
        foreach (['suspend', 'resume', 'delete'] as $a) $r->assertDontSee("/admin/ses/$sid/users/$left/$a");
        // 順：停止 → 退会 → 詳細
        $c = $r->getContent();
        $p1 = strpos($c, "/admin/ses/$sid/users/$mine/suspend");
        $p2 = strpos($c, "/admin/ses/$sid/users/$mine/delete");
        $p3 = strpos($c, 'href="' . url("/admin/ses/$sid/users/$mine") . '"');
        $this->assertTrue($p1 < $p2 && $p2 < $p3, 'ボタンの順が 停止・退会・詳細 でない');
    }

    public function test_ac10_admin_dual_not_in_table_and_detail_404(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $eid = $this->egId('eg1@park.example');
        $adm = $this->uid('admin@park.example');
        DB::table('users')->where('id', $adm)->update(['engineer_id' => $eid]);

        $this->admin()->get("/admin/ses/$sid")->assertOk()->assertDontSee("/admin/ses/$sid/users/$adm");
        $this->admin()->get("/admin/ses/$sid/users/$adm")->assertNotFound();
    }

    // ---------- AC3 / AC4 ----------

    public function test_ac3_engineer_rows_have_buttons(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $e1 = $this->egId('eg1@park.example');
        $r = $this->admin()->get("/admin/ses/$sid")->assertOk();
        $r->assertSee("/engineers/$e1/suspend")->assertSee("/engineers/$e1/delete")->assertDontSee("/engineers/$e1/resume");
        $r->assertSee('name="back" value="ses"', false);
        $r->assertSee('href="' . url("/engineers/$e1") . '"', false);
        $c = $r->getContent();
        $p1 = strpos($c, "/engineers/$e1/suspend");
        $p2 = strpos($c, "/engineers/$e1/delete");
        $p3 = strpos($c, 'href="' . url("/engineers/$e1") . '"', $p2);
        $this->assertTrue($p1 !== false && $p1 < $p2 && $p2 < $p3, 'エンジニアのボタンの順が 停止・退会・詳細 でない');
    }

    public function test_ac3_suspended_engineer_shows_resume_and_badge(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $e1 = $this->egId('eg1@park.example');
        DB::table('engineers')->where('id', $e1)->update(['suspended_at' => now()]);
        $r = $this->admin()->get("/admin/ses/$sid")->assertOk();
        $r->assertSee("/engineers/$e1/resume")->assertDontSee("/engineers/$e1/suspend")->assertSee("/engineers/$e1/delete")->assertSee('停止中');
    }

    public function test_ac3_left_engineer_has_detail_only(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $e1 = $this->egId('eg1@park.example');
        DB::table('engineers')->where('id', $e1)->update(['status' => 'left']);
        $r = $this->admin()->get("/admin/ses/$sid")->assertOk();
        foreach (['suspend', 'resume', 'delete'] as $a) $r->assertDontSee("/engineers/$e1/$a");
        $r->assertSee('href="' . url("/engineers/$e1") . '"', false);
    }

    public function test_ac3_confirm_text_matches_engineer_detail(): void
    {
        $sid = $this->sesId('ses1@park.example');
        // 稼働中の契約あり（eg3）
        $e3 = $this->egId('eg3@park.example');
        $s3 = $this->sesId('eg3@park.example');
        $this->assertTrue(DB::table('contracts')->where('engineer_id', $e3)->where('status', 'active')->exists());
        $this->admin()->get("/admin/ses/$s3")->assertSee($this->enc('稼働中の契約があります。契約・請求はそのまま続きます。'), false);
        $this->admin()->get("/engineers/$e3")->assertSee($this->enc('稼働中の契約があります。契約・請求はそのまま続きます。'), false);

        // 選考中の応募あり・契約なし（eg1）
        $e1 = $this->egId('eg1@park.example');
        $list = $this->admin()->get("/admin/ses/$sid")->assertOk();
        $list->assertSee($this->enc('選考中の応募 1 件はそのまま残ります。'), false);
        // 契約なしの人の確認の文に「稼働中」は入らない（eg1 の行のフォームの範囲）
        $c = $list->getContent();
        $pos = strpos($c, "/engineers/$e1/suspend");
        $seg = substr($c, $pos, strpos($c, '</form>', $pos) - $pos);
        $this->assertStringNotContainsString($this->enc('稼働中の契約があります'), $seg);
        $this->assertStringContainsString($this->enc('選考中の応募 1 件'), $seg);

        // 詳細と同じ全文
        $full = $this->enc(engineer_stop_confirm(false, 1));
        $this->admin()->get("/engineers/$e1")->assertSee($full, false);
        $list->assertSee($full, false);
    }

    public function test_ac4_engineer_suspend_resume_back_to_ses(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $e1 = $this->egId('eg1@park.example');

        $this->admin()->post("/engineers/$e1/suspend", ['back' => 'ses'])->assertRedirect("/admin/ses/$sid");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertNotNull(DB::table('engineers')->where('id', $e1)->value('suspended_at'));
        $this->assertSame(0, (int) DB::table('users')->where('engineer_id', $e1)->where('role', 'eg')->value('active'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'engineer-suspend')->count());

        $this->admin()->post("/engineers/$e1/resume", ['back' => 'ses'])->assertRedirect("/admin/ses/$sid");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertNull(DB::table('engineers')->where('id', $e1)->value('suspended_at'));
    }

    public function test_ac4_engineer_leave_back_to_ses(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $e1 = $this->egId('eg1@park.example');
        $this->assertFalse(DB::table('contracts')->where('engineer_id', $e1)->where('status', 'active')->exists());
        $this->admin()->post("/engineers/$e1/delete", ['back' => 'ses'])->assertRedirect("/admin/ses/$sid");
        $this->assertSame('left', DB::table('engineers')->where('id', $e1)->value('status'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'engineer-leave')->count());

        // 二重の退会：断られて SES の詳細に戻る
        $this->admin()->post("/engineers/$e1/delete", ['back' => 'ses'])->assertRedirect("/admin/ses/$sid");
        $this->assertSame('すでに退会済です。', $this->flash('msg'));
        // 退会済は停止などもできない（今の判定のまま）。戻り先だけ確認
        $this->admin()->post("/engineers/$e1/suspend", ['back' => 'ses'])->assertRedirect("/admin/ses/$sid");
        $this->assertSame('left', DB::table('engineers')->where('id', $e1)->value('status'));
        $this->assertNull(DB::table('engineers')->where('id', $e1)->value('suspended_at'));
    }

    public function test_ac4_engineer_leave_refused_with_active_contract(): void
    {
        $e3 = $this->egId('eg3@park.example');
        $s3 = $this->sesId('eg3@park.example');
        $this->admin()->post("/engineers/$e3/delete", ['back' => 'ses'])->assertRedirect("/admin/ses/$s3");
        $this->assertSame('bad', $this->flash('type'));
        $this->assertStringContainsString('稼働中の契約があります', $this->flash('msg'));
        $this->assertNotSame('left', DB::table('engineers')->where('id', $e3)->value('status'));
        $this->assertSame(1, DB::table('users')->where('engineer_id', $e3)->where('role', 'eg')->value('active'));
    }

    public function test_ac4_without_back_goes_to_engineer_detail(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $this->admin()->post("/engineers/$e1/suspend")->assertRedirect("/engineers/$e1");
        $this->admin()->post("/engineers/$e1/resume")->assertRedirect("/engineers/$e1");
        $this->admin()->post("/engineers/$e1/delete")->assertRedirect("/engineers/$e1");
        // 知らない back の値は無視
        $e2 = $this->egId('eg2@park.example');
        $this->admin()->post("/engineers/$e2/suspend", ['back' => 'http://evil.example/'])->assertRedirect("/engineers/$e2");
        $this->admin()->post("/engineers/$e2/resume", ['back' => 'detail'])->assertRedirect("/engineers/$e2");
    }

    public function test_ac4_ses_user_back_param_ignored_for_engineer(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $this->as('ses1@park.example')->post("/engineers/$e1/suspend", ['back' => 'ses'])->assertRedirect("/engineers/$e1");
        $this->as('ses1@park.example')->post("/engineers/$e1/resume", ['back' => 'ses'])->assertRedirect("/engineers/$e1");
        $this->as('ses1@park.example')->post("/engineers/$e1/delete", ['back' => 'ses'])->assertRedirect("/engineers/$e1");
    }

    // ---------- AC5 ----------

    public function test_ac5_user_actions_from_table_return_to_ses_detail(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $mine = $this->uid('ses1@park.example');
        $other = $this->addSesUser($sid, 'ses1b@park.example', '予備 次郎');

        $this->admin()->post("/admin/ses/$sid/users/$other/suspend")->assertRedirect("/admin/ses/$sid");
        $this->assertSame(0, (int) DB::table('users')->where('id', $other)->value('active'));
        $this->admin()->post("/admin/ses/$sid/users/$other/resume")->assertRedirect("/admin/ses/$sid");
        $this->assertSame(1, (int) DB::table('users')->where('id', $other)->value('active'));
    }

    public function test_ac5_last_usable_user_cannot_be_stopped(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $mine = $this->uid('ses1@park.example');
        $this->admin()->post("/admin/ses/$sid/users/$mine/suspend")->assertRedirect("/admin/ses/$sid");
        $this->assertSame('bad', $this->flash('type'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $mine)->value('active'));
        $this->admin()->post("/admin/ses/$sid/users/$mine/delete")->assertRedirect("/admin/ses/$sid");
        $this->assertSame('bad', $this->flash('type'));
        $this->assertNull(DB::table('users')->where('id', $mine)->value('left_at'));
    }

    // ---------- AC6 ----------

    public function test_ac6_only_admin_can_open_user_detail(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        $this->as('ses1@park.example')->get("/admin/ses/$sid/users/$uid")->assertForbidden();
        $this->as('eg1@park.example')->get("/admin/ses/$sid/users/$uid")->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get("/admin/ses/$sid/users/$uid")->assertRedirect();
        $this->admin()->get("/admin/ses/$sid/users/$uid")->assertOk();
    }

    public function test_ac6_dual_user_in_other_role_is_forbidden(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        DB::table('users')->where('id', $uid)->update(['engineer_id' => $this->egId('eg1@park.example')]);
        $this->as('ses1@park.example', 'ses')->get("/admin/ses/$sid/users/$uid")->assertForbidden();
        $this->as('ses1@park.example', 'eg')->get("/admin/ses/$sid/users/$uid")->assertForbidden();
    }

    // ---------- AC7 ----------

    public function test_ac7_detail_fields(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        $name = DB::table('users')->where('id', $uid)->value('name');
        $comp = DB::table('ses_companies')->where('id', $sid)->value('name');
        DB::table('users')->where('id', $uid)->update(['kana' => null, 'phone' => null, 'position' => null, 'engineer_id' => null, 'last_login' => null]);

        $r = $this->admin()->get("/admin/ses/$sid/users/$uid")->assertOk();
        foreach (['状態', 'SES', '氏名', 'ふりがな', 'メールアドレス', '電話番号', '所属・役職', 'エンジニア兼任', '最終ログイン', '登録日', 'SES の詳細に戻る', $name, $comp, 'ses1@park.example'] as $t) $r->assertSee($t);
        $r->assertSee('—')->assertSee('なし')->assertDontSee('編集');
        $r->assertSee("/admin/ses/$sid/users/$uid/suspend")->assertSee("/admin/ses/$sid/users/$uid/delete");
        $r->assertSee('name="back" value="detail"', false);
        $r->assertSee('href="' . url("/admin/ses/$sid") . '"', false);
    }

    public function test_ac7_detail_shows_values_and_dual_link(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        $eid = $this->egId('eg1@park.example');
        DB::table('users')->where('id', $uid)->update(['kana' => 'テスト ふりがな', 'phone' => '090-1111-2222', 'position' => '営業部長', 'engineer_id' => $eid]);
        $r = $this->admin()->get("/admin/ses/$sid/users/$uid")->assertOk();
        $r->assertSee('テスト ふりがな')->assertSee('090-1111-2222')->assertSee('営業部長')->assertSee('あり');
        $r->assertSee('href="' . url("/engineers/$eid") . '"', false);
    }

    // ---------- AC8 ----------

    public function test_ac8_not_found_cases(): void
    {
        $s1 = $this->sesId('ses1@park.example');
        $s2 = $this->sesId('ses2@park.example');
        $u1 = $this->uid('ses1@park.example');
        $u2 = $this->uid('ses2@park.example');
        $adm = $this->uid('admin@park.example');
        $eg = $this->uid('eg1@park.example');
        $this->assertNotSame($s1, $s2);

        $this->admin()->get("/admin/ses/$s1/users/$u2")->assertNotFound();   // ほかの会社の担当者
        $this->admin()->get("/admin/ses/$s2/users/$u1")->assertNotFound();
        $this->admin()->get("/admin/ses/$s1/users/$adm")->assertNotFound();  // 管理者
        $this->admin()->get("/admin/ses/$s1/users/$eg")->assertNotFound();   // エンジニアの行
        $this->admin()->get("/admin/ses/$s1/users/999999")->assertNotFound();
        $this->admin()->get("/admin/ses/999999/users/$u1")->assertNotFound();

        $direct = W::directSesId();
        $this->admin()->get("/admin/ses/$direct/users/$u1")->assertNotFound();
    }

    public function test_ac8_left_user_can_be_opened_without_buttons(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $left = $this->addSesUser($sid, 'ses1c@park.example', '退会 三郎');
        DB::table('users')->where('id', $left)->update(['active' => 0, 'left_at' => now()]);
        $r = $this->admin()->get("/admin/ses/$sid/users/$left")->assertOk()->assertSee('退会済');
        foreach (['suspend', 'resume', 'delete'] as $a) $r->assertDontSee("/admin/ses/$sid/users/$left/$a");
    }

    public function test_ac8_new_route_still_works(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $this->admin()->get("/admin/ses/$sid/users/new")->assertOk();
    }

    // ---------- AC9 ----------

    public function test_ac9_detail_buttons_return_to_detail(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $other = $this->addSesUser($sid, 'ses1b@park.example', '予備 次郎');
        $d = "/admin/ses/$sid/users/$other";

        $this->admin()->post("$d/suspend", ['back' => 'detail'])->assertRedirect($d);
        $this->assertSame(0, (int) DB::table('users')->where('id', $other)->value('active'));
        $this->admin()->post("$d/resume", ['back' => 'detail'])->assertRedirect($d);
        $this->assertSame(1, (int) DB::table('users')->where('id', $other)->value('active'));
        // 兼任でない人の退会は詳細に戻る
        $this->admin()->post("$d/delete", ['back' => 'detail'])->assertRedirect($d);
        $this->assertNotNull(DB::table('users')->where('id', $other)->value('left_at'));
        $this->admin()->get($d)->assertOk()->assertSee('退会済');
    }

    public function test_ac9_last_user_refusal_stays_on_detail(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $mine = $this->uid('ses1@park.example');
        $d = "/admin/ses/$sid/users/$mine";
        $this->admin()->post("$d/suspend", ['back' => 'detail'])->assertRedirect($d);
        $this->assertSame('bad', $this->flash('type'));
        $this->assertSame(1, (int) DB::table('users')->where('id', $mine)->value('active'));
    }

    public function test_ac9_dual_user_leave_goes_to_ses_detail(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $other = $this->addSesUser($sid, 'ses1b@park.example', '兼任 次郎');
        $eid = $this->egId('eg1@park.example');
        DB::table('users')->where('id', $other)->update(['engineer_id' => $eid]);

        $this->admin()->post("/admin/ses/$sid/users/$other/delete", ['back' => 'detail'])->assertRedirect("/admin/ses/$sid");
        $row = DB::table('users')->where('id', $other)->first();
        $this->assertSame('eg', $row->role);
        $this->assertSame('ok', $this->flash('type'));
    }

    public function test_ac9_ses_user_back_param_ignored(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $other = $this->addSesUser($sid, 'ses1b@park.example', '予備 次郎');
        $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$other/suspend", ['back' => 'detail'])->assertRedirect('/ses/company');
        $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$other/resume", ['back' => 'detail'])->assertRedirect('/ses/company');
    }

    public function test_ses_user_cannot_touch_other_company_user(): void
    {
        $s2 = $this->sesId('ses2@park.example');
        $u2 = $this->uid('ses2@park.example');
        $this->as('ses1@park.example')->post("/admin/ses/$s2/users/$u2/suspend", ['back' => 'detail'])->assertNotFound();
        $this->assertSame(1, (int) DB::table('users')->where('id', $u2)->value('active'));
    }

    public function test_engineer_actions_other_company_forbidden_for_ses(): void
    {
        $theirs = $this->egId('eg2@park.example');
        $this->as('ses1@park.example')->post("/engineers/$theirs/suspend", ['back' => 'ses'])->assertForbidden();
        $this->as('eg1@park.example')->post("/engineers/$theirs/suspend", ['back' => 'ses'])->assertForbidden();
    }

    // ---------- AC11 ----------

    public function test_ac11_manuals(): void
    {
        $root = base_path('resources/manuals');
        $admin = file_get_contents("$root/admin.md");
        $steps = file_get_contents("$root/admin-steps.md");
        $this->assertStringContainsString('担当者の詳細', $admin);
        $this->assertStringContainsString('エンジニア・SES を止める・退会にする', $steps);
        foreach (['担当者のアカウント', '担当者を追加して招待'] as $old) {
            $this->assertStringNotContainsString($old, $admin);
            $this->assertStringNotContainsString($old, $steps);
        }
    }
}
