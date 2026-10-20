<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseMail;
use App\Services\Matcher;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Js;
use Tests\TestCase;

// 担当者ごと・エンジニアごとの「停止」「退会」（PLAN 2026-10-20：T1〜T15）
class SuspendLeaveTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function admin(): static
    {
        return $this->as('admin@park.example');
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    // 見本のエンジニア（メールアドレスで探す）
    private function egId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('engineer_id');
    }

    private function sesId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('ses_id');
    }

    // 同じ会社に担当者をもう1人足す（使用中）
    private function addSesUser(int $sesId, string $email, string $name): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sesId]);
        DB::table('users')->where('id', $id)->update(['profile_completed_at' => now()]);
        return $id;
    }

    private function rowCount(string $table): int
    {
        return DB::table($table)->count();
    }

    private function flash(string $key)
    {
        return session('flash')[$key] ?? null;
    }

    // @js で出る確認の文の一部（日本語は \u の形になるので、同じ形にして探す）
    private function enc(string $s): string
    {
        return trim(Js::from($s)->toHtml(), '"\'');
    }

    // ---------- 担当者（B） ----------

    public function test_t1_ses_users_table_has_actions(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $stopped = $this->addSesUser($sid, 'ses1b@park.example', '停止 次郎');
        DB::table('users')->where('id', $stopped)->update(['active' => 0]);
        $left = $this->addSesUser($sid, 'ses1c@park.example', '退会 三郎');
        DB::table('users')->where('id', $left)->update(['active' => 0, 'left_at' => now()]);
        $mine = $this->uid('ses1@park.example');

        $r = $this->admin()->get("/admin/ses/$sid")->assertOk();
        $r->assertSee('担当者を登録')->assertSee('>停止</button>', false)->assertSee('>退会</button>', false);
        $r->assertSee("/admin/ses/$sid/users/$mine/suspend")->assertSee("/admin/ses/$sid/users/$mine/delete");
        // 停止の人：再開と退会
        $r->assertSee("/admin/ses/$sid/users/$stopped/resume")->assertSee("/admin/ses/$sid/users/$stopped/delete")->assertDontSee("/admin/ses/$sid/users/$stopped/suspend");
        // 退会済の人：ボタンなし
        foreach (['suspend', 'resume', 'delete'] as $a) $r->assertDontSee("/admin/ses/$sid/users/$left/$a");
        $r->assertSee('退会済');
    }

    public function test_t2_suspend_blocks_login_and_invite_link(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        $this->addSesUser($sid, 'ses1x@park.example', '予備 担当'); // 使える担当者が1人だけだと止められない
        // 招待中の別の担当者（止める前に招待リンクを出しておく）
        $inv = W::invite(['email' => 'ses1d@park.example', 'name' => '招待 四郎', 'role' => 'ses', 'ses_id' => $sid], User::where('email', 'admin@park.example')->first());
        $token = DB::table('users')->where('id', $inv->id)->value('token');
        $this->assertNotEmpty($token);

        $this->admin()->post("/admin/ses/$sid/users/$uid/suspend")->assertRedirect("/admin/ses/$sid");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertStringContainsString('停止しました', $this->flash('msg'));
        $this->assertDatabaseHas('users', ['id' => $uid, 'active' => 0]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'ses-user-suspend')->count());
        $this->assertStringNotContainsString('@', (string) DB::table('audit_log')->where('action', 'ses-user-suspend')->value('detail'));

        // ログイン中だった人は、次の操作でログイン画面へ
        $this->as('ses1@park.example')->get('/ses')->assertRedirect();
        // 新しくログインもできない
        $this->post('/login', ['email' => 'ses1@park.example', 'password' => 'park1234'])->assertSessionHas('flash.type', 'bad');

        // 招待中の人を止めると、止める前のリンクは使えない
        $this->admin()->post("/admin/ses/$sid/users/{$inv->id}/suspend");
        $this->assertNull(DB::table('users')->where('id', $inv->id)->value('token'));
        $this->post("/invite/$token", ['password' => 'abcd12345', 'password_confirmation' => 'abcd12345'])->assertRedirect('/login');
        $this->assertSame('リンクの期限が切れています。', $this->flash('msg'));
        $this->assertNull(DB::table('users')->where('id', $inv->id)->value('password'));
    }

    public function test_t3_resume(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        $this->addSesUser($sid, 'ses1x@park.example', '予備 担当');
        $this->admin()->post("/admin/ses/$sid/users/$uid/suspend");
        $this->admin()->post("/admin/ses/$sid/users/$uid/resume")->assertRedirect("/admin/ses/$sid");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertStringContainsString('再開しました', $this->flash('msg'));
        $this->assertDatabaseHas('users', ['id' => $uid, 'active' => 1]);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'ses-user-resume')->count());

        $this->admin()->post("/admin/ses/$sid/users/$uid/resume");
        $this->assertSame('すでに使えます。', $this->flash('msg'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'ses-user-resume')->count());

        // パスワード未設定の人は、招待を送り直す方法を添える
        $inv = W::invite(['email' => 'ses1e@park.example', 'name' => '招待 五郎', 'role' => 'ses', 'ses_id' => $sid], User::where('email', 'admin@park.example')->first());
        $this->admin()->post("/admin/ses/$sid/users/{$inv->id}/suspend");
        $this->admin()->post("/admin/ses/$sid/users/{$inv->id}/resume");
        $this->assertStringContainsString('招待を送り直すには', $this->flash('msg'));
    }

    public function test_t4_leave_and_no_more_actions(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->addSesUser($sid, 'ses1f@park.example', '退会 六郎');
        $this->admin()->post("/admin/ses/$sid/users/$uid/delete")->assertRedirect("/admin/ses/$sid");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertStringContainsString('退会済にしました', $this->flash('msg'));
        $row = DB::table('users')->find($uid);
        $this->assertSame(0, (int) $row->active);
        $this->assertNotNull($row->left_at);
        $this->assertSame('退会済', user_state($row));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'ses-user-leave')->count());

        foreach (['suspend', 'resume', 'delete'] as $a) {
            $this->admin()->post("/admin/ses/$sid/users/$uid/$a");
            $this->assertSame('bad', $this->flash('type'));
            $this->assertSame('退会済の人は操作できません。', $this->flash('msg'));
        }
        $after = DB::table('users')->find($uid);
        $this->assertSame($row->left_at, $after->left_at);
        $this->assertSame(0, (int) $after->active);
    }

    public function test_t5_wrong_target_and_roles(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $other = $this->uid('ses2@park.example'); // 別の会社の担当者
        foreach (['suspend', 'resume', 'delete'] as $a) {
            $this->admin()->post("/admin/ses/$sid/users/$other/$a")->assertNotFound();
            $this->admin()->post("/admin/ses/$sid/users/".$this->uid('admin@park.example')."/$a")->assertNotFound();
            $this->admin()->post("/admin/ses/$sid/users/".$this->uid('eg1@park.example')."/$a")->assertNotFound();
            $this->admin()->post("/admin/ses/$sid/users/999999/$a")->assertNotFound();
        }
        $this->assertDatabaseHas('users', ['id' => $other, 'active' => 1]);
        $uid = $this->uid('ses1@park.example');
        // エンジニア（eg）は 403
        foreach (['suspend', 'resume', 'delete'] as $a) $this->as('eg1@park.example')->post("/admin/ses/$sid/users/$uid/$a")->assertForbidden();
        // SES は他社の会社・他社の担当者・自社の管理者などは 404
        $s2 = $this->sesId('ses2@park.example');
        foreach (['suspend', 'resume', 'delete'] as $a) {
            $this->as('ses1@park.example')->post("/admin/ses/$s2/users/$other/$a")->assertNotFound();
            $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$other/$a")->assertNotFound();
            $this->as('ses1@park.example')->post("/admin/ses/$sid/users/".$this->uid('admin@park.example')."/$a")->assertNotFound();
        }
        $this->assertDatabaseHas('users', ['id' => $uid, 'active' => 1]);
        $this->assertDatabaseHas('users', ['id' => $other, 'active' => 1]);
    }

    // 1b：SES は自社の担当者を止め・再開・退会にできる（ご自身は不可）
    public function test_t16_ses_manages_own_users(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $me = $this->uid('ses1@park.example');
        $mate = $this->addSesUser($sid, 'ses1m@park.example', '同僚 一郎');

        // 画面：同僚の行にだけ操作が出る
        $r = $this->as('ses1@park.example')->get('/ses/company')->assertOk();
        $r->assertSee("/admin/ses/$sid/users/$mate/suspend")->assertSee("/admin/ses/$sid/users/$mate/delete");
        foreach (['suspend', 'resume', 'delete'] as $a) $r->assertDontSee("/admin/ses/$sid/users/$me/$a");

        $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$mate/suspend")->assertRedirect('/ses/company');
        $this->assertSame('ok', $this->flash('type'));
        $this->assertDatabaseHas('users', ['id' => $mate, 'active' => 0]);
        $this->as('ses1@park.example')->get('/ses/company')->assertSee("/admin/ses/$sid/users/$mate/resume");
        $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$mate/resume")->assertRedirect('/ses/company');
        $this->assertDatabaseHas('users', ['id' => $mate, 'active' => 1]);
        $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$mate/delete")->assertRedirect('/ses/company');
        $this->assertNotNull(DB::table('users')->where('id', $mate)->value('left_at'));

        // ご自身は不可
        $other = $this->addSesUser($sid, 'ses1n@park.example', '同僚 二郎');
        foreach (['suspend', 'resume', 'delete'] as $a) {
            $this->as('ses1@park.example')->post("/admin/ses/$sid/users/$me/$a");
            $this->assertSame('bad', $this->flash('type'));
        }
        $this->assertDatabaseHas('users', ['id' => $me, 'active' => 1, 'left_at' => null]);
    }

    // 3b：使える担当者が1人だけのとき、その人の停止・退会は断る
    public function test_t6_last_usable_user_is_refused(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $uid = $this->uid('ses1@park.example');
        foreach (['suspend', 'delete'] as $a) {
            $this->admin()->post("/admin/ses/$sid/users/$uid/$a")->assertRedirect("/admin/ses/$sid");
            $this->assertSame('bad', $this->flash('type'));
            $this->assertStringContainsString('この人だけです', $this->flash('msg'));
        }
        $this->assertDatabaseHas('users', ['id' => $uid, 'active' => 1, 'left_at' => null]);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'like', 'ses-user-%')->count());
        // 確認の文に「最後の1人」の注意は出ない
        $this->admin()->get("/admin/ses/$sid")->assertDontSee($this->enc('使える担当者がいなくなります'), false)->assertDontSee('使える担当者がいません。');

        // 2人目がいれば止められる。2人目が止まっていると、1人目は断られる
        $mate = $this->addSesUser($sid, 'ses1g@park.example', '二人目 七郎');
        $this->admin()->post("/admin/ses/$sid/users/$mate/suspend");
        $this->assertSame('ok', $this->flash('type'));
        $this->admin()->post("/admin/ses/$sid/users/$uid/suspend");
        $this->assertSame('bad', $this->flash('type'));

        // 全員が止まっている（データで作る）：会社が参加中なら注意。止めた人がいれば「再開」を勧める
        DB::table('users')->where('id', $uid)->update(['active' => 0]);
        $this->admin()->get("/admin/ses/$sid")->assertSee('使える担当者がいません。止めている担当者を再開するか');
        // 全員が退会済のときは、「再開」の語を出さない
        DB::table('users')->where('ses_id', $sid)->where('role', 'ses')->update(['active' => 0, 'left_at' => now()]);
        $this->admin()->get("/admin/ses/$sid")->assertSee('使える担当者がいません。担当者を登録してください。')->assertDontSee('再開するか');
    }

    public function test_t7_invite_refused_for_stopped_or_left(): void
    {
        $sid = $this->sesId('ses1@park.example');
        $stopped = $this->addSesUser($sid, 'ses1h@park.example', '停止 八郎');
        DB::table('users')->where('id', $stopped)->update(['active' => 0]);
        $left = $this->addSesUser($sid, 'ses1i@park.example', '退会 九郎');
        DB::table('users')->where('id', $left)->update(['active' => 0, 'left_at' => now()]);
        // パスワード未設定の停止の人（招待中だった人）
        $inv = W::invite(['email' => 'ses1j@park.example', 'name' => '招待 十郎', 'role' => 'ses', 'ses_id' => $sid], User::where('email', 'admin@park.example')->first());
        DB::table('users')->where('id', $inv->id)->update(['active' => 0, 'token' => null, 'token_expires' => null]);

        $before = DB::table('mail_outbox')->where('kind', 'invite')->count();
        foreach (['ses1h@park.example', 'ses1i@park.example', 'ses1j@park.example'] as $mail) {
            $this->admin()->post("/admin/ses/$sid/users", ['email' => $mail, 'name_sei' => '山', 'name_mei' => '花']);
            $this->assertSame('bad', $this->flash('type'));
            $this->assertStringContainsString('停止中か退会済', $this->flash('msg'));
        }
        $this->assertSame($before, DB::table('mail_outbox')->where('kind', 'invite')->count());
        $this->assertNull(DB::table('users')->where('id', $inv->id)->value('token'));
    }

    // ---------- エンジニア（C） ----------

    public function test_t8_engineer_buttons_and_badge(): void
    {
        $eid = $this->egId('eg1@park.example');
        $html = $this->admin()->get("/engineers/$eid")->assertOk()->getContent();
        $a = strpos($html, '>停止</button>');
        $b = strpos($html, '>退会</button>');
        $c = strpos($html, '>編集</a>');
        $this->assertNotFalse($a);
        $this->assertTrue($a < $b && $b < $c, '停止・退会・編集の順に並んでいません');
        $this->assertStringNotContainsString('停止中</span>', $html);

        $this->admin()->post("/engineers/$eid/suspend");
        $html = $this->admin()->get("/engineers/$eid")->assertOk()->getContent();
        $this->assertStringContainsString('>再開</button>', $html);
        $this->assertStringNotContainsString('>停止</button>', $html);
        $this->assertStringContainsString('停止中</span>', $html);
        $this->assertStringNotContainsString('招待を送る', $html);
        $this->admin()->get('/admin/engineers')->assertSee('停止中');

        // 本人の画面には出ない
        $mine = $this->as('eg1@park.example')->get("/engineers/$eid");
        // 停止中の本人は入れない
        $mine->assertRedirect();
        $this->admin()->post("/engineers/$eid/resume");
        $self = $this->as('eg1@park.example')->get("/engineers/$eid")->assertOk()->getContent();
        $this->assertStringNotContainsString('>停止</button>', $self);
        $this->assertStringNotContainsString('>退会</button>', $self);
    }

    public function test_t9_admin_suspends_and_resumes(): void
    {
        $eid = $this->egId('eg1@park.example');
        $mails = $this->rowCount('mail_outbox');
        $sesUser = $this->uid('ses1@park.example');
        $notes = DB::table('app_notifications')->where('user_id', $sesUser)->count();

        $this->admin()->post("/engineers/$eid/suspend")->assertRedirect("/engineers/$eid");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertNotNull(DB::table('engineers')->where('id', $eid)->value('suspended_at'));
        $this->assertSame(0, (int) DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->value('active'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'engineer-suspend')->count());
        $this->assertSame($notes + 1, DB::table('app_notifications')->where('user_id', $sesUser)->count());
        $this->assertSame($mails, $this->rowCount('mail_outbox'));
        $this->post('/login', ['email' => 'eg1@park.example', 'password' => 'park1234'])->assertSessionHas('flash.type', 'bad');

        $this->admin()->post("/engineers/$eid/suspend");
        $this->assertSame('すでに停止中です。', $this->flash('msg'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'engineer-suspend')->count());

        $this->admin()->post("/engineers/$eid/resume");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertNull(DB::table('engineers')->where('id', $eid)->value('suspended_at'));
        $this->assertSame(1, (int) DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->value('active'));
        $this->admin()->post("/engineers/$eid/resume");
        $this->assertSame('停止していません。', $this->flash('msg'));

        // 退会の記録がある本人は、再開しても戻らない
        $this->admin()->post("/engineers/$eid/suspend");
        DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->update(['left_at' => now()]);
        $this->admin()->post("/engineers/$eid/resume");
        $this->assertSame(0, (int) DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->value('active'));
    }

    public function test_t10_ses_permission_on_engineers(): void
    {
        $mine = $this->egId('eg1@park.example');
        $theirs = $this->egId('eg2@park.example');
        $admin = $this->uid('admin@park.example');
        $notes = DB::table('app_notifications')->where('user_id', $admin)->count();
        $mails = $this->rowCount('mail_outbox');

        $this->as('ses1@park.example')->post("/engineers/$mine/suspend")->assertRedirect("/engineers/$mine");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertNotNull(DB::table('engineers')->where('id', $mine)->value('suspended_at'));
        $this->assertSame($notes + 1, DB::table('app_notifications')->where('user_id', $admin)->count());
        $this->assertSame($mails, $this->rowCount('mail_outbox'));
        $this->as('ses1@park.example')->post("/engineers/$mine/resume");
        $this->assertNull(DB::table('engineers')->where('id', $mine)->value('suspended_at'));

        foreach (['suspend', 'resume'] as $a) {
            $this->as('ses1@park.example')->post("/engineers/$theirs/$a")->assertForbidden();
            $this->as('eg1@park.example')->post("/engineers/$mine/$a")->assertForbidden();
        }
        $this->assertNull(DB::table('engineers')->where('id', $theirs)->value('suspended_at'));
    }

    public function test_t11_working_engineer_can_be_suspended(): void
    {
        $eid = $this->egId('eg3@park.example'); // 稼働中の契約がある
        $this->assertTrue(DB::table('contracts')->where('engineer_id', $eid)->where('status', 'active')->exists());
        $this->admin()->get("/engineers/$eid")->assertSee($this->enc('稼働中の契約があります。契約・請求はそのまま続きます。'), false);

        $this->admin()->post("/engineers/$eid/suspend");
        $this->assertSame('ok', $this->flash('type'));
        $this->assertNotNull(DB::table('engineers')->where('id', $eid)->value('suspended_at'));
        $this->assertTrue(DB::table('contracts')->where('engineer_id', $eid)->where('status', 'active')->exists());

        // 選考中の応募の件数も知らせる
        $e1 = $this->egId('eg1@park.example');
        $this->admin()->get("/engineers/$e1")->assertSee($this->enc('選考中の応募 1 件はそのまま残ります。'), false)->assertDontSee($this->enc('稼働中の契約があります'), false);
    }

    // ---------- 停止の効果（D） ----------

    public function test_t12_suspended_and_left_engineers_are_not_offered(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        $caseId = (int) DB::table('cases')->where('title', 'EC サイトのフロントエンド改善')->value('id');
        $c = DB::table('cases')->find($caseId);
        $eid = $this->egId('eg1@park.example');
        $ids = fn (array $list) => array_map(fn ($m) => $m['engineer']->id, $list);

        // 止める前は候補に出る（確認用）
        $this->assertContains($eid, $ids(Matcher::forCase($caseId, true)));
        $this->assertContains($eid, $ids(CaseMail::candidates($admin, $c, 'own')));

        $this->admin()->post("/engineers/$eid/suspend");
        $this->assertNotContains($eid, $ids(Matcher::forCase($caseId, true)));
        $this->assertNotContains($eid, $ids(Matcher::forCase($caseId, false)));
        $this->assertNotContains($eid, $ids(CaseMail::candidates($admin, $c, 'own')));

        $offers = $this->rowCount('case_offers');
        $this->assertSame(0, W::distribute($caseId, [$eid], $admin));
        $this->assertSame($offers, $this->rowCount('case_offers'));

        $apps = $this->rowCount('applications');
        $this->admin()->post("/cases/$caseId/apply", ['engineer_id' => $eid]);
        $this->assertSame('bad', $this->flash('type'));
        $this->assertSame('このエンジニアは停止中のため、応募できません。', $this->flash('msg'));
        $this->assertSame($apps, $this->rowCount('applications'));

        // SES の応募画面の選択肢
        $this->as('ses1@park.example')->get("/cases/$caseId")->assertOk()->assertDontSee('山田 太郎')->assertSee('高橋 陽介');

        // 退会済も同じ
        $left = DB::table('engineers')->where('name', '高橋 陽介')->value('id');
        DB::table('engineers')->where('id', $left)->update(['status' => 'left']);
        $this->assertNotContains($left, $ids(Matcher::forCase($caseId, true)));
        $this->assertNotContains($left, $ids(CaseMail::candidates($admin, $c, 'own')));
        $this->assertSame(0, W::distribute($caseId, [$left], $admin));
        $this->admin()->post("/cases/$caseId/apply", ['engineer_id' => $left]);
        $this->assertSame('このエンジニアは退会済のため、応募できません。', $this->flash('msg'));
        $this->assertSame($apps, $this->rowCount('applications'));
        $this->as('ses1@park.example')->get("/cases/$caseId")->assertDontSee('高橋 陽介');
    }

    public function test_t13_left_engineer_boundaries(): void
    {
        $left = (int) DB::table('engineers')->where('name', '高橋 陽介')->value('id'); // アカウントなし
        $this->admin()->post("/engineers/$left/suspend");
        $this->assertSame('ok', $this->flash('type')); // アカウントが無くても停止できる
        $this->assertNotNull(DB::table('engineers')->where('id', $left)->value('suspended_at'));

        // 停止中のエンジニアを退会にできる（停止の記録は残る）
        $eid = $this->egId('eg1@park.example');
        $this->admin()->post("/engineers/$eid/suspend");
        $this->admin()->post("/engineers/$eid/delete");
        $this->assertSame('left', DB::table('engineers')->where('id', $eid)->value('status'));
        $this->assertNotNull(DB::table('engineers')->where('id', $eid)->value('suspended_at'));
        $row = DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->first();
        $this->assertNotNull($row->left_at);
        $this->assertSame('退会済', user_state($row));

        foreach (['suspend', 'resume'] as $a) {
            $this->admin()->post("/engineers/$eid/$a");
            $this->assertSame('bad', $this->flash('type'));
            $this->assertSame('退会済のエンジニアは操作できません。', $this->flash('msg'));
        }
        $this->admin()->get("/engineers/$eid")->assertDontSee('>停止</button>', false)->assertDontSee('>再開</button>', false)->assertDontSee('>退会</button>', false);

        W::refreshEngineerStatus($eid);
        $this->assertSame('left', DB::table('engineers')->where('id', $eid)->value('status'));
    }

    public function test_t14_ses_leave_sets_left_at(): void
    {
        $sid = $this->sesId('ses2@park.example');
        $this->admin()->post("/admin/ses/$sid/delete");
        $this->assertSame('left', DB::table('ses_companies')->where('id', $sid)->value('status'));
        $this->assertNotNull(DB::table('users')->where('email', 'ses2@park.example')->value('left_at'));
        $this->assertNotNull(DB::table('users')->where('email', 'eg2@park.example')->value('left_at'));
        // 会社が退会済なら、担当者全員が退会済でボタンは出ない
        $uid = $this->uid('ses2@park.example');
        $this->admin()->get("/admin/ses/$sid")->assertDontSee("/admin/ses/$sid/users/$uid/suspend")->assertDontSee("/admin/ses/$sid/users/$uid/delete");
    }

    // ---------- 招待の状況（E） ----------

    public function test_t15_dashboard_invite_rows(): void
    {
        $s1 = $this->sesId('ses1@park.example');
        $s2 = $this->sesId('ses2@park.example');
        // 会社1の担当者は全員退会済、会社2の担当者は停止
        DB::table('users')->where('email', 'ses1@park.example')->update(['active' => 0, 'left_at' => now()]);
        DB::table('users')->where('email', 'ses2@park.example')->update(['active' => 0]);

        $html = $this->admin()->get('/admin')->assertOk()->getContent();
        $this->assertStringNotContainsString('緑川 一郎', $html);
        $this->assertMatchesRegularExpression('#<td>みどり情報システム</td>\s*<td>担当者が未登録#', $html);
        $this->assertMatchesRegularExpression('#<td>青木 花子</td>\s*<td>あおぞらテック</td>\s*<td>停止#', $html);
        $this->assertMatchesRegularExpression('#<td>ひだまりソリューションズ</td>\s*<td>使用中#', $html);
        $this->assertNotSame($s1, $s2);
    }

    // レビュー2：状態を「退会済」にできない／退会済から変えられない
    public function test_t17_status_left_cannot_be_set_or_changed_by_edit(): void
    {
        $eid = $this->egId('eg1@park.example');
        $this->admin()->get("/engineers/$eid/edit")->assertOk()->assertDontSee('<option value="left"', false);
        $this->admin()->post("/engineers/$eid", ['status' => 'left']);
        $this->assertSame('bad', $this->flash('type'));
        $this->assertNotSame('left', DB::table('engineers')->where('id', $eid)->value('status'));

        $left = (int) DB::table('engineers')->where('name', '高橋 陽介')->value('id');
        DB::table('engineers')->where('id', $left)->update(['status' => 'left']);
        $this->admin()->get("/engineers/$left/edit")->assertOk()->assertSee('退会済');
        $this->admin()->post("/engineers/$left", ['status' => 'available']);
        $this->assertSame('left', DB::table('engineers')->where('id', $left)->value('status'));
    }

    // レビュー3：退会済には招待を送らない
    public function test_t18_invite_refused_for_left_engineer(): void
    {
        $eid = $this->egId('eg1@park.example');
        DB::table('engineers')->where('id', $eid)->update(['status' => 'left']);
        $mails = $this->rowCount('mail_outbox');
        $this->admin()->post("/engineers/$eid/invite");
        $this->assertSame('bad', $this->flash('type'));
        $this->assertSame($mails, $this->rowCount('mail_outbox'));
    }

    // レビュー4：停止中・退会済のエンジニアは推薦できない
    public function test_t19_recommend_refused(): void
    {
        $caseId = (int) DB::table('cases')->where('title', 'EC サイトのフロントエンド改善')->value('id'); // 管理者が登録した案件
        $eid = $this->egId('eg1@park.example');
        DB::table('engineers')->where('id', $eid)->update(['suspended_at' => now()]);
        $this->as('ses1@park.example')->post("/cases/$caseId/recommend", ['engineer_id' => $eid]);
        $this->assertSame('bad', $this->flash('type'));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'recommend')->count());
        $left = (int) DB::table('engineers')->where('name', '高橋 陽介')->value('id');
        DB::table('engineers')->where('id', $left)->update(['status' => 'left']);
        $this->as('ses1@park.example')->post("/cases/$caseId/recommend", ['engineer_id' => $left]);
        $this->assertSame('bad', $this->flash('type'));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'recommend')->count());
    }

    // レビュー5：停止中・退会済のエンジニアの返事（リンク）は記録しない
    public function test_t20_reply_link_refused(): void
    {
        $caseId = (int) DB::table('cases')->where('title', 'EC サイトのフロントエンド改善')->value('id');
        $admin = $this->uid('admin@park.example');
        $eid = $this->egId('eg1@park.example');
        $token = bin2hex(random_bytes(16));
        DB::table('case_mails')->insert(['case_id' => $caseId, 'engineer_id' => $eid, 'ses_id' => null, 'sent_by' => $admin, 'route' => 'own', 'mode' => 'same',
            'subject' => '案内', 'body' => '本文', 'score' => 50, 'suggest' => '', 'outbox_id' => null, 'sent_at' => now_str(), 'token' => $token]);

        // 普通のときは、返事の画面が開く
        $this->get("/r/$token/interested")->assertOk()->assertDontSee('いま受け付けていません');

        foreach (['suspended', 'left'] as $how) {
            DB::table('engineers')->where('id', $eid)->update($how === 'left' ? ['status' => 'left', 'suspended_at' => null] : ['status' => 'available', 'suspended_at' => now()]);
            $this->get("/r/$token/interested")->assertOk()->assertSee('いま受け付けていません');
            $this->post("/r/$token", ['answer' => 'interested'])->assertOk()->assertSee('いま受け付けていません');
            $reply = DB::table('case_mails')->where('token', $token)->value('reply');
            $this->assertNotSame('interested', $reply);
        }
    }

    // レビュー6：招待を出せなかったときは「送りました」と出さない
    public function test_t21_approve_does_not_claim_invite_when_not_sent(): void
    {
        $sid = $this->sesId('ses1@park.example');
        DB::table('users')->where('email', 'ses1@park.example')->update(['active' => 0]);
        DB::table('ses_companies')->where('id', $sid)->update(['status' => 'suspended']);
        $mails = DB::table('mail_outbox')->where('kind', 'invite')->count();
        $this->admin()->post("/admin/ses/$sid/approve");
        $this->assertSame($mails, DB::table('mail_outbox')->where('kind', 'invite')->count());
        $this->assertStringNotContainsString('招待メールを送りました', $this->flash('msg'));
    }
}
