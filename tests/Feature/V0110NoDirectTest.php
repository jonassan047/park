<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Billing;
use App\Services\CaseMail;
use App\Services\CaseThread;
use App\Services\DirectMail as DM;
use App\Services\DirectMerge;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\TestCase;

// v0.11.0 直属をやめ、管理者は案件を発信しない（PLAN.md 8章 M1〜M8・N1〜N10）。人・会社は見本データと、テストの中で作る架空のものだけ
// 会社：A＝SES1（みどり情報システム）、B＝SES2（あおぞらテック）、MRSY 役＝SES3（テストの中で名前を「合同会社MRSY」にする）、直属＝テストの中で作る kind='direct' の行
class V0110NoDirectTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const SES3 = 'ses3@park.example';

    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/merge-direct-*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/merge-direct-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    // ---------- 道具 ----------
    private function row(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->row($email)->ses_id;
    }

    private function admin(): object
    {
        return $this->row(self::ADMIN);
    }

    private function backups(): array
    {
        return array_values(array_diff(glob(storage_path('app/backups/merge-direct-*.json')) ?: [], $this->backupsBefore));
    }

    // 直属の行（古い形。テストの中でだけ作る）
    private function mkDirect(): int
    {
        return (int) DB::table('ses_companies')->insertGetId(['name' => '管理者の直属', 'kind' => 'direct', 'status' => 'active', 'code' => \App\Services\Ids::next('S'),
            'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    // 付け替え先の役：SES3 の名前を「合同会社MRSY」にして、担当者をもう1人足す
    private function mrsy(): int
    {
        $m = $this->sesId(self::SES3);
        DB::table('ses_companies')->where('id', $m)->update(['name' => DirectMerge::MRSY_NAME, 'status' => 'active']);
        $this->mkStaff($m, 'staff-m2@example.test', 'MRSY 担当二');
        return $m;
    }

    private function mkStaff(int $sid, string $email, string $name): int
    {
        $uid = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        return $uid;
    }

    // 本登録が済んだエンジニア（アカウントつき）
    private function mkEgIn(int $sid, string $name, string $email, ?string $initials = null): int
    {
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->admin(), false);
        DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()] + ($initials ? ['initials' => $initials] : []));
        $uid = W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
        $this->completeProfile($uid);
        return $id;
    }

    private function mkCase(?int $source, string $title = '架空の案件V0110', string $status = 'open'): int
    {
        $cid = (int) DB::table('cases')->insertGetId(['title' => $title, 'status' => $status, 'source_ses_id' => $source, 'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('cases')->where('id', $cid)->update(['code' => \App\Services\Ids::next('C')]);
        return $cid;
    }

    private function offer(int $cid, int $eid): void
    {
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $eid, 'score' => 50, 'sent_at' => now_str()]);
    }

    // M1 の土台：直属 D と元直属の EG D1・D2、D1 の応募・契約・やり取り・案内メール・検索・連絡メール、提案元 SESが空の案件。付け替え先 MRSY 役も作る
    private function world(): array
    {
        $d = $this->mkDirect();
        $m = $this->mrsy();
        $d1 = $this->mkEgIn($d, '元直属 一郎', 'd1@example.test', 'D.I');
        $d2 = $this->mkEgIn($d, '元直属 二郎', 'd2@example.test', 'D.J');
        $caseNull = $this->mkCase(null, '提案元 SES なしの案件');
        $caseDirect = $this->mkCase($d, '直属の案件');
        $app = W::apply($caseNull, $d1, ['start_ok' => 1], $this->admin());
        $k = W::makeContract($app, ['price' => 70, 'start_date' => date('Y-m-01')], $this->admin());
        $thread = (int) DB::table('case_threads')->insertGetId(['case_id' => $caseNull, 'engineer_id' => $d1, 'ses_id' => $d, 'partner_ses_id' => $d, 'last_post_at' => now_str(), 'created_at' => now_str()]);
        $mail = (int) DB::table('case_mails')->insertGetId(['case_id' => $caseDirect, 'engineer_id' => $d1, 'ses_id' => $d, 'sent_by' => $this->admin()->id, 'route' => 'own', 'mode' => 'same',
            'subject' => '件名', 'body' => '本文', 'sent_at' => now_str(), 'token' => bin2hex(random_bytes(10))]);
        $search = (int) DB::table('case_searches')->insertGetId(['case_id' => $caseDirect, 'created_by' => $this->admin()->id, 'ses_id' => $d, 'conditions' => '{"req":[],"pref":[]}', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $dm = (int) DB::table('direct_mails')->insertGetId(['send_key' => Str::random(32), 'from_user_id' => null, 'from_role' => 'eg', 'from_ses_id' => $d, 'from_label' => '元直属 一郎', 'subject' => '件名', 'body' => '本文', 'to_count' => 1, 'created_at' => now_str()]);
        return compact('d', 'm', 'd1', 'd2', 'caseNull', 'caseDirect', 'app', 'k', 'thread', 'mail', 'search', 'dm');
    }

    // ---------- M1〜M8：データの付け替え ----------
    public function test_m1_merge_moves_everything_to_mrsy(): void
    {
        $w = $this->world();
        $this->assertSame($w['d'], (int) DB::table('engineers')->find($w['d1'])->ses_id);
        $r = DirectMerge::run();
        $this->assertTrue($r['changed']);
        $this->assertSame($w['m'], $r['mrsy']);
        foreach ([$w['d1'], $w['d2']] as $e) {
            $this->assertSame($w['m'], (int) DB::table('engineers')->find($e)->ses_id);
            $this->assertSame($w['m'], (int) DB::table('users')->where('engineer_id', $e)->where('role', 'eg')->value('ses_id'));
        }
        $this->assertSame($w['m'], (int) DB::table('applications')->find($w['app'])->ses_id);
        $this->assertSame($w['m'], (int) DB::table('contracts')->find($w['k'])->ses_id);
        $t = DB::table('case_threads')->find($w['thread']);
        $this->assertSame([$w['m'], $w['m']], [(int) $t->ses_id, (int) $t->partner_ses_id]);
        $this->assertSame($w['m'], (int) DB::table('cases')->find($w['caseNull'])->source_ses_id);   // 提案元 SESが空だった案件
        $this->assertSame($w['m'], (int) DB::table('cases')->find($w['caseDirect'])->source_ses_id); // 直属が提案元 SES の案件
        $this->assertSame($w['m'], (int) DB::table('case_mails')->find($w['mail'])->ses_id);
        $this->assertSame($w['m'], (int) DB::table('case_searches')->find($w['search'])->ses_id);
        $this->assertSame($w['m'], (int) DB::table('direct_mails')->find($w['dm'])->from_ses_id);
        $this->assertSame(1, DB::table('ses_companies')->where('id', $w['d'])->where('kind', 'direct')->count()); // 直属の行は残る
        $this->assertSame(0, DirectMerge::references());
        $this->assertCount(1, $this->backups());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'direct-merge')->count());
        $this->assertStringNotContainsString('元直属', (string) DB::table('audit_log')->where('action', 'direct-merge')->value('detail')); // 名前は記録に書かない
        // 契約は、請求の対象外の印を持たない（列を足さない）
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasColumn('contracts', 'fee_exempt'));
    }

    public function test_m2_idempotent(): void
    {
        $this->world();
        DirectMerge::run();
        $snap = [DB::table('engineers')->pluck('ses_id', 'id')->all(), DB::table('cases')->pluck('source_ses_id', 'id')->all(), DB::table('app_notifications')->count()];
        $r = DirectMerge::run();
        $this->assertFalse($r['changed']);
        $this->assertSame($snap, [DB::table('engineers')->pluck('ses_id', 'id')->all(), DB::table('cases')->pluck('source_ses_id', 'id')->all(), DB::table('app_notifications')->count()]);
        $this->assertCount(1, $this->backups());
    }

    public function test_m3_restore(): void
    {
        $w = $this->world();
        $casesBefore = DB::table('cases')->pluck('source_ses_id', 'id')->all();
        $engBefore = DB::table('engineers')->pluck('ses_id', 'id')->all();
        DirectMerge::run();
        $this->assertTrue(DirectMerge::restore());
        $this->assertSame($engBefore, DB::table('engineers')->pluck('ses_id', 'id')->all());
        $this->assertSame($casesBefore, DB::table('cases')->pluck('source_ses_id', 'id')->all()); // 空だった提案元 SESは空に戻る
        $this->assertSame($w['d'], (int) DB::table('applications')->find($w['app'])->ses_id);
        $this->assertSame($w['d'], (int) DB::table('contracts')->find($w['k'])->ses_id);
        $this->assertSame($w['d'], (int) DB::table('case_threads')->find($w['thread'])->partner_ses_id);
        $this->assertSame($w['d'], (int) DB::table('direct_mails')->find($w['dm'])->from_ses_id);
    }

    public function test_m4_stops_without_a_proper_mrsy(): void
    {
        $w = $this->world();
        $snap = fn () => [DB::table('engineers')->pluck('ses_id', 'id')->all(), DB::table('cases')->pluck('source_ses_id', 'id')->all(), DB::table('contracts')->pluck('ses_id', 'id')->all()];
        $before = $snap();
        // なし
        DB::table('ses_companies')->where('id', $w['m'])->update(['name' => 'ほかの会社']);
        $this->assertStops();
        $this->assertSame($before, $snap());
        // 2つ
        DB::table('ses_companies')->where('id', $w['m'])->update(['name' => DirectMerge::MRSY_NAME]);
        DB::table('ses_companies')->where('id', $this->sesId(self::SES2))->update(['name' => '合同会社 MRSY']); // 空白のゆれも同じ会社とみなす
        $this->assertStops();
        $this->assertSame($before, $snap());
        // 参加中でない
        DB::table('ses_companies')->where('id', $this->sesId(self::SES2))->update(['name' => 'あおぞらテック']);
        DB::table('ses_companies')->where('id', $w['m'])->update(['status' => 'suspended']);
        $this->assertStops();
        $this->assertSame($before, $snap());
        $this->assertSame([], $this->backups());
    }

    private function assertStops(): void
    {
        try {
            DirectMerge::run();
            $this->fail('止まるはずが、止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('直属の付け替えを止めました', $e->getMessage());
        }
    }

    public function test_m5_stops_when_threads_would_collide(): void
    {
        $w = $this->world();
        // 同じ案件×エンジニアで、直属の1本と MRSY の1本の両方がある
        DB::table('case_threads')->insert(['case_id' => $w['caseNull'], 'engineer_id' => $w['d1'], 'ses_id' => $w['m'], 'partner_ses_id' => $w['m'], 'created_at' => now_str()]);
        $before = DB::table('case_threads')->get()->map(fn ($t) => [$t->id, $t->ses_id, $t->partner_ses_id])->all();
        $this->assertStops2();
        $this->assertSame($before, DB::table('case_threads')->get()->map(fn ($t) => [$t->id, $t->ses_id, $t->partner_ses_id])->all());
        $this->assertSame($w['d'], (int) DB::table('engineers')->find($w['d1'])->ses_id);
        $this->assertSame([], $this->backups());
    }

    private function assertStops2(): void
    {
        try {
            DirectMerge::run();
            $this->fail('止まるはずが、止まらなかった');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('止めました', $e->getMessage());
        }
    }

    public function test_m6_nothing_to_do_does_nothing(): void
    {
        DB::table('cases')->update(['source_ses_id' => $this->sesId(self::SES1)]);
        $before = [DB::table('engineers')->pluck('ses_id', 'id')->all(), DB::table('cases')->pluck('source_ses_id', 'id')->all()];
        $this->assertNotContains('合同会社MRSY', DB::table('ses_companies')->pluck('name')->all()); // MRSY 役がいなくても、例外にならない
        $r = DirectMerge::run();
        $this->assertFalse($r['changed']);
        $this->assertSame($before, [DB::table('engineers')->pluck('ses_id', 'id')->all(), DB::table('cases')->pluck('source_ses_id', 'id')->all()]);
        $this->assertSame([], $this->backups());
        $this->assertSame(0, DB::table('audit_log')->where('action', 'direct-merge')->count());
    }

    public function test_m7_notices_without_mail(): void
    {
        $w = $this->world();
        $mails = DB::table('mail_outbox')->count();
        DirectMerge::run();
        foreach (['d1@example.test', 'd2@example.test'] as $email) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->row($email)->id)->where('title', 'like', '所属の会社が「合同会社MRSY」になりました%')->count(), $email);
        }
        foreach ([self::SES3, 'staff-m2@example.test'] as $email) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->row($email)->id)->where('title', '管理者の直属だった 2 名のエンジニアが、貴社の所属になりました')->count(), $email);
        }
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame($w['m'], $this->sesId(self::SES3));
    }

    public function test_m8_billing_includes_moved_contracts(): void
    {
        $w = $this->world();
        DirectMerge::run();
        $inv = Billing::build($w['m'], date('Y-m'));
        $this->assertNotNull($inv);
        $items = json_decode($inv->items_json, true);
        $this->assertContains([$w['k'], 'initial'], array_map(fn ($i) => [$i['contract_id'], $i['type']], $items));
        $next = Billing::items($w['m'], date('Y-m', strtotime('first day of next month')));
        $this->assertContains([$w['k'], 'monthly'], array_map(fn ($i) => [$i['contract_id'], $i['type']], $next['items']));
        // 直属の id では、請求書は作られない（契約が無いため）
        $this->assertSame([], Billing::items($w['d'], date('Y-m'))['items']);
        $this->assertNull(Billing::build($w['d'], date('Y-m')));
    }

    // ---------- N1〜N4：案件の登録と、管理者は発信できない ----------
    public function test_n1_n2_admin_registers_for_a_ses(): void
    {
        $this->autoSource = false;
        $a = $this->sesId(self::SES1);
        $this->mkStaff($a, 'staff-a2@example.test', '担当 次郎');
        $n0 = DB::table('cases')->count();
        $b = $this->sesId(self::SES2);
        DB::table('ses_companies')->where('id', $b)->update(['status' => 'suspended']);
        $this->as(self::ADMIN, 'admin');
        $this->post('/cases', ['title' => 'N1 提案元なし'])->assertSessionHas('flash.msg', '提案元 SES を選んでください。');
        $this->post('/cases', ['title' => 'N1 提案元なし', 'source_ses_id' => ''])->assertSessionHas('flash.msg', '提案元 SES を選んでください。');
        $this->post('/cases', ['title' => 'N1 停止中の会社', 'source_ses_id' => $b])->assertSessionHas('flash.msg', '提案元 SES を選んでください。');
        $this->post('/cases', ['title' => 'N1 直属', 'source_ses_id' => $this->mkDirect()])->assertSessionHas('flash.msg', '提案元 SES を選んでください。');
        $this->assertSame($n0, DB::table('cases')->count());
        $this->post('/cases', ['title' => 'N1 A の案件', 'source_ses_id' => $a, 'status' => 'open'])->assertSessionHas('flash.msg', fn ($m) => str_contains($m, '案内は みどり情報システム の担当者が行います'));
        $c = DB::table('cases')->where('title', 'N1 A の案件')->first();
        $this->assertSame($a, (int) $c->source_ses_id);
        $this->assertSame((int) $this->admin()->id, (int) $c->registered_by);
        // N2：担当者全員にお知らせとメール。担当者の「今やること」に「新しい案件」
        foreach ([self::SES1, 'staff-a2@example.test'] as $email) {
            $uid = $this->row($email)->id;
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $uid)->where('title', 'like', '管理者が貴社の案件として登録しました：N1 A の案件%')->count(), $email);
            $this->assertSame(1, DB::table('mail_outbox')->where('to_email', $email)->where('subject', 'like', '%管理者が貴社の案件として登録しました：N1 A の案件%')->count(), $email);
        }
        $this->as(self::SES1, 'ses')->get('/ses')->assertOk()->assertSee('新しい案件「N1 A の案件」');
        // 提案元 SESの担当者が0人のときは、管理者にも知らせる
        DB::table('users')->where('ses_id', $this->sesId(self::SES3))->where('role', 'ses')->update(['active' => 0]);
        $this->as(self::ADMIN, 'admin')->post('/cases', ['title' => 'N2 担当者なし', 'source_ses_id' => $this->sesId(self::SES3), 'status' => 'open'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->admin()->id)->where('title', 'like', '案件を登録しました（担当者がいません）：N2 担当者なし%')->count());
    }

    // 登録の画面：管理者の選択は必須（空の選択肢は「選んでください」）
    public function test_n1_form_requires_source(): void
    {
        $this->as(self::ADMIN, 'admin')->get('/cases/new')->assertOk()->assertSee('提案元 SES（必須）')->assertSee('（選んでください）')->assertDontSee('（管理者が直接）');
    }

    private function sentCase(): array
    {
        $a = $this->sesId(self::SES1);
        $cid = $this->mkCase($a, '架空の案件N3');
        $eid = $this->mkEgIn($a, '自社 太郎', 'n3-eg@example.test', 'J.T');
        $this->offer($cid, $eid);
        CaseMail::send(User::find($this->row(self::SES1)->id), DB::table('cases')->find($cid), [$eid => ['subject' => '案件のご案内', 'body' => '架空の本文']], 'same');
        $mid = (int) DB::table('case_mails')->where('case_id', $cid)->value('id');
        $this->assertGreaterThan(0, $mid);
        return [$cid, $eid, $mid];
    }

    public function test_n3_admin_cannot_send(): void
    {
        [$cid, $eid, $mid] = $this->sentCase();
        // 提案元 SESの担当者が作った検索（管理者は開けない）
        $this->as(self::SES1, 'ses')->post("/cases/$cid/search", [])->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $cid)->value('id');
        $before = [DB::table('case_mails')->count(), DB::table('case_offers')->count(), DB::table('case_searches')->count()];
        $this->as(self::ADMIN, 'admin');
        $this->get("/cases/$cid/mail")->assertForbidden();
        $this->post("/cases/$cid/mail/preview", ['mode' => 'same', 'subject' => 'x', 'body' => 'y', 'engineer_id' => [$eid]])->assertForbidden();
        $this->post("/cases/$cid/mail/send", ['mode' => 'same', 'to' => [$eid => ['send' => 1, 'subject' => 'x', 'body' => 'y']]])->assertForbidden();
        $this->get("/cases/$cid/search")->assertForbidden();
        $this->post("/cases/$cid/search", [])->assertForbidden();
        $this->post("/cases/$cid/distribute", ['engineer_id' => [$eid]])->assertForbidden();
        $this->post("/case-mails/$mid/reply", ['reply' => 'interested'])->assertForbidden();
        $this->post("/case-mails/$mid/submitted")->assertForbidden();
        $this->get("/case-mails/$mid/doc/resume")->assertForbidden();
        $this->get("/searches/$sid")->assertForbidden();
        $this->post("/searches/$sid/send", ['pick' => [$eid]])->assertForbidden();
        $this->assertSame($before, [DB::table('case_mails')->count(), DB::table('case_offers')->count(), DB::table('case_searches')->count()]);
        $this->assertSame('none', DB::table('case_mails')->find($mid)->reply);
        // 管理者が前に作った検索（ses_id が空）は、だれも開けない
        $old = (int) DB::table('case_searches')->insertGetId(['case_id' => $cid, 'created_by' => $this->admin()->id, 'ses_id' => null, 'conditions' => '{"req":[],"pref":[]}', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->get("/searches/$old")->assertForbidden();
        $this->as(self::SES1, 'ses')->get("/searches/$old")->assertForbidden();
    }

    public function test_n4_admin_page_is_read_only(): void
    {
        [$cid, $eid, $mid] = $this->sentCase();
        $this->as(self::ADMIN, 'admin')->get("/cases/$cid")->assertOk()
            ->assertDontSee('エンジニアに案内する')->assertSee('案内は、提案元 SES（みどり情報システム）の担当者が行います。')
            ->assertSee('案内メール')->assertSee('自社 太郎')->assertDontSee("/case-mails/$mid/reply", false)->assertDontSee("/case-mails/$mid/doc/", false);
        // 提案元 SESの担当者には、今までどおり操作が出る
        $this->as(self::SES1, 'ses')->get("/cases/$cid")->assertOk()->assertSee('エンジニアに案内する')->assertSee("/case-mails/$mid/reply", false);
    }

    public function test_n5_ses_acts_on_cases_registered_by_admin(): void
    {
        $this->autoSource = false;
        $a = $this->sesId(self::SES1);
        $this->as(self::ADMIN, 'admin')->post('/cases', ['title' => 'N5 管理者の登録', 'source_ses_id' => $a, 'status' => 'open'])->assertSessionHas('flash.type', 'ok');
        $cid = (int) DB::table('cases')->where('title', 'N5 管理者の登録')->value('id');
        $eid = $this->mkEgIn($a, '自社 太郎', 'n5-eg@example.test');
        $this->as(self::SES1, 'ses')->get("/cases/$cid/mail")->assertOk();
        $this->get("/cases/$cid/edit")->assertOk();
        $this->assertSame(1, CaseMail::send(User::find($this->row(self::SES1)->id), DB::table('cases')->find($cid), [$eid => ['subject' => '件名', 'body' => '本文']], 'same'));
        $m = DB::table('case_mails')->where('case_id', $cid)->first();
        $this->assertSame($a, (int) $m->ses_id);
        $this->post("/case-mails/{$m->id}/reply", ['reply' => 'interested', 'note' => '単価は相談したい'])->assertRedirect();
        $this->assertSame('interested', DB::table('case_mails')->find($m->id)->reply);
        $this->get("/case-mails/{$m->id}/doc/resume")->assertOk();
    }

    // ---------- N6：やり取りは読むだけ ----------
    public function test_n6_admin_reads_threads_only(): void
    {
        $a = $this->sesId(self::SES1);
        $cid = $this->mkCase($a, '架空の案件N6');
        $eid = $this->mkEgIn($a, '自社 太郎', 'n6-eg@example.test');
        $this->offer($cid, $eid);
        $tid = (int) DB::table('case_threads')->insertGetId(['case_id' => $cid, 'engineer_id' => $eid, 'ses_id' => $a, 'partner_ses_id' => $a, 'last_post_at' => now_str(), 'created_at' => now_str()]);
        DB::table('case_thread_posts')->insert(['thread_id' => $tid, 'from_user_id' => null, 'from_side' => 'eg', 'from_label' => '自社 太郎', 'body' => 'N6の質問', 'read_by_eg' => 1, 'read_by_side' => 0, 'created_at' => now_str()]);
        $this->as(self::ADMIN, 'admin')->get("/cases/$cid/threads/$eid")->assertOk()->assertSee('N6の質問')->assertSee('管理者は、やり取りを読むだけです。');
        $this->assertSame(0, (int) DB::table('case_thread_posts')->where('thread_id', $tid)->value('read_by_side'));
        $this->post("/cases/$cid/threads/$eid", ['body' => '管理者から'])->assertSessionHas('flash.msg', '管理者は、やり取りを読むだけです。');
        $this->assertSame(1, DB::table('case_thread_posts')->where('thread_id', $tid)->count());
        $this->get('/admin')->assertOk()->assertDontSee('さんから案件の質問');
        $this->get('/threads')->assertOk()->assertSee('架空の案件N6');
    }

    // ---------- N7：直属の表示・行が増えない ----------
    public function test_n7_no_direct_on_screens(): void
    {
        $e = $this->mkEgIn($this->sesId(self::SES1), '自社 太郎', 'n7-eg@example.test');
        $this->as(self::ADMIN, 'admin');
        foreach (['/admin/engineers', '/admin/engineers?ses=direct', "/engineers/$e", "/engineers/$e/edit", '/admin/ses', '/cases/new', '/admin'] as $p) {
            $this->get($p)->assertOk()->assertDontSee('直属')->assertDontSee('管理者が直接');
        }
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count());
    }

    // ---------- N8：元直属の人の知らせは、MRSY の担当者へ ----------
    public function test_n8_moved_engineer_talks_to_mrsy(): void
    {
        $w = $this->world();
        DirectMerge::run();
        $d1User = User::find($this->row('d1@example.test')->id);
        $e = DB::table('engineers')->find($w['d1']);
        // world() では成約まで進めてあり、案件は募集を終えている（終えた案件は読むだけ）。ここは書ける場面を見たいので、選考中に戻す
        DB::table('cases')->where('id', $w['caseNull'])->update(['status' => 'open']);
        DB::table('applications')->where('id', $w['app'])->update(['status' => 'interview']);
        $c = DB::table('cases')->find($w['caseNull']); // いまは MRSY の案件。D1 は応募済み
        $this->assertSame($w['m'], CaseThread::partnerFor($c, $e));
        $res = CaseThread::post($d1User, $c, $e, '案件の質問です');
        $this->assertSame('ok', $res['type'], $res['msg'] ?? '');
        foreach ([self::SES3, 'staff-m2@example.test'] as $email) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->row($email)->id)->where('title', 'like', 'エンジニアから案件の質問が届きました：提案元 SES なしの案件%')->count(), $email);
        }
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->admin()->id)->where('title', 'like', 'エンジニアから案件の質問が届きました%')->count());
        // 連絡メール：宛先は MRSY の担当者（管理者ではない）
        $targets = array_keys(DM::staffTargets($d1User));
        $this->assertContains((int) $this->row(self::SES3)->id, $targets);
        $this->assertNotContains((int) $this->admin()->id, $targets);
        $this->assertSame(1, DM::send($d1User, [(int) $this->row(self::SES3)->id], '件名', '本文', Str::random(32))['sent']);
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->admin()->id)->where('link', 'like', '/mails/%')->count());
        // 応募の知らせ（応募の取り下げ）も MRSY の担当者へ
        W::setStatus($w['app'], 'rejected', $this->admin(), '他の候補者で決定', '');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->row(self::SES3)->id)->where('title', 'like', '%見送り：提案元 SES なしの案件%')->count());
    }

    // ---------- N9：他社には「イニシャル（会社名）」 ----------
    public function test_n9_other_company_sees_initials_with_mrsy_name(): void
    {
        $w = $this->world();
        DirectMerge::run();
        $viewer = (object) ['role' => 'ses', 'ses_id' => $this->sesId(self::SES1)];
        $e = DB::table('engineers')->find($w['d1']);
        $this->assertSame('D.I（合同会社MRSY）', CaseThread::egName($viewer, $e));
        // 検索の候補（他社の人）：氏名・EG- は出ない
        $c = DB::table('cases')->find($this->mkCase($this->sesId(self::SES1), '架空の案件N9'));
        $m = collect(\App\Services\CaseSearch::pool($this->row(self::SES1), $c))->first(fn ($x) => (int) $x['engineer']->id === $w['d1']);
        $this->assertNotNull($m, '元直属の人が、他社の検索の候補に出る');
        $this->assertFalse($m['own']);
    }

    // ---------- N10：提案元 SESが空の案件は、管理者が一度だけ選べる ----------
    public function test_n10_edit_source_once(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $cid = $this->mkCase(null, 'N10 提案先なし');
        $this->as(self::ADMIN, 'admin')->get("/cases/$cid/edit")->assertOk()->assertSee('提案元 SES（必須）');
        $this->post("/cases/$cid", ['title' => 'N10 提案先なし', 'status' => 'open', 'source_ses_id' => $a])->assertRedirect();
        $this->assertSame($a, (int) DB::table('cases')->find($cid)->source_ses_id);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->row(self::SES1)->id)->where('title', 'like', '管理者が貴社の案件として登録しました：N10 提案先なし%')->count());
        // 2回目：選ぶ欄が無く、送っても変わらない
        $this->get("/cases/$cid/edit")->assertOk()->assertDontSee('提案元 SES');
        $this->post("/cases/$cid", ['title' => 'N10 提案先なし', 'status' => 'open', 'source_ses_id' => $b])->assertRedirect();
        $this->assertSame($a, (int) DB::table('cases')->find($cid)->source_ses_id);
    }

    // L8：直属が2行あり、同じ案件×エンジニアの1本が両方にあるときも、付け替えると重なるので、何も変えずに止まる
    public function test_l8_two_direct_rows_with_colliding_threads_stop(): void
    {
        $w = $this->world();
        $d2 = $this->mkDirect();
        DB::table('case_threads')->insert(['case_id' => $w['caseNull'], 'engineer_id' => $w['d1'], 'ses_id' => $d2, 'partner_ses_id' => $d2, 'created_at' => now_str()]);
        $this->assertStops2();
        $this->assertSame($w['d'], (int) DB::table('engineers')->find($w['d1'])->ses_id);
        $this->assertSame(2, DB::table('case_threads')->where('case_id', $w['caseNull'])->where('engineer_id', $w['d1'])->count());
        $this->assertSame([], $this->backups());
    }

    // H2：role=admin で ses_id がある行は、変えずに数だけ知らせる（status と付け替えの結果に出る）
    public function test_h2_admins_with_ses_id_are_counted_not_changed(): void
    {
        $w = $this->world();
        $a = $this->sesId(self::SES1);
        DB::table('users')->where('email', self::ADMIN)->update(['ses_id' => $a]);
        $this->assertSame(1, DirectMerge::adminsWithSes());
        $this->assertSame(1, DirectMerge::summary()['adminsWithSes']);
        \Illuminate\Support\Facades\Artisan::call('park:status', ['--json' => true]);
        $j = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $key = collect(array_keys($j))->first(fn ($k) => str_contains((string) $k, '管理者で ses_id がある行'));
        $this->assertNotNull($key);
        $this->assertSame(1, $j[$key]);
        $r = DirectMerge::run();
        $this->assertSame(1, $r['adminsWithSes']);
        $this->assertSame($a, (int) $this->admin()->ses_id); // 書き換えない
        $this->assertStringContainsString('管理者で ses_id あり（変えない）：1', (string) DB::table('audit_log')->where('action', 'direct-merge')->value('detail'));
    }

    // ---------- 追加：古い直属の行が残っていても、増えない・開けない ----------
    public function test_extra_old_direct_row_is_ignored(): void
    {
        $d = $this->mkDirect();
        $this->as(self::ADMIN, 'admin');
        $this->get("/admin/ses/$d")->assertNotFound();
        $this->post("/admin/ses/$d/staff-dual")->assertNotFound();
        $e = $this->mkEgIn($this->sesId(self::SES1), '自社 太郎', 'x-eg@example.test');
        $this->assertSame(1, DB::table('ses_companies')->where('kind', 'direct')->count());
        $this->post("/engineers/$e/affiliation", ['ses_id' => $d])->assertSessionHas('flash.msg', '選べない会社です。');
    }
}
