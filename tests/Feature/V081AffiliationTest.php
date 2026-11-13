<?php

namespace Tests\Feature;

use App\Services\Affiliation;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.8.1 エンジニアの所属 SES を変える（PLAN.md 7章 T1〜T19。T20 は既存のテスト一式）。名前・メールは、テストの中で作る架空のものだけ
// 会社：A＝SES1（みどり情報システム）、B＝SES2（あおぞらテック）、C＝SES3（ひだまりソリューションズ。停止中にして使う）、直属
class V081AffiliationTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    // 見本データの応募・契約は、選考中・稼働中のものがあるので、まず全部終わったことにする（各テストが必要なものを自分で作る）
    protected function setUp(): void
    {
        parent::setUp();
        DB::table('applications')->update(['status' => 'rejected']);
        DB::table('contracts')->update(['status' => 'ended']);
    }

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';   // A のエンジニア
    private const EG2 = 'eg2@park.example';   // B のエンジニア
    private const A = 'みどり情報システム';
    private const B = 'あおぞらテック';
    private const C = 'ひだまりソリューションズ';
    private const DIRECT = '管理者の直属';

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    // 本登録が済んだ形のエンジニア（アカウント付き）を、指定の会社に作る
    private function mkEgIn(int $sid, string $name, string $email, array $over = []): int
    {
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
        return $id;
    }

    private function mkStaff(string $sesEmail, string $email, string $name): int
    {
        $uid = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $this->sesId($sesEmail)]);
        DB::table('users')->where('id', $uid)->update(['secrecy_agreed_at' => now_str(), 'phone' => '03-0000-0004']);
        return $uid;
    }

    private function mkCase(): int
    {
        return (int) DB::table('cases')->insertGetId(['title' => '架空の案件', 'status' => 'open', 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    private function mkApp(int $eid, int $sesId, string $status): int
    {
        return (int) DB::table('applications')->insertGetId(['case_id' => $this->mkCase(), 'engineer_id' => $eid, 'ses_id' => $sesId, 'status' => $status, 'applied_at' => now_str()]);
    }

    private function mkContract(int $eid, int $sesId, string $status): int
    {
        return (int) DB::table('contracts')->insertGetId(['case_id' => $this->mkCase(), 'engineer_id' => $eid, 'ses_id' => $sesId, 'price' => 60, 'start_date' => today_str(), 'status' => $status, 'created_at' => now_str()]);
    }

    private function move(string $as, int $eid, $to)
    {
        return $this->as($as)->post("/engineers/$eid/affiliation", ['ses_id' => $to]);
    }

    private function engSes(int $eid): int
    {
        return (int) DB::table('engineers')->where('id', $eid)->value('ses_id');
    }

    private function egUserSes(int $eid): int
    {
        return (int) DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->value('ses_id');
    }

    // T1：管理者はどの会社のエンジニアも、参加中の SES か直属へ。直属のエンジニアも A へ
    public function test_t1_admin_moves(): void
    {
        $eg1 = $this->engId(self::EG1);
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2); $direct = W::directSesId();

        $this->move(self::ADMIN, $eg1, $b)->assertRedirect("/engineers/$eg1")->assertSessionHas('flash.msg', '所属を「'.self::B.'」に変えました。');
        $this->assertSame([$b, $b], [$this->engSes($eg1), $this->egUserSes($eg1)]);

        $this->move(self::ADMIN, $eg1, $direct)->assertRedirect("/engineers/$eg1");
        $this->assertSame([$direct, $direct], [$this->engSes($eg1), $this->egUserSes($eg1)]);

        $this->move(self::ADMIN, $eg1, $a)->assertRedirect("/engineers/$eg1");
        $this->assertSame([$a, $a], [$this->engSes($eg1), $this->egUserSes($eg1)]);
    }

    // T2：担当者（role=ses）も、自社のエンジニアをほかの参加中の SES へ。戻り先は自社の一覧
    public function test_t2_staff_moves(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->mkStaff(self::SES1, 'staff-a@example.test', '担当 花子');
        $b = $this->sesId(self::SES2);
        $this->move('staff-a@example.test', $eg1, $b)->assertRedirect('/ses/engineers')
            ->assertSessionHas('flash.msg', '山田 太郎 さんの所属を「'.self::B.'」に変えました。これからは '.self::B.' が扱います。');
        $this->assertSame($b, $this->engSes($eg1));
    }

    // T3：SES が選べない変更先（直属・停止中の会社・自社・いない id・数字でない値・空）
    public function test_t3_bad_targets(): void
    {
        $eg1 = $this->engId(self::EG1);
        $a = $this->sesId(self::SES1);
        DB::table('ses_companies')->where('name', self::C)->update(['status' => 'suspended']);
        $c = (int) DB::table('ses_companies')->where('name', self::C)->value('id');

        foreach ([W::directSesId() => '選べない会社です。', $c => '選べない会社です。', 999999 => '選べない会社です。', 'abc' => '選べない会社です。',
                  '' => '変更先を選んでください。'] as $to => $msg) {
            $this->move(self::SES1, $eg1, (string) $to)->assertSessionHas('flash.msg', $msg);
            $this->assertSame([$a, $a], [$this->engSes($eg1), $this->egUserSes($eg1)]);
        }
        $this->move(self::SES1, $eg1, $a)->assertSessionHas('flash.msg', 'すでに「'.self::A.'」の所属です。');
        $this->assertSame($a, $this->engSes($eg1));
    }

    // T4：SES は、他社・直属のエンジニアを動かせない（自社へ引き入れることもできない）
    public function test_t4_other_company_forbidden(): void
    {
        $eg2 = $this->engId(self::EG2);
        $dEg = $this->mkEgIn(W::directSesId(), '直属 一郎', 'direct-eg@example.test');
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2); $direct = W::directSesId();
        $this->move(self::SES1, $eg2, $a)->assertForbidden();
        $this->move(self::SES1, $dEg, $a)->assertForbidden();
        $this->assertSame([$b, $direct], [$this->engSes($eg2), $this->engSes($dEg)]);
    }

    // T5：本人は変えられない。編集の保存に ses_id を混ぜても、だれが送っても所属は変わらない
    public function test_t5_self_and_update_cannot_move(): void
    {
        $eg1 = $this->engId(self::EG1);
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $this->move(self::EG1, $eg1, $b)->assertForbidden();
        $this->assertSame($a, $this->engSes($eg1));

        $body = ['name_sei' => '山田', 'name_mei' => '太郎', 'kana_sei' => 'やまだ', 'kana_mei' => 'たろう', 'phone' => '090-0000-0001',
            'postal_code' => '100-0001', 'address' => '東京都（テスト）', 'email' => self::EG1, 'ses_id' => $b];
        foreach ([self::EG1, self::ADMIN, self::SES1] as $who) {
            $this->as($who)->post("/engineers/$eg1", $body)->assertStatus(302);
            $this->assertSame($a, $this->engSes($eg1), $who);
            $this->assertSame($a, $this->egUserSes($eg1), $who);
        }
    }

    // T6：変えられない条件（契約・選考中の5つの状態・退会済・兼任）。終わった応募・契約だけなら変えられる
    public function test_t6_blockers(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);

        $e = $this->mkEgIn($a, '契約 一郎', 'k1@example.test');
        $this->mkContract($e, $a, 'active');
        $this->move(self::ADMIN, $e, $b)->assertSessionHas('flash.msg', '稼働中の契約があるため、所属を変えられません。契約が終わってから変えてください。');
        $this->assertSame($a, $this->engSes($e));

        foreach (Affiliation::IN_SELECTION as $i => $st) {
            $e = $this->mkEgIn($a, '選考 '.$i, "sel{$i}@example.test");
            $this->mkApp($e, $a, $st);
            $this->move(self::ADMIN, $e, $b)->assertSessionHas('flash.msg', '選考中の応募が 1 件あるため、所属を変えられません。選考が終わってから変えてください。');
            $this->assertSame($a, $this->engSes($e), $st);
        }

        $e = $this->mkEgIn($a, '退会 三郎', 'left@example.test', ['status' => 'left']);
        $this->move(self::ADMIN, $e, $b)->assertSessionHas('flash.msg', '退会済のエンジニアは、所属を変えられません。');
        $this->assertSame($a, $this->engSes($e));

        $e = $this->mkEgIn($a, '兼任 四郎', 'dual@example.test');
        $staff = $this->mkStaff(self::SES1, 'dual-staff@example.test', '兼任 担当');
        DB::table('users')->where('id', $staff)->update(['engineer_id' => $e]);
        $this->move(self::ADMIN, $e, $b)->assertSessionHas('flash.msg', 'この方は SES 担当者（または管理者）を兼ねているため、所属を変えられません。管理者にご相談ください。');
        $this->assertSame($a, $this->engSes($e));

        // 終わった応募・終わった契約だけなら変えられる
        $e = $this->mkEgIn($a, '終了 五郎', 'done@example.test');
        $this->mkApp($e, $a, 'rejected');
        $this->mkContract($e, $a, 'ended');
        $this->move(self::ADMIN, $e, $b)->assertSessionHas('flash.msg', '所属を「'.self::B.'」に変えました。');
        $this->assertSame($b, $this->engSes($e));
    }

    // T7：停止中は管理者だけ。仮登録中はどちらも不可
    public function test_t7_suspended_and_provisional(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $s = $this->mkEgIn($a, '停止 二郎', 'stop@example.test', ['suspended_at' => now_str()]);
        $this->move(self::SES1, $s, $b)->assertSessionHas('flash.msg', '停止中のエンジニアの所属は、管理者だけが変えられます。');
        $this->assertSame($a, $this->engSes($s));
        $this->move(self::ADMIN, $s, $b)->assertSessionHas('flash.msg', '所属を「'.self::B.'」に変えました。');
        $this->assertSame($b, $this->engSes($s));

        $p = $this->mkEgIn($a, '仮登録 一郎', 'prov@example.test');
        DB::table('users')->where('engineer_id', $p)->update(['registered_at' => null]);
        $this->move(self::ADMIN, $p, $b)->assertSessionHas('flash.msg', '仮登録中のため、ここでは変えられません。「仮登録の内容を直す」（氏名・メール・所属）から変えてください。');
        $this->move(self::SES1, $p, $b)->assertSessionHas('flash.msg', '仮登録中のため変えられません。所属を変えるときは、管理者にご依頼ください。');
        $this->assertSame($a, $this->engSes($p));
    }

    // T8：付け替えるのは engineers と users（role=eg）だけ。記録の表は1つも変わらない
    public function test_t8_records_unchanged(): void
    {
        $eg1 = $this->engId(self::EG1);
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $admin = (int) $this->user(self::ADMIN)->id;
        $app = $this->mkApp($eg1, $a, 'rejected');
        $con = $this->mkContract($eg1, $a, 'ended');
        $mail = DB::table('case_mails')->insertGetId(['case_id' => $this->mkCase(), 'engineer_id' => $eg1, 'ses_id' => $a, 'sent_by' => $admin, 'route' => 'own', 'mode' => 'same',
            'subject' => '件名', 'body' => '本文', 'sent_at' => now_str()]);
        $dm = DB::table('direct_mails')->insertGetId(['send_key' => str_repeat('k', 32), 'from_user_id' => $admin, 'from_role' => 'ses', 'from_ses_id' => $a,
            'from_label' => '架空', 'subject' => '件名', 'body' => '本文', 'to_count' => 1, 'created_at' => now_str()]);
        $snap = fn () => [DB::table('applications')->find($app), DB::table('contracts')->find($con), DB::table('case_mails')->find($mail), DB::table('direct_mails')->find($dm)];
        $before = $snap();

        $this->move(self::ADMIN, $eg1, $b)->assertRedirect("/engineers/$eg1");
        $this->assertEquals($before, $snap());
        $this->assertSame($b, $this->engSes($eg1));
        // 兼任でない SES 担当者の行は、そのまま
        $this->assertSame($a, $this->sesId(self::SES1));
    }

    // T9：記録（audit_log）に会社名と id だけ。エンジニアの名前は書かない
    public function test_t9_audit(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->move(self::ADMIN, $eg1, $this->sesId(self::SES2));
        $rows = DB::table('audit_log')->where('action', 'engineer-move')->get();
        $this->assertCount(1, $rows);
        $this->assertStringContainsString("エンジニア #{$eg1}：".self::A.' → '.self::B, $rows[0]->detail);
        $this->assertStringNotContainsString('山田', $rows[0]->detail);
    }

    // T10：お知らせ（元・先・本人。SES が変えたときは管理者にも）。本人にはメール
    public function test_t10_notifications(): void
    {
        $eg1 = $this->engId(self::EG1);
        $title = '山田 太郎 さんの所属が変わりました';
        $n = fn (string $email) => DB::table('app_notifications')->where('user_id', $this->user($email)->id)->where('title', $title)->get();

        $this->move(self::ADMIN, $eg1, $this->sesId(self::SES2));
        $this->assertCount(1, $n(self::SES1));
        $this->assertSame('ses', $n(self::SES1)[0]->for_role);
        $this->assertCount(1, $n(self::SES2));
        $this->assertCount(0, $n(self::ADMIN)); // 管理者が変えたときは、管理者には出さない
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->user(self::EG1)->id)->where('title', '所属の会社が変わりました')->count());
        $mails = DB::table('mail_outbox')->where('kind', 'engineer-move')->get();
        $this->assertCount(1, $mails);
        $this->assertSame(self::EG1, $mails[0]->to_email);

        // SES が B→A へ戻すのではなく、B の SES が自社の人を A へ移す。管理者にも出る（メールは重ねない）
        $this->move(self::SES2, $eg1, $this->sesId(self::SES1));
        $this->assertCount(1, $n(self::ADMIN));
        $this->assertSame(2, DB::table('mail_outbox')->where('kind', 'engineer-move')->count()); // 本人あての2通だけ

        // 直属 → A：管理者あては1人1件だけ
        $dEg = $this->mkEgIn(W::directSesId(), '直属 一郎', 'direct-eg@example.test');
        $this->move(self::ADMIN, $dEg, $this->sesId(self::SES1));
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->user(self::ADMIN)->id)->where('title', '直属 一郎 さんの所属が変わりました')->count());
    }

    // T11：同じ変更を2回送っても、記録・お知らせ・メールは1回分
    public function test_t11_double_post(): void
    {
        $eg1 = $this->engId(self::EG1);
        $b = $this->sesId(self::SES2);
        $this->move(self::ADMIN, $eg1, $b);
        $counts = fn () => [DB::table('audit_log')->where('action', 'engineer-move')->count(), DB::table('app_notifications')->count(), DB::table('mail_outbox')->count()];
        $after = $counts();
        $this->move(self::ADMIN, $eg1, $b)->assertSessionHas('flash.msg', 'すでに「'.self::B.'」の所属です。');
        $this->assertSame($after, $counts());
        $this->assertSame(1, $after[0]);
    }

    // T12：移したあと、元の会社の SES・担当者は詳細・編集を開けない。新しい会社の一覧には出る
    public function test_t12_visibility(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->mkStaff(self::SES1, 'staff-a@example.test', '担当 花子');
        $this->move(self::ADMIN, $eg1, $this->sesId(self::SES2));
        foreach ([self::SES1, 'staff-a@example.test'] as $who) {
            $this->as($who)->get("/engineers/$eg1")->assertForbidden();
            $this->as($who)->get("/engineers/$eg1/edit")->assertForbidden();
        }
        $this->as(self::SES2)->get('/ses/engineers')->assertOk()->assertSee('山田 太郎');
    }

    // T13：移したあとの連絡メール：元の会社は 403、新しい会社は作成画面を開ける
    public function test_t13_direct_mail_follows(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->move(self::ADMIN, $eg1, $this->sesId(self::SES2));
        $this->as(self::SES1)->get("/mails/new?eg[]=$eg1")->assertForbidden();
        $this->as(self::SES2)->get("/mails/new?eg[]=$eg1")->assertOk();
    }

    // T14：ログインなしはログインへ。いないエンジニアは 404
    public function test_t14_guest_and_missing(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->flushSession();
        $this->post("/engineers/$eg1/affiliation", ['ses_id' => 1])->assertRedirect();
        $this->assertSame($this->sesId(self::SES1), $this->engSes($eg1));
        $this->move(self::ADMIN, 999999, 1)->assertNotFound();
    }

    // T15：編集画面の「所属」の欄。選べる会社は立場で変わる
    public function test_t15_edit_card(): void
    {
        $eg1 = $this->engId(self::EG1);
        DB::table('ses_companies')->where('name', self::C)->update(['status' => 'suspended']);
        W::directSesId();

        $page = $this->as(self::ADMIN)->get("/engineers/$eg1/edit")->assertOk()->assertSee('/engineers/'.$eg1.'/affiliation', false)->assertSee('今の所属：'.self::A);
        $page->assertSeeInOrder([self::DIRECT, self::B], false);
        $page->assertDontSee(self::C);

        $this->as(self::SES1)->get("/engineers/$eg1/edit")->assertOk()->assertSee('/engineers/'.$eg1.'/affiliation', false)
            ->assertSee(self::B)->assertDontSee(self::C)->assertDontSee(self::DIRECT)->assertSee('移す先の会社にも、あらかじめお伝えください。');
    }

    // T16：変えられないときは選択を出さず、理由だけ
    public function test_t16_blocked_card(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->mkContract($eg1, $this->sesId(self::SES1), 'active');
        $this->as(self::ADMIN)->get("/engineers/$eg1/edit")->assertOk()->assertDontSee('/affiliation', false)->assertSee('稼働中の契約があるため、所属を変えられません。');
    }

    // T17：確認の文（選んだ会社の名前を入れる）が form に付いている
    public function test_t17_confirm_text(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->as(self::ADMIN)->get("/engineers/$eg1/edit")->assertOk()->assertSee('confirm(', false)->assertSee('」から「', false)->assertSee('見えなくなります', false);
    }

    // T18：本人の編集画面には、所属が読み取りだけで出る
    public function test_t18_self_readonly(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->as(self::EG1)->get("/engineers/$eg1/edit")->assertOk()->assertSee('所属：'.self::A)
            ->assertSee('所属の会社か管理者にご相談ください')->assertDontSee('/affiliation', false);
    }

    // T19：版
    public function test_t19_version(): void
    {
        $this->assertSame('v0.9.1', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.9.1');
    }
}
