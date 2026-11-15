<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseSearch;
use App\Services\DirectMail as DM;
use App\Services\Notifier as N;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.11.0 管理者が、1つの会社の担当者を兼ねる（PLAN-v0110-dual.md 1章 X1〜X15）。人・会社は見本データと、テストの中で作る架空のものだけ
// 会社：A＝SES1（みどり情報システム）、B＝SES2（あおぞらテック）。管理者：P＝見本の管理者（EG の行なし）、Q＝EG の行もある管理者、R＝ほかの管理者
class V0110AdminStaffDualTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const P = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';

    // ---------- 道具 ----------
    private function row(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->row($email)->ses_id;
    }

    private function mkAdmin(string $email, string $name): int
    {
        $id = W::createUser($email, $name, 'admin', 'park1234');
        $this->completeProfile($id, ['company_name' => 'テスト運営']);
        return $id;
    }

    private function mkStaff(int $sesId, string $email, string $name): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sesId]);
        $this->completeProfile($id);
        return $id;
    }

    // 管理者 Q：エンジニアの行も持つ（管理者＋エンジニア）
    private function mkQ(): int
    {
        $qid = $this->mkAdmin('adm-q@example.test', '管理 Q');
        $eid = W::createEngineer($this->sesId(self::SES1), ['name' => 'Q 本人', 'email' => 'adm-q-eg@example.test'], $this->row(self::P), false);
        W::linkEngineer($qid, $eid, $this->row(self::P));
        return $qid;
    }

    // 本登録が済んだ形のエンジニア（必須 Java・SQL に合う）
    private function mkEng(int $sesId, string $name, string $initials): int
    {
        $id = W::createEngineer($sesId, ['name' => $name, 'kana' => 'てすと たろう', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->row(self::P), false);
        DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str(), 'initials' => $initials,
            'status' => 'available', 'email' => "eg{$id}@example.test"]);
        SkillChecks::saveFromNames($id, [['Java', 'lang', 12], ['SQL', 'db', 12]], 'seed');
        return $id;
    }

    // A が持ち込んだ募集中の案件
    private function mkCaseOfA(string $title = '架空の案件X'): int
    {
        return W::saveCase(['title' => $title, 'price' => 60, 'required_skills' => 'Java、SQL', 'status' => 'open', 'description' => '架空の作業内容', 'raw_text' => "架空のメール\n■必須\n・Java\n・SQL"], $this->row(self::SES1));
    }

    // 管理者が、会社の担当者を兼ね始める。$ready が true のときは、担当者の立場で入れるよう、担当者に要る項目（電話・守秘義務への同意）を先にそろえておく
    private function start(string $as, int $sesId, bool $ready = true)
    {
        if ($ready) {
            DB::table('users')->where('email', $as)->whereNull('phone')->update(['phone' => '03-0000-0009']);
            DB::table('users')->where('email', $as)->update(['secrecy_agreed_at' => now_str()]);
        }
        return $this->as($as, 'admin')->post("/admin/ses/$sesId/staff-dual");
    }

    // ---------- X1〜X4：兼ねる・やめる ----------
    public function test_x1_start(): void
    {
        $a = $this->sesId(self::SES1);
        $a2 = $this->mkStaff($a, 'staff-a2@example.test', '担当 次郎');
        $this->start(self::P, $a)->assertRedirect("/admin/ses/$a")->assertSessionHas('flash.type', 'ok');
        $p = $this->row(self::P);
        $this->assertSame($a, (int) $p->ses_id);
        $this->assertNotNull($p->staff_code);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'admin-staff-dual')->where('detail', "会社 #{$a} 開始")->count());
        foreach ([(int) $this->row(self::SES1)->id, $a2] as $uid) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $uid)->where('title', '管理者が、貴社の担当者を兼ねるようになりました')->where('for_role', 'ses')->count());
        }
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', self::SES1)->where('subject', 'like', '%管理者が、貴社の担当者を兼ねるようになりました%')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'staff-a2@example.test')->where('subject', 'like', '%管理者が、貴社の担当者を兼ねるようになりました%')->count());
        // 本人には知らせない
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $p->id)->where('title', '管理者が、貴社の担当者を兼ねるようになりました')->count());
        // もう一度押しても何も変わらない
        $this->start(self::P, $a)->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'admin-staff-dual')->count());
    }

    public function test_x2_only_one_company(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $this->start(self::P, $a);
        $this->start(self::P, $b)->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', fn ($m) => str_contains($m, '兼ねられるのは1社だけです'));
        $this->assertSame($a, (int) $this->row(self::P)->ses_id);
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->row(self::SES2)->id)->where('title', 'like', '管理者が、貴社の担当者を%')->count());
    }

    public function test_x3_cannot_start_in_unusable_company(): void
    {
        $b = $this->sesId(self::SES2);
        DB::table('ses_companies')->where('id', $b)->update(['status' => 'suspended']);
        $this->start(self::P, $b)->assertSessionHas('flash.type', 'bad');
        $pending = (int) DB::table('ses_companies')->where('status', 'pending')->value('id');
        $this->start(self::P, $pending)->assertSessionHas('flash.type', 'bad');
        $direct = (int) DB::table('ses_companies')->insertGetId(['name' => '古い直属', 'kind' => 'direct', 'status' => 'active', 'code' => \App\Services\Ids::next('S'),
            'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->start(self::P, $direct)->assertNotFound();
        $this->assertNull($this->row(self::P)->ses_id);
        $this->assertSame(0, DB::table('audit_log')->where('action', 'admin-staff-dual')->count());
    }

    public function test_x4_only_myself(): void
    {
        $a = $this->sesId(self::SES1);
        $r = $this->mkAdmin('adm-r@example.test', '管理 R');
        $this->as(self::P, 'admin')->post("/admin/ses/$a/staff-dual", ['user_id' => $r, 'id' => $r, 'uid' => $r])->assertSessionHas('flash.type', 'ok');
        $this->assertNull(DB::table('users')->find($r)->ses_id);
        $this->assertSame($a, (int) $this->row(self::P)->ses_id);
        // ほかの管理者がやめる操作をしても、P の行は変わらない
        $this->as('adm-r@example.test', 'admin')->post("/admin/ses/$a/staff-dual/stop")->assertSessionHas('flash.type', 'ok');
        $this->assertSame($a, (int) $this->row(self::P)->ses_id);
    }

    // ---------- X5：立場を選ぶ ----------
    public function test_x5_choose_role(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $this->assertSame(['admin', 'ses'], W::rolesOf($this->row(self::P)));
        $this->as(self::P)->get('/admin')->assertRedirect('/login/role');
        $this->get('/login/role')->assertOk()->assertSee('SES 担当者（みどり情報システム）')->assertSee('value="admin"', false)->assertSee('value="ses"', false)->assertDontSee('value="eg"', false);
        $this->post('/login/role', ['to' => 'ses'])->assertRedirect('/ses');

        $q = $this->mkQ();
        $this->start('adm-q@example.test', $a);
        $this->assertSame(['admin', 'ses', 'eg'], W::rolesOf($this->row('adm-q@example.test')));
        $this->as('adm-q@example.test')->get('/login/role')->assertOk()->assertSee('SES 担当者（みどり情報システム）')
            ->assertSee('value="admin"', false)->assertSee('value="ses"', false)->assertSee('value="eg"', false);
        $this->assertSame($q, (int) $this->row('adm-q@example.test')->id);
    }

    // ---------- X6〜X8：発信は担当者の立場だけ ----------
    public function test_x6_can_send_as_staff(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $cid = $this->mkCaseOfA();
        $eid = $this->mkEng($a, '自社 太郎', 'J.T');
        $this->as(self::P, 'ses')->get("/cases/$cid/mail")->assertOk();
        $this->post("/cases/$cid/search", [])->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $cid)->value('id');
        $this->assertSame($a, (int) DB::table('case_searches')->find($sid)->ses_id);
        $this->post("/searches/$sid/send", ['pick' => [$eid]])->assertRedirect();
        $m = DB::table('case_mails')->where('case_id', $cid)->where('engineer_id', $eid)->first();
        $this->assertNotNull($m);
        $this->assertSame($a, (int) $m->ses_id);
        $this->assertSame((int) $this->row(self::P)->id, (int) $m->sent_by);
        // 差出人：会社の担当者として「会社名 氏名」（ふつうの担当者と同じ表示。管理者の差出人名は付かない）
        $this->assertStringContainsString('みどり情報システム 管理者 太郎', (string) DB::table('mail_outbox')->where('id', $m->outbox_id)->value('body'));
        $this->assertNull(DB::table('mail_outbox')->where('id', $m->outbox_id)->value('from_name'));
    }

    // K10：担当者の立場で初めて入ったとき、足りない項目（電話・守秘義務への同意）は、足りない項目の画面で入れられる（管理者の行でも保存できる）
    public function test_k10_missing_items_screen_works_for_admin_row(): void
    {
        $a = $this->sesId(self::SES1);
        // 電話は管理者の立場でも要る（同じ列）ので、足りなくなるのは守秘義務への同意だけ。管理者として兼任を始めてから、同意を外す
        $this->start(self::P, $a);
        DB::table('users')->where('email', self::P)->update(['secrecy_agreed_at' => null]);
        $this->as(self::P, 'ses')->get('/ses')->assertRedirect('/register/complete');
        $this->get('/register/complete')->assertOk()->assertSee('守秘義務');
        $this->post('/register/complete', ['phone' => '03-0000-0001', 'agree' => '1'])->assertRedirect();
        $p = $this->row(self::P);
        $this->assertSame('03-0000-0001', $p->phone);
        $this->assertNotNull($p->secrecy_agreed_at);
        $this->assertSame('admin', $p->role); // 立場は変わらない（DB の role は管理者のまま）
        $this->get('/ses')->assertOk();
        $this->assertNotEmpty($p->company_name); // 管理者の項目（企業名）は、担当者の立場の保存で消えない
    }

    public function test_x7_cannot_send_as_admin(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $cid = $this->mkCaseOfA();
        $eid = $this->mkEng($a, '自社 太郎', 'J.T');
        $before = [DB::table('case_mails')->count(), DB::table('case_searches')->count(), DB::table('case_offers')->count()];
        $this->as(self::P, 'admin')->get("/cases/$cid/mail")->assertForbidden();
        $this->get("/cases/$cid/search")->assertForbidden();
        $this->post("/cases/$cid/search", [])->assertForbidden();
        $this->post("/cases/$cid/mail/send", ['mode' => 'same', 'to' => [$eid => ['send' => 1, 'subject' => '件名', 'body' => '本文']]])->assertForbidden();
        $this->post("/cases/$cid/distribute", ['engineer_id' => [$eid]])->assertForbidden();
        $this->assertSame($before, [DB::table('case_mails')->count(), DB::table('case_searches')->count(), DB::table('case_offers')->count()]);
    }

    public function test_x8_screens_split_by_role(): void
    {
        $this->start(self::P, $this->sesId(self::SES1));
        $this->as(self::P, 'ses')->get('/admin')->assertForbidden();
        $this->get('/admin/engineers')->assertForbidden();
        $this->as(self::P, 'admin')->get('/ses')->assertForbidden();
        $this->get('/ses/company')->assertForbidden();
    }

    // ---------- X9：知らせ ----------
    public function test_x9_notifications_are_split_by_role(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $pid = (int) $this->row(self::P)->id;
        N::toSes($a, 'X9の担当者あてのお知らせ', '本文X9', '/ses', true);
        $n = DB::table('app_notifications')->where('user_id', $pid)->where('title', 'X9の担当者あてのお知らせ')->first();
        $this->assertNotNull($n);
        $this->assertSame('ses', $n->for_role);
        $mail = DB::table('mail_outbox')->where('to_email', self::P)->where('subject', 'like', '%X9の担当者あてのお知らせ%')->first();
        $this->assertNotNull($mail);
        $this->assertStringStartsWith('【SES 担当者あて】', $mail->body);
        $this->as(self::P, 'admin')->get('/notifications')->assertOk()->assertDontSee('X9の担当者あてのお知らせ');
        $this->as(self::P, 'ses')->get('/notifications')->assertOk()->assertSee('X9の担当者あてのお知らせ');
    }

    // ---------- X10：会社の画面に出る・止められない ----------
    public function test_x10_listed_but_cannot_be_stopped(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $pid = (int) $this->row(self::P)->id;
        $page = $this->as(self::SES1, 'ses')->get('/ses/company')->assertOk()->assertSee('管理者（兼任）');
        foreach (['suspend', 'resume', 'delete'] as $x) $page->assertDontSee("/admin/ses/$a/users/$pid/$x");
        foreach (['suspend', 'resume', 'delete'] as $x) $this->post("/admin/ses/$a/users/$pid/$x")->assertNotFound();
        $this->assertSame(1, (int) DB::table('users')->where('id', $pid)->value('active'));
    }

    public function test_x10b_admin_screen_and_rep_candidates(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $pid = (int) $this->row(self::P)->id;
        $this->mkStaff($a, 'staff-a2@example.test', '担当 次郎');
        $r = $this->as(self::P, 'admin')->get("/admin/ses/$a")->assertOk()->assertSee('管理者（兼任）')->assertSee('兼任をやめる');
        foreach (['suspend', 'resume', 'delete'] as $x) $r->assertDontSee("/admin/ses/$a/users/$pid/$x");
        $this->assertFalse($r->viewData('repCandidates')->contains(fn ($u) => (int) $u->id === $pid));
        $this->post("/admin/ses/$a/users/$pid/suspend")->assertNotFound();
        $this->assertSame(1, (int) DB::table('users')->where('id', $pid)->value('active'));
        // 会社の「使える担当者の最後の1人」の数えにも入らない：担当者が兼任の管理者だけになっても、会社ごとの数えは role=ses だけ
        $this->assertSame(1 + 1, DB::table('users')->where('ses_id', $a)->where('role', 'ses')->count());
    }

    // L1・L2：EG→兼任の管理者の連絡メール。お知らせは担当者の立場あて、表示は「会社名 氏名」（管理者の企業名ではない）
    public function test_l1_l2_direct_mail_to_dual_admin(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $pid = (int) $this->row(self::P)->id;
        DB::table('users')->where('id', $pid)->update(['company_name' => '管理者の企業名XYZ']);
        $eg = $this->row(self::EG1);
        $res = DM::send(User::find($eg->id), [$pid], '架空の件名', '本文', \Illuminate\Support\Str::random(32));
        $this->assertSame(1, $res['sent']);
        $n = DB::table('app_notifications')->where('user_id', $pid)->where('link', '/mails/'.$res['ok'])->first();
        $this->assertNotNull($n);
        $this->assertSame('ses', $n->for_role); // 担当者の立場あて
        $this->assertSame('ses', DB::table('direct_mail_recipients')->where('direct_mail_id', $res['ok'])->value('to_role'));
        $label = (string) DB::table('direct_mail_recipients')->where('direct_mail_id', $res['ok'])->value('to_label');
        $this->assertSame('みどり情報システム 管理者 太郎', $label);
        $this->assertStringNotContainsString('管理者の企業名XYZ', $label);
        $mail = DB::table('mail_outbox')->where('to_email', self::P)->where('kind', 'direct-mail')->first();
        $this->assertStringStartsWith('みどり情報システム 管理者 太郎 様', $mail->body);
        // 管理者の立場の一覧には出ず、担当者の立場には出る
        $this->as(self::P, 'admin')->get('/notifications')->assertDontSee('から連絡メールが届きました');
        $this->as(self::P, 'ses')->get('/notifications')->assertSee('から連絡メールが届きました');
        // 作成画面の宛先の名前も「会社名 氏名（管理者兼任）」
        $this->assertSame('みどり情報システム 管理者 太郎（管理者兼任）', DM::staffLabels($this->row(self::EG1), DM::staffTargets($this->row(self::EG1)))[$pid]);
    }

    // L3：担当者の一覧では、兼任の管理者のメールアドレスとエンジニア兼任のリンクを出さない
    public function test_l3_company_list_hides_admin_email(): void
    {
        $a = $this->sesId(self::SES1);
        $this->mkQ();
        $this->start('adm-q@example.test', $a);
        $this->as(self::SES1, 'ses')->get('/ses/company')->assertOk()->assertSee('管理者（兼任）')->assertDontSee('adm-q@example.test')->assertDontSee('エンジニア兼任');
        // 会社の画面のエンジニアの表・エンジニアの一覧・詳細にも、兼任の管理者のメールアドレスは出ない
        $eid = (int) DB::table('users')->where('email', 'adm-q@example.test')->value('engineer_id');
        $this->get('/ses/engineers')->assertOk()->assertDontSee('adm-q@example.test');
        $this->get("/engineers/$eid")->assertOk()->assertDontSee('adm-q@example.test');
    }

    // H1：案内メールの記録の route・ses_id が空でも、他社のエンジニアの分は reply・submitted できない（エンジニアの会社で決める）
    public function test_h1_other_company_engineer_mail_row_is_untouchable(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $cid = $this->mkCaseOfA('H1 案件');
        $eb = $this->mkEng($b, '他社 太郎', 'T.T');
        // 管理者が送った古い記録（ses_id が空・route が own）
        $mid = (int) DB::table('case_mails')->insertGetId(['case_id' => $cid, 'engineer_id' => $eb, 'ses_id' => null, 'sent_by' => $this->row(self::P)->id, 'route' => 'own', 'mode' => 'same',
            'subject' => '件名', 'body' => '本文', 'sent_at' => now_str(), 'token' => bin2hex(random_bytes(10)), 'reply' => 'interested']);
        $this->as(self::SES1, 'ses');
        $this->post("/case-mails/$mid/reply", ['reply' => 'declined'])->assertForbidden();
        $this->post("/case-mails/$mid/submitted")->assertForbidden();
        $this->assertSame('interested', DB::table('case_mails')->find($mid)->reply);
        $this->assertSame(0, DB::table('applications')->where('case_id', $cid)->where('engineer_id', $eb)->count());
        // 今やること：他社の人は氏名ではなくイニシャル
        $this->get('/ses')->assertOk()->assertSee('T.T さんが「応募したい」')->assertDontSee('他社 太郎');
        // 自社の人は、今までどおり記録できる
        $ea = $this->mkEng($a, '自社 太郎', 'J.T');
        $mid2 = (int) DB::table('case_mails')->insertGetId(['case_id' => $cid, 'engineer_id' => $ea, 'ses_id' => null, 'sent_by' => $this->row(self::P)->id, 'route' => 'group', 'mode' => 'same',
            'subject' => '件名', 'body' => '本文', 'sent_at' => now_str(), 'token' => bin2hex(random_bytes(10))]);
        $this->post("/case-mails/$mid2/reply", ['reply' => 'interested'])->assertRedirect();
        $this->assertSame('interested', DB::table('case_mails')->find($mid2)->reply);
    }

    // L6：会社に担当者が自分しかいない（兼任の管理者が書いた本人）ときは、管理者に知らせる
    public function test_l6_only_self_as_staff_notifies_admins(): void
    {
        $a = $this->sesId(self::SES1);
        $r = $this->mkAdmin('adm-r@example.test', '管理 R');
        $cid = $this->mkCaseOfA('L6 案件');
        // 会社の担当者は SES1 の人だけ。その人がエンジニアも兼ねていて、自分で質問を書く
        $eid = $this->mkEng($a, '自社 太郎', 'J.T');
        DB::table('users')->where('email', self::SES1)->update(['engineer_id' => $eid]);
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $eid, 'score' => 50, 'sent_at' => now_str()]);
        $self = User::where('email', self::SES1)->first();
        $self->role = 'eg'; // エンジニアの立場で書く
        $before = DB::table('app_notifications')->where('user_id', $r)->where('title', 'like', 'エンジニアから案件の質問が届きました%')->count();
        $res = \App\Services\CaseThread::post($self, DB::table('cases')->find($cid), DB::table('engineers')->find($eid), 'L6の質問');
        $this->assertSame('ok', $res['type'], $res['msg'] ?? '');
        // 自分しか担当者がいないので、自分には届かず、管理者に届く
        $this->assertSame($before + 1, DB::table('app_notifications')->where('user_id', $r)->where('title', 'like', 'エンジニアから案件の質問が届きました%')->count());
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $self->id)->where('title', 'like', 'エンジニアから案件の質問が届きました%')->count());
    }

    // ---------- X11：会社が止まると入れない ----------
    public function test_x11_company_stopped(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $this->mkQ();
        $this->start('adm-q@example.test', $a);
        DB::table('ses_companies')->where('id', $a)->update(['status' => 'suspended']);
        $this->as(self::P, 'ses')->get('/ses')->assertRedirect('/login');
        $this->assertGuest();
        $this->assertSame(['admin'], W::usableRoles($this->row(self::P)));
        $this->assertSame(['admin', 'eg'], W::usableRoles($this->row('adm-q@example.test')));
        // 管理者の立場では、そのまま入れる
        $this->as(self::P, 'admin')->get('/admin')->assertOk();
    }

    // ---------- X12：やめられる ----------
    public function test_x12_stop(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $code = $this->row(self::P)->staff_code;
        $this->as(self::P, 'admin')->post("/admin/ses/$a/staff-dual/stop")->assertRedirect("/admin/ses/$a")->assertSessionHas('flash.type', 'ok');
        $p = $this->row(self::P);
        $this->assertNull($p->ses_id);
        $this->assertSame($code, $p->staff_code); // 担当者の ID は残す
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->row(self::SES1)->id)->where('title', '管理者が、貴社の担当者の兼任をやめました')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'admin-staff-dual')->where('detail', "会社 #{$a} 終了")->count());
        // 担当者の立場で入っていた人は、次の画面でログアウト
        $this->as(self::P, 'ses')->get('/ses')->assertRedirect('/login');
        $this->assertGuest();
        // 兼ねていないときのやめる操作は、何も変えない
        $this->as(self::P, 'admin')->post("/admin/ses/$a/staff-dual/stop")->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('audit_log')->where('action', 'admin-staff-dual')->where('detail', "会社 #{$a} 終了")->count());
    }

    // ---------- X13：連絡メールの宛先 ----------
    public function test_x13_direct_mail_recipients(): void
    {
        $a = $this->sesId(self::SES1);
        $pid = (int) $this->row(self::P)->id;
        $this->assertNotContains($pid, array_keys(DM::staffTargets($this->row(self::EG1))));
        $this->start(self::P, $a);
        $this->assertContains($pid, array_keys(DM::staffTargets($this->row(self::EG1))));
        $this->as(self::EG1)->get('/mails/new')->assertOk()->assertSee('（管理者兼任）');
        // ほかの会社のエンジニアには出ない
        $this->assertNotContains($pid, array_keys(DM::staffTargets($this->row('eg2@park.example'))));
    }

    // ---------- X14：管理者の立場で ses_id が混ざらない ----------
    public function test_x14_admin_view_is_not_narrowed_to_company(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $caseOfB = W::saveCase(['title' => '架空のB社の案件', 'price' => 60, 'status' => 'open'], $this->row(self::SES2));
        $before = $this->as(self::P, 'admin')->get('/admin/engineers')->assertOk()->getContent();
        $this->start(self::P, $a);
        $after = $this->as(self::P, 'admin')->get('/admin/engineers')->assertOk()->assertSee('山田 太郎')->assertSee('佐藤 美咲')->getContent();
        $this->assertSame(substr_count($before, '/engineers/'), substr_count($after, '/engineers/'));
        $this->get("/cases/$caseOfB")->assertOk()->assertSee('架空のB社の案件');
        $this->get('/threads')->assertOk();
        $this->get('/admin/cases?status=all')->assertOk()->assertSee('架空のB社の案件');
        $this->assertSame($b, (int) DB::table('cases')->find($caseOfB)->source_ses_id);
    }

    // ---------- X15：記録の印 ----------
    public function test_x15_audit_mark(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        $cid = $this->mkCaseOfA();
        $eid = $this->mkEng($a, '自社 太郎', 'J.T');
        $this->as(self::P, 'ses')->post("/cases/$cid/search", [])->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $cid)->value('id');
        $this->post("/searches/$sid/send", ['pick' => [$eid]])->assertRedirect();
        $detail = (string) DB::table('audit_log')->where('action', 'case-mail')->orderByDesc('id')->value('detail');
        $this->assertStringStartsWith('［管理者の兼任］', $detail);
        // ふつうの担当者の記録には付かない
        $eid2 = $this->mkEng($a, '自社 二郎', 'J.J');
        $this->as(self::SES1, 'ses')->post("/cases/$cid/search", [])->assertRedirect();
        $sid2 = (int) DB::table('case_searches')->where('case_id', $cid)->where('created_by', $this->row(self::SES1)->id)->value('id');
        $this->post("/searches/$sid2/send", ['pick' => [$eid2]])->assertRedirect();
        $d2 = (string) DB::table('audit_log')->where('action', 'case-mail')->orderByDesc('id')->value('detail');
        $this->assertStringNotContainsString('［管理者の兼任］', $d2);
    }

    // ---------- 追加：数え・ID・一覧 ----------
    public function test_extra_status_ids_and_counts(): void
    {
        $a = $this->sesId(self::SES1);
        $this->start(self::P, $a);
        Artisan::call('park:status', ['--json' => true]);
        $j = json_decode(Artisan::output(), true);
        $this->assertSame(1, $j['兼任（管理者＋SES 担当者）']);
        $code = (string) $this->row(self::P)->staff_code;
        $this->assertMatchesRegularExpression('/^ST-\d{6}$/', $code);
        // ID で探せる（管理者は管理者の詳細へ）
        $this->as(self::P, 'admin')->get('/find?code='.urlencode($code))->assertRedirect(url('/admin/admins/'.$this->row(self::P)->id));
        // SES の代表・次の SES の候補に、兼任の管理者は入らない（会社の人数にも混ぜない）
        $this->assertSame(0, DB::table('ses_companies')->where('rep_user_id', $this->row(self::P)->id)->count());
    }
}
