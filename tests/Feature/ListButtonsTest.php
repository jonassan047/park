<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\TestCase;

// 管理者・SES・エンジニアの一覧の 停止・退会 ボタン（PLAN 2026-10-21：AC1〜AC10）
class ListButtonsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function admin(): static { return $this->as('admin@park.example'); }

    private function uid(string $email): int { return (int) DB::table('users')->where('email', $email)->value('id'); }
    private function egId(string $email): int { return (int) DB::table('users')->where('email', $email)->value('engineer_id'); }
    private function sesId(string $email): int { return (int) DB::table('users')->where('email', $email)->value('ses_id'); }
    private function user(int $id): object { return DB::table('users')->find($id); }
    private function audits(string $a): int { return DB::table('audit_log')->where('action', $a)->count(); }

    // 使用中の管理者を足す
    private function addAdmin(string $email, string $name = '管理者 二郎', array $extra = []): int
    {
        return W::createUser($email, $name, 'admin', 'park1234', $extra);
    }

    // パスワード未設定（招待中）の管理者
    private function addInvitedAdmin(string $email): int
    {
        $id = $this->addAdmin($email, '招待 三郎');
        DB::table('users')->where('id', $id)->update(['password' => null]);
        return $id;
    }

    // エンジニア兼任の管理者（eg1 のエンジニアに結びつける）
    private function addDualAdmin(string $email = 'dual@park.example'): array
    {
        $eid = $this->egId('eg1@park.example');
        $id = $this->addAdmin($email, '兼任 四郎', ['engineer_id' => $eid]);
        DB::table('engineers')->where('id', $eid)->update(['is_operator' => 1]);
        return [$id, $eid];
    }

    // ---------- AC1：管理者の一覧 ----------

    public function test_admins_list_buttons(): void
    {
        $a = $this->uid('admin@park.example');
        $b = $this->addAdmin('b@park.example');
        $c = $this->addInvitedAdmin('c@park.example');

        $r = $this->admin()->get('/admin/admins')->assertOk();
        $r->assertSee("/admin/admins/$b/suspend", false)->assertSee("/admin/admins/$b/delete", false)->assertSee("/admin/admins/$b", false);
        $r->assertDontSee("/admin/admins/$a/suspend", false)->assertDontSee("/admin/admins/$a/delete", false);
        $r->assertSee("/admin/admins/$c/reinvite", false);

        DB::table('users')->where('id', $b)->update(['active' => 0]);
        $r = $this->admin()->get('/admin/admins')->assertOk();
        $r->assertSee("/admin/admins/$b/resume", false)->assertDontSee("/admin/admins/$b/suspend", false);

        DB::table('users')->where('id', $b)->update(['left_at' => now()]);
        $r = $this->admin()->get('/admin/admins')->assertOk();
        $r->assertSee('退会済');
        $r->assertDontSee("/admin/admins/$b/resume", false)->assertDontSee("/admin/admins/$b/delete", false)->assertDontSee("/admin/admins/$b/suspend", false);
        $r->assertSee("/admin/admins/$b", false);
    }

    // ---------- AC2：管理者の停止・再開・退会 ----------

    public function test_admin_suspend_resume_leave(): void
    {
        $a = $this->uid('admin@park.example');
        $b = $this->addAdmin('b@park.example');
        DB::table('users')->where('id', $b)->update(['token' => 'tok', 'token_expires' => now()->addDay()]);

        $this->admin()->post("/admin/admins/$b/suspend", ['back' => 'list'])->assertRedirect('/admin/admins');
        $u = $this->user($b);
        $this->assertSame(0, (int) $u->active);
        $this->assertNull($u->token);
        $this->assertSame(1, $this->audits('admin-suspend'));

    }

    public function test_stopped_admin_cannot_enter(): void
    {
        $b = $this->addAdmin('b@park.example');
        $this->admin()->post("/admin/admins/$b/suspend")->assertRedirect();
        $res = $this->as('b@park.example')->get('/admin');
        $this->assertNotSame(200, $res->getStatusCode());
    }

    public function test_admin_resume_and_leave_and_self_and_double(): void
    {
        $a = $this->uid('admin@park.example');
        $b = $this->addAdmin('b@park.example');

        $this->admin()->post("/admin/admins/$b/suspend", ['back' => 'list']);
        $this->admin()->post("/admin/admins/$b/suspend", ['back' => 'list'])->assertRedirect('/admin/admins')->assertSessionHas('flash.msg', 'すでに停止中です。');

        $this->admin()->post("/admin/admins/$b/resume", ['back' => 'list'])->assertRedirect('/admin/admins');
        $this->assertSame(1, (int) $this->user($b)->active);
        $this->assertSame(1, $this->audits('admin-resume'));

        $this->admin()->post("/admin/admins/$b/delete", ['back' => 'list'])->assertRedirect('/admin/admins');
        $this->assertNotNull($this->user($b)->left_at);
        $this->assertSame(0, (int) $this->user($b)->active);
        $this->assertSame(1, $this->audits('admin-leave'));
        $this->admin()->get("/admin/admins/$b")->assertOk()->assertSee('退会済');

        // 退会済は操作できない・直せない
        $this->admin()->post("/admin/admins/$b/resume")->assertRedirect();
        $this->assertNotNull($this->user($b)->left_at);
        $this->assertSame(0, (int) $this->user($b)->active);
        $this->admin()->post("/admin/admins/$b", ['name_sei' => '変更', 'name_mei' => '後', 'company_name' => 'X'])->assertRedirect("/admin/admins/$b");
        $this->assertStringNotContainsString('変更', (string) $this->user($b)->name);

        // 自分自身
        $this->admin()->post("/admin/admins/$a/suspend")->assertRedirect();
        $this->admin()->post("/admin/admins/$a/delete")->assertRedirect();
        $this->assertSame(1, (int) $this->user($a)->active);
        $this->assertNull($this->user($a)->left_at);
    }

    public function test_cannot_stop_last_usable_admin(): void
    {
        $a = $this->uid('admin@park.example');
        $inv = $this->addInvitedAdmin('inv@park.example'); // パスワード未設定＝使える管理者に数えない
        // 実行者を A' とする（使える管理者が A のほか 0 人の状態で A を止める）
        $res = W::suspendAdmin($a, $this->user($inv));
        $this->assertSame('bad', $res['type']);
        $this->assertSame(1, (int) $this->user($a)->active);
        $res = W::leaveAdmin($a, $this->user($inv));
        $this->assertSame('bad', $res['type']);
        $this->assertNull($this->user($a)->left_at);
    }

    public function test_admin_op_404_and_403(): void
    {
        $b = $this->addAdmin('b@park.example');
        $this->admin()->post('/admin/admins/999999/suspend')->assertNotFound();
        // 管理者でない id
        $this->admin()->post('/admin/admins/'.$this->uid('ses1@park.example').'/suspend')->assertNotFound();
        // SES 担当者・エンジニアは 403
        $this->as('ses1@park.example')->post("/admin/admins/$b/suspend")->assertForbidden();
        $this->as('eg1@park.example')->post("/admin/admins/$b/delete")->assertForbidden();
        $this->assertSame(1, (int) $this->user($b)->active);
    }

    // ---------- AC3：エンジニア兼任の管理者 ----------

    public function test_dual_admin_leave_becomes_engineer(): void
    {
        [$d, $eid] = $this->addDualAdmin();
        $ses = (int) DB::table('engineers')->where('id', $eid)->value('ses_id');

        // back なしでも詳細ではなく一覧へ
        $this->admin()->post("/admin/admins/$d/delete")->assertRedirect('/admin/admins');
        $u = $this->user($d);
        $this->assertSame('eg', $u->role);
        $this->assertSame($ses, (int) $u->ses_id);
        $this->assertNull($u->left_at);
        $this->assertSame(1, (int) $u->active);
        $this->assertSame(0, (int) DB::table('engineers')->where('id', $eid)->value('is_operator'));
        $this->assertSame(1, $this->audits('admin-leave'));
    }

    public function test_dual_admin_with_stopped_engineer_stays_inactive(): void
    {
        [$d, $eid] = $this->addDualAdmin();
        DB::table('engineers')->where('id', $eid)->update(['suspended_at' => now()]);
        $this->admin()->post("/admin/admins/$d/delete", ['back' => 'list'])->assertRedirect('/admin/admins');
        $u = $this->user($d);
        $this->assertSame('eg', $u->role);
        $this->assertSame(0, (int) $u->active);
    }

    // 停止中の兼任管理者（エンジニアは停止していない）の退会 → active=1 でエンジニアとして入れる
    public function test_suspended_dual_admin_leave_reactivates_as_engineer(): void
    {
        [$d, $eid] = $this->addDualAdmin();
        DB::table('users')->where('id', $d)->update(['active' => 0]);
        $this->admin()->post("/admin/admins/$d/delete")->assertRedirect('/admin/admins');
        $u = $this->user($d);
        $this->assertSame('eg', $u->role);
        $this->assertSame(1, (int) $u->active);
        $this->assertNull($u->left_at);
    }

    // エンジニア非停止の兼任管理者（使用中）の退会後も active=1 のまま
    public function test_active_dual_admin_leave_keeps_active(): void
    {
        [$d] = $this->addDualAdmin();
        $this->admin()->post("/admin/admins/$d/delete");
        $this->assertSame(1, (int) $this->user($d)->active);
        $this->assertSame('eg', $this->user($d)->role);
    }

    // 停止中の兼任でない管理者の退会 → 退会済のまま（エンジニアとして入れる道はない）
    public function test_suspended_plain_admin_leave_is_left(): void
    {
        $b = $this->addAdmin('b@park.example');
        DB::table('users')->where('id', $b)->update(['active' => 0]);
        $this->admin()->post("/admin/admins/$b/delete")->assertRedirect();
        $u = $this->user($b);
        $this->assertSame('admin', $u->role);
        $this->assertNotNull($u->left_at);
        $this->assertSame(0, (int) $u->active);
    }

    // エンジニアの行が無い兼任の退会は断られる
    public function test_dual_admin_without_engineer_row_is_refused(): void
    {
        [$d, $eid] = $this->addDualAdmin();
        DB::table('engineers')->where('id', $eid)->delete();
        $this->admin()->post("/admin/admins/$d/delete", ['back' => 'list']);
        $u = $this->user($d);
        $this->assertSame('admin', $u->role);
        $this->assertNull($u->left_at);
        $this->assertSame(1, (int) $u->active);
        $this->assertSame(0, $this->audits('admin-leave'));
        $res = W::leaveAdmin($d, $this->user($this->uid('admin@park.example')));
        $this->assertSame('bad', $res['type']);
    }

    public function test_dual_suspend_message(): void
    {
        [$d] = $this->addDualAdmin();
        $res = W::suspendAdmin($d, $this->user($this->uid('admin@park.example')));
        $this->assertSame('ok', $res['type']);
        $this->assertStringContainsString('エンジニアとしても入れなくなります', $res['msg']);
        $this->assertSame(0, (int) $this->user($d)->active);
    }

    // ---------- AC4：SES の一覧 ----------

    public function test_ses_list_buttons(): void
    {
        $s1 = $this->sesId('ses1@park.example');
        $s2 = $this->sesId('ses2@park.example');
        $s3 = $this->sesId('ses3@park.example');
        $pend = (int) DB::table('ses_companies')->where('status', 'pending')->value('id');
        DB::table('ses_companies')->where('id', $s2)->update(['status' => 'suspended']);
        DB::table('ses_companies')->where('id', $s3)->update(['status' => 'left']);

        $r = $this->admin()->get('/admin/ses')->assertOk();
        $r->assertSee("/admin/ses/$s1/suspend", false)->assertSee("/admin/ses/$s1/delete", false)->assertDontSee("/admin/ses/$s1/resume", false);
        $r->assertSee("/admin/ses/$s2/resume", false)->assertDontSee("/admin/ses/$s2/suspend", false);
        $r->assertSee("/admin/ses/$pend/delete", false)->assertDontSee("/admin/ses/$pend/suspend", false)->assertDontSee("/admin/ses/$pend/resume", false);
        $r->assertDontSee("/admin/ses/$s3/suspend", false)->assertDontSee("/admin/ses/$s3/resume", false)->assertDontSee("/admin/ses/$s3/delete", false);
        foreach ([$s1, $s2, $s3, $pend] as $id) $r->assertSee("/admin/ses/$id\"", false);
    }

    // ---------- AC5：SES の再開 ----------

    public function test_ses_resume(): void
    {
        $s2 = $this->sesId('ses2@park.example');
        $s1 = $this->sesId('ses1@park.example');
        $pend = (int) DB::table('ses_companies')->where('status', 'pending')->value('id');
        DB::table('ses_companies')->where('id', $s2)->update(['status' => 'suspended']);
        $mails = DB::table('mail_outbox')->count();

        $this->admin()->post("/admin/ses/$s2/resume", ['back' => 'list'])->assertRedirect('/admin/ses');
        $this->assertSame('active', DB::table('ses_companies')->where('id', $s2)->value('status'));
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame(1, $this->audits('ses-resume'));

        // 参加中に再開 → 停止していません
        $this->admin()->post("/admin/ses/$s1/resume", ['back' => 'list'])->assertRedirect('/admin/ses')->assertSessionHas('flash.msg', '停止していません。');
        $this->assertSame(1, $this->audits('ses-resume'));

        // 申込中に停止 → 断り
        $this->admin()->post("/admin/ses/$pend/suspend")->assertRedirect("/admin/ses/$pend");
        $this->assertSame('pending', DB::table('ses_companies')->where('id', $pend)->value('status'));

        // 詳細の右上
        DB::table('ses_companies')->where('id', $s2)->update(['status' => 'suspended']);
        $r = $this->admin()->get("/admin/ses/$s2")->assertOk();
        $r->assertSee("/admin/ses/$s2/resume", false)->assertDontSee('承認して招待を送る');
        $this->admin()->get("/admin/ses/$pend")->assertOk()->assertSee('承認して招待を送る');
    }

    public function test_ses_suspend_guards_and_roles(): void
    {
        $s1 = $this->sesId('ses1@park.example');
        $s3 = $this->sesId('ses3@park.example');
        DB::table('ses_companies')->where('id', $s3)->update(['status' => 'left']);
        $this->admin()->post("/admin/ses/$s3/suspend");
        $this->assertSame('left', DB::table('ses_companies')->where('id', $s3)->value('status'));

        $this->as('ses1@park.example')->post("/admin/ses/$s1/resume")->assertForbidden();
        $this->as('eg1@park.example')->post("/admin/ses/$s1/resume")->assertForbidden();
        $this->admin()->post('/admin/ses/999999/resume')->assertNotFound();
    }

    // ---------- AC6：エンジニアの一覧 ----------

    public function test_engineer_lists_buttons(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $e2 = $this->egId('eg2@park.example');
        $e3 = $this->egId('eg3@park.example'); // 稼働中の契約あり
        DB::table('engineers')->where('id', $e2)->update(['suspended_at' => now()]);
        $left = DB::table('engineers')->insertGetId(['ses_id' => $this->sesId('ses1@park.example'), 'name' => '退会 太郎', 'status' => 'left', 'created_at' => now_str(), 'updated_at' => now_str()]);

        $r = $this->admin()->get('/admin/engineers')->assertOk();
        $r->assertSee("/engineers/$e1/suspend", false)->assertSee("/engineers/$e1/delete", false)->assertSee("/engineers/$e1\"", false);
        $r->assertSee("/engineers/$e2/resume", false)->assertDontSee("/engineers/$e2/suspend", false);
        $r->assertDontSee("/engineers/$left/suspend", false)->assertDontSee("/engineers/$left/delete", false)->assertDontSee("/engineers/$left/resume", false);
        $r->assertSee("/engineers/$left\"", false);
        $r->assertSee(trim((string) Js::from('稼働中の契約があります。'), "'\""), false);

        // SES（eg3 の会社）
        $r = $this->as('ses3@park.example')->get('/ses/engineers')->assertOk();
        $r->assertSee("/engineers/$e3/suspend", false)->assertSee("/engineers/$e3/delete", false)->assertSee(trim((string) Js::from('稼働中の契約があります。'), "'\""), false);
        $r->assertDontSee("/engineers/$e1/suspend", false);
    }

    public function test_empty_engineer_list_zero_rows(): void
    {
        $this->admin()->get('/admin/engineers?q=zzzzzzzzzz')->assertOk()->assertDontSee('/suspend', false);
    }

    // ---------- AC7：戻り先 ----------

    public function test_back_to_list_keeps_filters(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $bq = 'q=Java&status=available&ses=direct&page=2&x=1';
        $this->admin()->post("/engineers/$e1/suspend", ['back' => 'list', 'back_query' => $bq])->assertRedirect('/admin/engineers?q=Java&status=available&ses=direct&page=2');
        $this->admin()->post("/engineers/$e1/resume", ['back' => 'list', 'back_query' => '//evil.example'])->assertRedirect('/admin/engineers');
        $this->admin()->post("/engineers/$e1/suspend", ['back' => 'list', 'back_query' => 'q=https://evil.example/&ses=' . str_repeat('a', 201)])->assertRedirect('/admin/engineers?q=https%3A%2F%2Fevil.example%2F');
    }

    public function test_back_query_array_does_not_500(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $b = $this->addAdmin('b@park.example');
        $s1 = $this->sesId('ses1@park.example');
        $arr = ['back' => 'list', 'back_query' => ['q' => 'x', 'status' => ['a']]];

        $this->admin()->post("/engineers/$e1/suspend", $arr)->assertRedirect('/admin/engineers');
        $this->admin()->post("/engineers/$e1/resume", $arr)->assertRedirect('/admin/engineers');
        $this->admin()->post("/admin/admins/$b/suspend", $arr)->assertRedirect('/admin/admins');
        $this->admin()->post("/admin/admins/$b/resume", $arr)->assertRedirect('/admin/admins');
        $this->admin()->post("/admin/ses/$s1/suspend", $arr)->assertRedirect('/admin/ses');
        $this->admin()->post("/admin/ses/$s1/resume", $arr)->assertRedirect('/admin/ses');
        $this->as('ses1@park.example')->post("/engineers/$e1/suspend", $arr)->assertRedirect('/ses/engineers');
        // 鍵の値が配列でも落ちない
        $this->admin()->post("/engineers/$e1/resume", ['back' => 'list', 'back_query' => 'q[]=1&status[]=2'])->assertRedirect('/admin/engineers');
    }

    public function test_ses_back_and_working_contract_refusal(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $this->as('ses1@park.example')->post("/engineers/$e1/suspend", ['back' => 'list', 'back_query' => 'q=Java&x=1'])->assertRedirect('/ses/engineers?q=Java');

        $e3 = $this->egId('eg3@park.example');
        $before = DB::table('engineers')->where('id', $e3)->value('status');
        $this->admin()->post("/engineers/$e3/delete", ['back' => 'list'])->assertRedirect('/admin/engineers');
        $this->assertSame($before, DB::table('engineers')->where('id', $e3)->value('status'));
        $this->assertNotSame('left', $before);

        // SES の一覧から sesDelete（稼働中の契約あり）
        $s3 = $this->sesId('ses3@park.example');
        $this->admin()->post("/admin/ses/$s3/delete", ['back' => 'list', 'back_query' => 'q=1'])->assertRedirect('/admin/ses?q=1');
        $this->assertSame('active', DB::table('ses_companies')->where('id', $s3)->value('status'));
    }

    // ---------- AC8：詳細から押したときの戻り先は今のまま ----------

    public function test_detail_back_unchanged(): void
    {
        $e1 = $this->egId('eg1@park.example');
        $s1 = $this->sesId('ses1@park.example');
        $b = $this->addAdmin('b@park.example');
        $this->admin()->post("/engineers/$e1/suspend")->assertRedirect("/engineers/$e1");
        $this->admin()->post("/engineers/$e1/resume", ['back' => 'ses'])->assertRedirect("/admin/ses/$s1");
        $this->admin()->post("/admin/ses/$s1/suspend")->assertRedirect("/admin/ses/$s1");
        $this->admin()->post("/admin/admins/$b/suspend")->assertRedirect("/admin/admins/$b");
    }

    // ---------- AC9：他社のエンジニアは操作できない ----------

    public function test_ses_cannot_touch_other_company_engineer(): void
    {
        $e2 = $this->egId('eg2@park.example'); // ses2 のエンジニア
        $this->as('ses1@park.example')->post("/engineers/$e2/suspend", ['back' => 'list'])->assertForbidden();
        $this->assertNull(DB::table('engineers')->where('id', $e2)->value('suspended_at'));
        $this->as('ses1@park.example')->post("/engineers/$e2/delete")->assertForbidden();
    }

    // ---------- AC10：マニュアル ----------

    public function test_manuals(): void
    {
        $this->admin()->get('/manual/admin')->assertOk()->assertSee('管理者の停止・再開・退会');
        $this->as('ses1@park.example')->get('/manual/ses')->assertOk()->assertSee('一覧');
    }

    // ---------- 補助：list_back ----------

    public function test_list_back_helper(): void
    {
        $this->assertSame('/p', list_back('/p', null));
        $this->assertSame('/p', list_back('/p', ''));
        $this->assertSame('/p?q=a', list_back('/p', 'q=a&evil=1'));
        $this->assertSame('/p', list_back('/p', 'q[]=a'));
    }
}
