<?php

namespace Tests\Feature;

use App\Services\CaseSearch as CS;
use App\Services\EgStaff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.17.0 EG ごとの担当者（PLAN-v0170.md の A1〜A11）。T1〜T16。名前・アドレスはすべて架空
class V0170EgStaffTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;
    use V0170World;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    private function staffOf(int $eid): ?int
    {
        $v = DB::table('engineers')->where('id', $eid)->value('staff_user_id');
        return $v === null ? null : (int) $v;
    }

    // 担当者を空にして、アカウントの登録した人を決める（マイグレーション前の形）
    private function blank(int $eid, ?int $registeredBy = null, ?string $email = null): void
    {
        DB::table('engineers')->where('id', $eid)->update(['staff_user_id' => null]);
        DB::table('engineer_staff_logs')->where('engineer_id', $eid)->delete();
        if ($email) DB::table('users')->where('id', $this->completeAndId($eid, $email))->update(['registered_by' => $registeredBy]);
    }

    private function completeAndId(int $eid, string $email): int
    {
        $this->egLogin($eid, $email);
        return (int) $this->user($email)->id;
    }

    public function test_t1_backfill_uses_registrant_when_usable(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $this->blank($b1, $this->user(self::BY)->id, 'eg1-17@example.test');
        $this->assertSame(1, EgStaff::fillMissing());
        $this->assertSame((int) $this->user(self::BY)->id, $this->staffOf($b1));
        $log = DB::table('engineer_staff_logs')->where('engineer_id', $b1)->get();
        $this->assertCount(1, $log);
        $this->assertSame('backfill', $log[0]->reason);
        $this->assertNull($log[0]->changed_by);
    }

    public function test_t2_backfill_falls_back_to_ses(): void
    {
        $bx = (int) $this->user(self::BX)->id;
        // 登録した人が停止中
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        DB::table('users')->where('email', self::BY)->update(['active' => 0]);
        $this->blank($b1, $this->user(self::BY)->id, 'eg2-17@example.test');
        // 管理者が登録した
        $b2 = $this->mkEng(self::SES2, '他社 次郎', 'B.C');
        $this->blank($b2, $this->user(self::ADMIN)->id, 'eg3-17@example.test');
        // アカウントが無い
        $b3 = $this->mkEng(self::SES2, '他社 三郎', 'B.D');
        $this->blank($b3);
        $this->assertSame(3, EgStaff::fillMissing());
        foreach ([$b1, $b2, $b3] as $id) $this->assertSame($bx, $this->staffOf($id));
    }

    public function test_t3_company_without_usable_staff_stays_empty(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $this->blank($b1);
        DB::table('ses_companies')->where('id', $this->sesId(self::SES2))->update(['status' => 'suspended']);
        $this->assertSame(0, EgStaff::fillMissing());
        $this->assertNull($this->staffOf($b1));
        $this->assertNull(EgStaff::of(DB::table('engineers')->find($b1)));
        $this->assertSame(1, EgStaff::emptyCount());
    }

    public function test_t4_registrant_becomes_staff(): void
    {
        $bx = (int) $this->user(self::BX)->id;
        $by = (int) $this->user(self::BY)->id;
        $this->as(self::BY)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '四郎', 'email' => 'new4-17@park.example'])->assertRedirect();
        $eid = (int) DB::table('engineers')->where('email', 'new4-17@park.example')->value('id');
        $this->assertSame($by, $this->staffOf($eid));
        $this->assertSame('create', DB::table('engineer_staff_logs')->where('engineer_id', $eid)->value('reason'));
        $this->assertNotSame($bx, $by);
    }

    public function test_t5_admin_registered_goes_to_ses(): void
    {
        $b = $this->sesId(self::SES2);
        $this->as(self::ADMIN)->post('/engineers', ['ses_id' => $b, 'name_sei' => '架空', 'name_mei' => '五郎', 'email' => 'new5-17@park.example'])->assertRedirect();
        $eid = (int) DB::table('engineers')->where('email', 'new5-17@park.example')->value('id');
        $this->assertSame((int) $this->user(self::BX)->id, $this->staffOf($eid));
    }

    public function test_t6_suspend_moves_engineers_to_ses(): void
    {
        $by = (int) $this->user(self::BY)->id;
        $bx = (int) $this->user(self::BX)->id;
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        DB::table('engineers')->where('id', $b1)->update(['staff_user_id' => $by]);
        $this->as(self::ADMIN)->post('/admin/ses/'.$this->sesId(self::SES2).'/users/'.$by.'/suspend')->assertRedirect();
        $this->assertSame($bx, $this->staffOf($b1));
        $this->assertSame('suspend', DB::table('engineer_staff_logs')->where('engineer_id', $b1)->orderByDesc('id')->value('reason'));
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $bx)->where('title', 'like', '%の担当になりました')->exists());
    }

    public function test_t7_leave_moves_engineers_to_ses(): void
    {
        $by = (int) $this->user(self::BY)->id;
        $bx = (int) $this->user(self::BX)->id;
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        DB::table('engineers')->where('id', $b1)->update(['staff_user_id' => $by]);
        $this->as(self::ADMIN)->post('/admin/ses/'.$this->sesId(self::SES2).'/users/'.$by.'/delete')->assertRedirect();
        $this->assertSame($bx, $this->staffOf($b1));
        $this->assertSame('leave', DB::table('engineer_staff_logs')->where('engineer_id', $b1)->orderByDesc('id')->value('reason'));
    }

    // 管理者が B 社の担当者を兼ねていて、B 社の EG の担当者になっている状態を作る。[管理者の id, B 社の id, EG の id]
    private function dualAdminWithEg(): array
    {
        $b = $this->sesId(self::SES2);
        $id = \App\Services\Workflow::createUser('adm-dual17@example.test', '架空 管理兼任', 'admin', 'park1234');
        $this->completeProfile($id, ['company_name' => 'テスト運営']);
        DB::table('users')->where('id', $id)->update(['ses_id' => $b]);
        DB::table('users')->where('id', $id)->whereNull('registered_at')->update(['registered_at' => now_str()]); // 本登録済み（使える担当者）にする
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        DB::table('engineers')->where('id', $b1)->update(['staff_user_id' => $id]);
        return [$id, $b, $b1];
    }

    public function test_t7b_stop_dual_moves_engineers_to_ses(): void
    {
        [$id, $b, $b1] = $this->dualAdminWithEg();
        $bx = (int) $this->user(self::BX)->id;
        $res = \App\Services\Workflow::stopStaffDual($b, DB::table('users')->find($id));
        $this->assertSame('ok', $res['type']);
        $this->assertNull(DB::table('users')->where('id', $id)->value('ses_id'));
        $this->assertSame($bx, $this->staffOf($b1));
        $this->assertSame('leave', DB::table('engineer_staff_logs')->where('engineer_id', $b1)->orderByDesc('id')->value('reason'));
    }

    public function test_t7c_suspend_admin_moves_engineers_to_ses(): void
    {
        [$id, $b, $b1] = $this->dualAdminWithEg();
        $bx = (int) $this->user(self::BX)->id;
        $res = \App\Services\Workflow::suspendAdmin($id, $this->user(self::ADMIN));
        $this->assertSame('ok', $res['type']);
        $this->assertSame($bx, $this->staffOf($b1));
        $this->assertSame('suspend', DB::table('engineer_staff_logs')->where('engineer_id', $b1)->orderByDesc('id')->value('reason'));
    }

    // 登録した人が管理者（兼任）でも、新しい EG の担当者は会社の SES（role=ses の人だけ本人になる）
    public function test_t7d_dual_admin_registering_gives_ses_as_staff(): void
    {
        [$id, $b] = $this->dualAdminWithEg();
        $this->assertSame((int) $this->user(self::BX)->id, EgStaff::initial($b, DB::table('users')->find($id)));
    }

    public function test_t7e_move_to_company_without_staff_clears_instead_of_keeping_old(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $c = $this->sesId(self::SES3);
        DB::table('users')->where('ses_id', $c)->where('role', 'ses')->update(['active' => 0]); // 移し先に使える担当者がいない
        EgStaff::moveTo($b1, $c, $this->user(self::ADMIN));
        $this->assertNull($this->staffOf($b1));
    }

    public function test_t8_move_to_another_company_goes_to_its_ses(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $a = $this->sesId(self::SES1);
        $this->as(self::SES2)->post("/engineers/$b1/affiliation", ['ses_id' => $a])->assertRedirect();
        $rep = EgStaff::rep($a);
        $this->assertNotNull($rep);
        $this->assertSame((int) $rep->id, $this->staffOf($b1));
        $this->assertSame('move', DB::table('engineer_staff_logs')->where('engineer_id', $b1)->orderByDesc('id')->value('reason'));
    }

    public function test_t9_search_uses_the_engineers_staff(): void
    {
        $w = $this->world();
        $by = $this->user(self::BY);
        DB::table('engineers')->where('id', $w['b1'])->update(['staff_user_id' => $by->id]);
        $this->as(self::SES1)->get("/searches/{$w['sid']}")->assertOk()->assertSee('B.B（'.CS::staffLabel($by).'）');
        // 担当者が使えなくなったら、会社の SES
        DB::table('users')->where('id', $by->id)->update(['active' => 0]);
        $this->as(self::SES1)->get("/searches/{$w['sid']}")->assertOk()->assertSee('B.B（'.CS::staffLabel($this->user(self::BX)).'）');
    }

    public function test_t10_referral_goes_to_the_engineers_staff(): void
    {
        $w = $this->world();
        $by = $this->user(self::BY);
        DB::table('engineers')->where('id', $w['b1'])->update(['staff_user_id' => $by->id]);
        $this->sendReferral($w);
        $mail = DB::table('mail_outbox')->where('kind', 'case-referral')->first();
        $this->assertSame($by->email, $mail->to_email);
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $by->id)->where('title', 'like', 'エンジニアの紹介の依頼：%')->exists());
    }

    public function test_t11_staff_can_reassign_within_company(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $egMail = $this->egLogin($b1, 'eg11-17@example.test');
        $bx = $this->user(self::BX);
        $by = $this->user(self::BY);
        $this->assertSame((int) $bx->id, $this->staffOf($b1));
        $this->as(self::BY)->post("/engineers/$b1/staff", ['staff_user_id' => $by->id])->assertRedirect();
        $this->assertSame((int) $by->id, $this->staffOf($b1));
        $log = DB::table('engineer_staff_logs')->where('engineer_id', $b1)->orderByDesc('id')->first();
        $this->assertSame('manual', $log->reason);
        $this->assertSame((int) $bx->id, (int) $log->from_user_id);
        $this->assertSame((int) $by->id, (int) $log->to_user_id);
        $this->assertSame((int) $by->id, (int) $log->changed_by);
        $audit = DB::table('audit_log')->where('action', 'eg-staff-change')->orderByDesc('id')->first();
        $this->assertNotNull($audit);
        $this->assertStringNotContainsString('他社', (string) $audit->detail); // 名前は書かない
        $this->assertStringNotContainsString('花子', (string) $audit->detail);
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $by->id)->where('title', 'like', '%の担当になりました')->exists());
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $bx->id)->where('title', 'like', '%の担当が替わりました')->exists());
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $this->user($egMail)->id)->where('title', '担当者が替わりました')->exists());
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', 'eg-staff')->count(), 'お知らせだけで、メールは出さない');
        // 編集の画面に、付け替えの記録
        $this->as(self::BY)->get("/engineers/$b1/edit")->assertOk()->assertSee('担当者を付け替える')->assertSee('付け替えの記録');
    }

    public function test_t12_cannot_pick_someone_from_another_company(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $before = $this->staffOf($b1);
        $this->as(self::BX)->post("/engineers/$b1/staff", ['staff_user_id' => $this->user(self::AS2)->id])->assertRedirect()->assertSessionHas('flash.msg', '選べない担当者です。');
        $this->assertSame($before, $this->staffOf($b1));
    }

    public function test_t13_admin_can_reassign_any_company(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $by = $this->user(self::BY);
        $this->as(self::ADMIN)->post("/engineers/$b1/staff", ['staff_user_id' => $by->id])->assertRedirect();
        $this->assertSame((int) $by->id, $this->staffOf($b1));
        $this->as(self::ADMIN)->post("/engineers/$b1/staff", ['staff_user_id' => $this->user(self::AS2)->id])->assertRedirect()->assertSessionHas('flash.msg', '選べない担当者です。');
        $this->assertSame((int) $by->id, $this->staffOf($b1));
    }

    public function test_t14_engineer_sees_staff_but_cannot_reassign(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $mail = $this->egLogin($b1, 'eg14-17@example.test');
        $bx = $this->user(self::BX);
        $this->as($mail)->post("/engineers/$b1/staff", ['staff_user_id' => $this->user(self::BY)->id])->assertForbidden();
        $this->assertSame((int) $bx->id, $this->staffOf($b1));
        $this->as($mail)->get("/engineers/$b1")->assertOk()->assertSee('担当者')->assertSee($bx->name);
        $this->as($mail)->get("/engineers/$b1/edit")->assertOk()->assertDontSee('担当者を付け替える');
    }

    public function test_t15_other_company_cannot_reassign(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $before = $this->staffOf($b1);
        $this->as(self::SES3)->post("/engineers/$b1/staff", ['staff_user_id' => $this->user(self::BY)->id])->assertForbidden();
        $this->as(self::SES1)->post("/engineers/$b1/staff", ['staff_user_id' => $this->user(self::BY)->id])->assertForbidden();
        $this->assertSame($before, $this->staffOf($b1));
    }

    public function test_t16_lists_have_a_staff_column(): void
    {
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $bx = $this->user(self::BX);
        $this->as(self::SES2)->get('/ses/engineers')->assertOk()->assertSee('担当者')->assertSee($bx->name);
        $this->as(self::ADMIN)->get('/admin/engineers')->assertOk()->assertSee('担当者')->assertSee($bx->name);
        $this->assertNotNull($b1);
    }
}
