<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseMail;
use App\Services\Registration;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.10.0 案件ごとのやり取り（PLAN.md 7章 T1〜T25）。人・会社は見本データと、テストの中で作る架空のものだけ
// 会社：A＝SES1（みどり情報システム。担当者 SES1 と A2）、B＝SES2（あおぞらテック。案件を持ち込む側）、直属＝管理者
// エンジニア：EG1＝A、EG1B＝A（同じ会社のほかの人）、EG2＝B、DEG＝直属
class V0100CaseThreadTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private array $backupsBefore = [];

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const A2 = 'staff-a2@example.test';
    private const EG1 = 'eg1@park.example';
    private const EG2 = 'eg2@park.example';
    private const A = 'みどり情報システム';
    private const B = 'あおぞらテック';

    private int $caseId;
    private int $eg1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
        DB::table('applications')->update(['status' => 'rejected']);
        DB::table('contracts')->update(['status' => 'ended']);
        $this->eg1 = $this->engId(self::EG1);
        // 案件は B が持ち込んだ募集中のもの。EG1 には届いている
        $this->caseId = $this->mkCase($this->sesId(self::SES2));
        $this->offer($this->caseId, $this->eg1);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    // ---------- 道具 ----------
    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    private function mkCase(?int $source = null, string $status = 'open', string $title = '架空の案件V0100'): int
    {
        $cid = (int) DB::table('cases')->insertGetId(['title' => $title, 'status' => $status, 'source_ses_id' => $source, 'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('cases')->where('id', $cid)->update(['code' => \App\Services\Ids::next('C')]);
        return $cid;
    }

    private function offer(int $cid, int $eid): void
    {
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $eid, 'score' => 50, 'sent_at' => now_str()]);
    }

    private function mkEgIn(int $sid, string $name, string $email): int
    {
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], User::find($this->user(self::ADMIN)->id), false);
        DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        $uid = W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
        $this->completeProfile($uid);
        return $id;
    }

    private function mkStaff(int $sid, string $email, string $name, ?int $engineerId = null): int
    {
        $uid = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sid] + ($engineerId ? ['engineer_id' => $engineerId] : []));
        $this->completeProfile($uid);
        return $uid;
    }

    private function uid(string $email): int
    {
        return (int) $this->user($email)->id;
    }

    private function send(string $as, int $cid, int $eid, string $body, ?string $acting = null)
    {
        return $this->as($as, $acting)->post("/cases/$cid/threads/$eid", ['body' => $body]);
    }

    private function threads(): int
    {
        return DB::table('case_threads')->count();
    }

    private function posts(): int
    {
        return DB::table('case_thread_posts')->count();
    }

    private function notes(string $email, string $like = '%'): int
    {
        return DB::table('app_notifications')->where('user_id', $this->uid($email))->where('title', 'like', $like)->count();
    }

    private function bad($res, ?string $msgPart = null): void
    {
        $res->assertSessionHas('flash.type', 'bad');
        if ($msgPart) $res->assertSessionHas('flash.msg', fn ($m) => str_contains($m, $msgPart));
    }

    // 1本と発言を直接入れる（画面を通さない準備用）
    private function mkThread(int $cid, int $eid, int $sesId, array $posts = []): int
    {
        $tid = (int) DB::table('case_threads')->insertGetId(['case_id' => $cid, 'engineer_id' => $eid, 'ses_id' => $sesId, 'last_post_at' => now_str(), 'created_at' => now_str()]);
        foreach ($posts as [$side, $body, $readEg, $readSide]) {
            DB::table('case_thread_posts')->insert(['thread_id' => $tid, 'from_user_id' => null, 'from_side' => $side, 'from_label' => $side === 'eg' ? 'EG 太郎' : '担当 花子（'.self::A.'）',
                'body' => $body, 'read_by_eg' => $readEg, 'read_by_side' => $readSide, 'created_at' => now_str()]);
        }
        return $tid;
    }

    // ---------- 段階1：知らせのリンク ----------
    // T1：案内メールに案件の詳細と一覧のリンク、case_offers が1行
    public function test_t1_case_mail_has_links(): void
    {
        $cid = $this->mkCase($this->sesId(self::SES1), 'open', '架空の案件T1');
        $this->assertSame(1, CaseMail::send(User::find($this->uid(self::SES1)), DB::table('cases')->find($cid), [$this->eg1 => ['subject' => '案件のご案内', 'body' => '架空の本文']], 'same'));
        $mail = DB::table('mail_outbox')->where('kind', 'case-mail')->latest('id')->first();
        $this->assertStringContainsString(url("/cases/$cid"), $mail->body);
        $this->assertStringContainsString(url('/eg/offers'), $mail->body);
        $this->assertSame(1, DB::table('case_offers')->where('case_id', $cid)->where('engineer_id', $this->eg1)->count());
    }

    // T2：EG にお知らせ（ベル）が出て、お知らせ由来のメールは増えない
    public function test_t2_case_mail_makes_notification_without_extra_mail(): void
    {
        $cid = $this->mkCase($this->sesId(self::SES1), 'open', '架空の案件T2');
        $before = DB::table('mail_outbox')->count();
        CaseMail::send(User::find($this->uid(self::SES1)), DB::table('cases')->find($cid), [$this->eg1 => ['subject' => '案件のご案内', 'body' => '架空の本文']], 'same');
        $n = DB::table('app_notifications')->where('user_id', $this->uid(self::EG1))->where('title', 'like', '案件のご案内が届きました：架空の案件T2［CA-%')->first();
        $this->assertNotNull($n);
        $this->assertSame("/cases/$cid", $n->link);
        $this->assertSame('eg', $n->for_role);
        $this->assertSame($before + 1, DB::table('mail_outbox')->count());
    }

    // T3：配信の知らせに一覧のリンク（画面の POST /cases/{id}/distribute）
    public function test_t3_distribute_mail_has_list_link(): void
    {
        $cid = $this->mkCase(null, 'open', '架空の案件T3');
        $this->as(self::ADMIN)->post("/cases/$cid/distribute", ['engineer_id' => [$this->eg1]])->assertRedirect();
        $mail = DB::table('mail_outbox')->where('to_email', self::EG1)->where('subject', 'like', '%架空の案件T3%')->first();
        $this->assertNotNull($mail);
        $this->assertStringContainsString(url('/eg/offers'), $mail->body);
    }

    // T4：停止中・退会済・会社が停止中の人には案内しない
    public function test_t4_unusable_people_are_skipped(): void
    {
        $sA = $this->sesId(self::SES1);
        $susp = $this->mkEgIn($sA, '停止 一郎', 'susp@example.test');
        DB::table('engineers')->where('id', $susp)->update(['suspended_at' => now_str()]);
        $left = $this->mkEgIn($sA, '退会 二郎', 'left@example.test');
        DB::table('engineers')->where('id', $left)->update(['status' => 'left']);
        $sC = $this->sesId('ses3@park.example');
        $inact = $this->mkEgIn($sC, '会社停止 三郎', 'inact@example.test');
        DB::table('ses_companies')->where('id', $sC)->update(['status' => 'suspended']);
        $cid = $this->mkCase($sA, 'open', '架空の案件T4');
        $items = array_fill_keys([$susp, $left, $inact], ['subject' => '件名', 'body' => '本文']);
        $this->assertSame(0, CaseMail::send(User::find($this->uid(self::ADMIN)), DB::table('cases')->find($cid), $items, 'same'));
        foreach ([$susp, $left, $inact] as $e) {
            $this->assertSame(0, DB::table('case_mails')->where('engineer_id', $e)->count());
            $this->assertSame(0, DB::table('case_offers')->where('engineer_id', $e)->count());
        }
        $this->assertSame(0, DB::table('mail_outbox')->whereIn('to_email', ['susp@example.test', 'left@example.test', 'inact@example.test'])->count());
    }

    // ---------- 段階2：EG の画面 ----------
    // T5：EG の案件の画面にやり取りの欄（0件の文と相手の会社名）。届いていない案件は 403
    public function test_t5_eg_sees_thread_card(): void
    {
        $this->as(self::EG1)->get("/cases/{$this->caseId}")->assertOk()->assertSee('担当者とのやり取り')
            ->assertSee('まだやり取りはありません')->assertSee('相手：'.self::A.'の担当者');
        $other = $this->mkCase(null, 'open', '届いていない案件');
        $this->get("/cases/$other")->assertForbidden();
        $this->get("/cases/$other/threads/{$this->eg1}")->assertNotFound();
    }

    // T6：EG が書くと、所属の担当者全員に届く。持ち込んだ他社には届かない・記録に本文が残らない
    public function test_t6_eg_post_reaches_own_company_only(): void
    {
        $this->mkStaff($this->sesId(self::SES1), self::A2, '担当 次郎');
        $res = $this->send(self::EG1, $this->caseId, $this->eg1, '勤務地について確認したいです');
        $res->assertRedirect("/cases/{$this->caseId}#thread")->assertSessionHas('flash.type', 'ok');
        $t = DB::table('case_threads')->first();
        $this->assertSame($this->sesId(self::SES1), (int) $t->ses_id);
        $p = DB::table('case_thread_posts')->first();
        $this->assertSame('eg', $p->from_side);
        $this->assertSame([1, 0], [(int) $p->read_by_eg, (int) $p->read_by_side]);
        foreach ([self::SES1, self::A2] as $e) {
            $this->assertSame(1, $this->notes($e, 'エンジニアから案件の質問が届きました：架空の案件V0100%'), $e);
            $this->assertSame(1, DB::table('mail_outbox')->where('to_email', $e)->where('kind', 'case-thread')->count());
        }
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->uid(self::SES2))->where('title', 'like', '%案件の質問%')->count());
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', self::SES2)->where('kind', 'case-thread')->count());
        $audit = DB::table('audit_log')->where('action', 'case-thread')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('勤務地', $audit[0]->detail);
        $this->assertStringContainsString("#thread {$t->id}", $audit[0]->detail);
    }

    // T7：担当者が返事 → EG にお知らせ。EG が開くと既読
    public function test_t7_ses_reply_reaches_eg_and_read(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, '質問です');
        $this->send(self::SES1, $this->caseId, $this->eg1, '回答します')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->notes(self::EG1, '担当者から案件の返事が届きました%'));
        $reply = DB::table('case_thread_posts')->where('from_side', 'ses')->first();
        $this->assertSame(0, (int) $reply->read_by_eg);
        $this->as(self::EG1)->get("/cases/{$this->caseId}")->assertOk()->assertSee('回答します');
        $this->assertSame(1, (int) DB::table('case_thread_posts')->where('id', $reply->id)->value('read_by_eg'));
        $this->assertStringContainsString('（'.self::A.'）', $reply->from_label);
    }

    // T8：担当者から先に書き始められる
    public function test_t8_ses_starts_thread(): void
    {
        $this->send(self::SES1, $this->caseId, $this->eg1, 'この案件いかがですか')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->threads());
        $this->assertSame(1, $this->notes(self::EG1, '担当者から案件の返事が届きました%'));
    }

    // T9：案件を届けていない EG には書けない（担当者・管理者とも）
    public function test_t9_cannot_write_to_engineer_without_offer(): void
    {
        $eg1b = $this->mkEgIn($this->sesId(self::SES1), '別の 人', 'eg1b@example.test');
        $this->bad($this->send(self::SES1, $this->caseId, $eg1b, '書いてみる'), '届いていない');
        $this->bad($this->send(self::ADMIN, $this->caseId, $eg1b, '書いてみる'), '届いていない');
        $this->assertSame([0, 0], [$this->threads(), $this->posts()]);
    }

    // T10：応募のあとも同じ1本が続き、書ける
    public function test_t10_after_apply_same_thread_continues(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, '応募前の質問');
        $this->as(self::EG1)->post("/cases/{$this->caseId}/apply", ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $this->assertSame(1, DB::table('applications')->where('case_id', $this->caseId)->where('engineer_id', $this->eg1)->count());
        $this->as(self::EG1)->get("/cases/{$this->caseId}")->assertSee('応募前の質問');
        $this->send(self::EG1, $this->caseId, $this->eg1, '応募後の質問')->assertSessionHas('flash.type', 'ok');
        $this->assertSame([1, 2], [$this->threads(), $this->posts()]);
    }

    // T11：募集が終わって選考中の応募が無ければ、読むだけ（EG・担当者・管理者とも書けない）
    public function test_t11_closed_case_is_read_only(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, '終了前の質問');
        DB::table('cases')->where('id', $this->caseId)->update(['status' => 'closed']);
        $this->as(self::EG1)->get("/cases/{$this->caseId}")->assertOk()->assertSee('終了前の質問')->assertSee('読むだけ')->assertDontSee('メッセージ（1〜2000 字）');
        foreach ([self::EG1, self::SES1, self::ADMIN] as $who) $this->bad($this->send($who, $this->caseId, $this->eg1, '終了後の質問'), '募集を終えた');
        $this->assertSame(1, $this->posts());
    }

    // T12：選考中の応募があれば、募集が終わっても書ける。見送り済み（rejected）なら書けない
    public function test_t12_in_selection_can_post_after_filled(): void
    {
        $appId = (int) DB::table('applications')->insertGetId(['case_id' => $this->caseId, 'engineer_id' => $this->eg1, 'ses_id' => $this->sesId(self::SES1), 'status' => 'interview', 'applied_at' => now_str()]);
        DB::table('cases')->where('id', $this->caseId)->update(['status' => 'filled']);
        $this->send(self::EG1, $this->caseId, $this->eg1, '面談前の質問')->assertSessionHas('flash.type', 'ok');
        DB::table('applications')->where('id', $appId)->update(['status' => 'rejected']);
        $this->bad($this->send(self::EG1, $this->caseId, $this->eg1, '見送り後の質問'));
        $this->assertSame(1, $this->posts());
    }

    // ---------- 段階3：担当者・管理者・権限 ----------
    // T13：他社の SES・担当者からは見えない・書けない・一覧にも出ない
    public function test_t13_other_company_cannot_see(): void
    {
        $this->mkStaff($this->sesId(self::SES2), 'staff-b2@example.test', '他社 担当');
        $this->send(self::EG1, $this->caseId, $this->eg1, '秘密の質問です');
        foreach ([self::SES2, 'staff-b2@example.test'] as $who) {
            $this->as($who)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertNotFound();
            $this->post("/cases/{$this->caseId}/threads/{$this->eg1}", ['body' => '侵入'])->assertNotFound();
            $this->get("/cases/{$this->caseId}")->assertOk()->assertDontSee('エンジニアとのやり取り')->assertDontSee('秘密の質問です');
            $this->get('/threads')->assertOk()->assertDontSee('秘密の質問です')->assertDontSee('架空の案件V0100');
            $this->get('/ses')->assertOk()->assertDontSee('さんから案件の質問');
        }
        $this->assertSame(1, $this->posts());
        // 自社の担当者には見える
        $this->as(self::SES1)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertSee('秘密の質問です');
        $this->get('/threads')->assertOk()->assertSee('架空の案件V0100');
    }

    // T14：ほかの EG は他人のやり取りを見られず、書けない
    public function test_t14_other_engineer_cannot_see(): void
    {
        $eg1b = $this->mkEgIn($this->sesId(self::SES1), '同社 別人', 'eg1b@example.test');
        $this->offer($this->caseId, $eg1b);
        $this->send(self::EG1, $this->caseId, $this->eg1, '本人だけの質問');
        $this->as('eg1b@example.test')->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertNotFound();
        $this->post("/cases/{$this->caseId}/threads/{$this->eg1}", ['body' => 'なりすまし'])->assertNotFound();
        $this->get("/cases/{$this->caseId}")->assertOk()->assertDontSee('本人だけの質問');
        // 他社の EG も同じ
        $this->offer($this->caseId, $this->engId(self::EG2));
        $this->as(self::EG2)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertNotFound();
        $this->post("/cases/{$this->caseId}/threads/{$this->eg1}", ['body' => 'なりすまし'])->assertNotFound();
        $this->get("/cases/{$this->caseId}")->assertDontSee('本人だけの質問');
        $this->assertSame(1, $this->posts());
        // 届いていない案件の画面から、他人の1本は見えない
        $this->get("/threads")->assertForbidden();
    }

    // T15：直属のエンジニアの相手は管理者。管理者全員に届き、開くと既読
    public function test_t15_direct_engineer_talks_to_admins(): void
    {
        $direct = W::directSesId();
        $deg = $this->mkEgIn($direct, '直属 一郎', 'deg@example.test');
        $this->offer($this->caseId, $deg);
        $a2 = W::createUser('admin2@example.test', '管理 二', 'admin', 'park1234');
        $this->completeProfile($a2);
        $this->as('deg@example.test')->get("/cases/{$this->caseId}")->assertSee('相手：'.setting('operator_name'));
        $this->send('deg@example.test', $this->caseId, $deg, '直属からの質問')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->notes(self::ADMIN, 'エンジニアから案件の質問%'));
        $this->assertSame(1, $this->notes('admin2@example.test', 'エンジニアから案件の質問%'));
        $this->as(self::ADMIN)->get('/admin')->assertOk()->assertSee('さんから案件の質問');
        $this->get("/cases/{$this->caseId}/threads/$deg")->assertOk()->assertSee('直属からの質問');
        $this->assertSame(1, (int) DB::table('case_thread_posts')->value('read_by_side'));
    }

    // T16：管理者は会社の1本を読めて書ける。開いても既読にならない。表示名は運営の名前。会社に知らせる（メールなし）
    public function test_t16_admin_reads_and_writes_company_thread(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, '会社の中の質問');
        $this->as(self::ADMIN)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertSee('会社の中の質問');
        $this->get("/cases/{$this->caseId}")->assertOk()->assertSee('エンジニアとのやり取り');
        $this->get('/threads')->assertOk()->assertSee('架空の案件V0100');
        $this->assertSame(0, (int) DB::table('case_thread_posts')->value('read_by_side'), '管理者が開いても、会社の1本は既読にならない');
        $this->as(self::ADMIN)->get('/admin')->assertDontSee('さんから案件の質問'); // 会社の1本は管理者の「今やること」に出さない
        $mailsBefore = DB::table('mail_outbox')->where('to_email', self::SES1)->count();
        // 会社と EG のやり取りは、管理者は読むだけ（TEZZO 2026-11-14）
        $this->bad($this->send(self::ADMIN, $this->caseId, $this->eg1, '管理者から補足'), '読むだけ');
        $this->assertSame(0, DB::table('case_thread_posts')->where('from_side', 'admin')->count());
        $this->assertSame($mailsBefore, DB::table('mail_outbox')->where('to_email', self::SES1)->count());
        // 担当者が開けば既読になる
        $this->as(self::SES1)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk();
        $this->assertSame(1, (int) DB::table('case_thread_posts')->where('from_side', 'eg')->value('read_by_side'));
    }

    // T17：EG が書いたあと、担当者の「今やること」と一覧に未読
    public function test_t17_ses_todo_and_list(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, '未読の質問');
        $this->as(self::SES1)->get('/ses')->assertOk()->assertSee('さんから案件の質問：架空の案件V0100');
        $this->get('/threads')->assertOk()->assertSee('未読');
        $this->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk();
        $this->get('/ses')->assertDontSee('さんから案件の質問');
    }

    // T18：担当者が書いたあと、EG の「今やること」と届いた案件の列
    public function test_t18_eg_todo_and_offers_column(): void
    {
        $this->send(self::SES1, $this->caseId, $this->eg1, '担当者からの一言');
        $this->as(self::EG1)->get('/eg')->assertOk()->assertSee('担当者から返事「架空の案件V0100」');
        $this->get('/eg/offers')->assertOk()->assertSee('未読 1');
        $this->get("/cases/{$this->caseId}")->assertOk();
        $this->get('/eg')->assertDontSee('担当者から返事「架空の案件V0100」');
        $this->get('/eg/offers')->assertDontSee('未読 1')->assertSee('あり');
        // 発言のない案件は「—」
        $this->offer($this->mkCase(null, 'open', '発言なしの案件'), $this->eg1);
        $this->get('/eg/offers')->assertOk()->assertSee('発言なしの案件');
    }

    // T19：空・2001 字は断る（入力は残る）。同じ文の2回目は増えない。2000 字ちょうどは通る
    public function test_t19_length_and_double_post(): void
    {
        $this->bad($this->send(self::EG1, $this->caseId, $this->eg1, ''), '1〜2000');
        $this->bad($this->send(self::EG1, $this->caseId, $this->eg1, "   \n "), '1〜2000');
        $this->bad($this->send(self::EG1, $this->caseId, $this->eg1, str_repeat('あ', 2001)), '1〜2000');
        $this->assertSame(0, $this->posts());
        $this->send(self::EG1, $this->caseId, $this->eg1, str_repeat('あ', 2000))->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->posts());
        $this->send(self::EG1, $this->caseId, $this->eg1, '同じ文');
        $notes = DB::table('app_notifications')->count();
        $this->send(self::EG1, $this->caseId, $this->eg1, '同じ文')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(2, $this->posts());
        $this->assertSame($notes, DB::table('app_notifications')->count(), '二重押しではお知らせも増えない');
        // 配列を送られても落ちない
        $this->post("/cases/{$this->caseId}/threads/{$this->eg1}", ['body' => ['x']])->assertRedirect();
    }

    // T20：所属が変わったら、前の1本は読むだけ。新しく書くと新しい会社の1本ができる。新しい会社には前のが見えず、会社名も漏れない
    public function test_t20_affiliation_change(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, 'A社の時の質問');
        $this->send(self::SES1, $this->caseId, $this->eg1, 'A社の担当の返事');
        $b = $this->sesId(self::SES2);
        $this->as(self::ADMIN)->post("/engineers/{$this->eg1}/affiliation", ['ses_id' => $b])->assertRedirect();
        $this->assertSame($b, (int) DB::table('engineers')->where('id', $this->eg1)->value('ses_id'));

        // EG：前の1本は読める（前の所属として）。新しい会社が相手の案内が出る
        $page = $this->as(self::EG1)->get("/cases/{$this->caseId}")->assertOk()->assertSee('A社の時の質問')->assertSee('前の所属（'.self::A.'）とのやり取り（読むだけ）')
            ->assertSee('相手：'.self::B.'の担当者')->getContent();
        $this->assertStringContainsString('メッセージ（1〜2000 字）', $page); // 新しい会社には書ける
        // 前の会社は、前の1本を読めるが書けない
        $this->as(self::SES1)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertSee('A社の時の質問')->assertSee('読むだけ');
        $this->bad($this->send(self::SES1, $this->caseId, $this->eg1, '移籍後に書く'));
        // 新しい会社（B）：前の1本は見えず、前の会社名も出ない
        $this->as(self::SES2)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertDontSee('A社の時の質問')->assertDontSee('A社の担当の返事')->assertDontSee(self::A);
        $this->get('/threads')->assertDontSee(self::A);
        // EG が新しく書く → B の1本ができる。A の1本はそのまま
        $this->send(self::EG1, $this->caseId, $this->eg1, 'B社への最初の質問')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(2, $this->threads());
        $this->assertSame(1, DB::table('case_threads')->where('ses_id', $b)->count());
        $this->assertSame(2, DB::table('case_thread_posts')->whereIn('thread_id', DB::table('case_threads')->where('ses_id', $this->sesId(self::SES1))->pluck('id'))->count());
        $this->assertSame(1, $this->notes(self::SES2, 'エンジニアから案件の質問が届きました%'));
        $this->assertSame(1, $this->notes(self::SES1, 'エンジニアから案件の質問が届きました%'), 'A 社には移籍後の質問が届かない（最初の1通だけ）');
        // B の画面には、前の会社名も A の担当者の名前入りの発言も出ない
        $this->as(self::SES2)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertSee('B社への最初の質問')->assertDontSee(self::A)->assertDontSee('A社の時の質問');
        // A は、B社の1本を見られない（一覧にも出ない）
        $this->as(self::SES1)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertDontSee('B社への最初の質問')->assertDontSee(self::B);
    }

    // T21：停止中のエンジニアには書けない（担当者・管理者）。履歴は担当者に見える
    public function test_t21_suspended_engineer(): void
    {
        $this->send(self::EG1, $this->caseId, $this->eg1, '停止前の質問');
        DB::table('engineers')->where('id', $this->eg1)->update(['suspended_at' => now_str()]);
        $this->bad($this->send(self::SES1, $this->caseId, $this->eg1, '書けない'), '停止中');
        $this->bad($this->send(self::ADMIN, $this->caseId, $this->eg1, '書けない'), '停止中');
        $this->as(self::SES1)->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertOk()->assertSee('停止前の質問');
        $this->assertSame(1, $this->posts());
        // 会社が参加中でないときも書けない
        DB::table('engineers')->where('id', $this->eg1)->update(['suspended_at' => null]);
        DB::table('ses_companies')->where('id', $this->sesId(self::SES1))->update(['status' => 'suspended']);
        $this->bad($this->send(self::ADMIN, $this->caseId, $this->eg1, '書けない'), '参加中');
    }

    // ---------- 段階4：消す道・版 ----------
    // T22：見本を消すと、やり取りも消える。実在の1本は残り、見本の人の発言は from_user_id が空になる
    public function test_t22_purge_samples_removes_threads(): void
    {
        $aid = W::createUser('real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        $this->completeProfile($aid, ['company_name' => '実在運営']);
        $admin = User::find($aid);
        $realEg = W::createEngineer(W::directSesId(), ['name' => '実在 直属', 'email' => 'real-eg@example.com'], $admin);
        $realCase = W::saveCase(['title' => '実在の案件', 'price' => 70, 'status' => 'open'], $admin);
        $this->offer($realCase, $realEg);
        $sampleUser = $this->uid(self::SES1);
        // 見本の1本（見本の EG・見本の案件）
        $sample = $this->mkThread($this->caseId, $this->eg1, $this->sesId(self::SES1), [['eg', '見本の質問', 1, 0]]);
        // 実在の1本（実在の EG・実在の案件）に、見本のユーザーの発言が混じる
        $real = $this->mkThread($realCase, $realEg, W::directSesId(), [['eg', '実在の質問', 1, 0]]);
        DB::table('case_thread_posts')->insert(['thread_id' => $real, 'from_user_id' => $sampleUser, 'from_side' => 'admin', 'from_label' => '見本の人', 'body' => '見本の人が書いた', 'read_by_eg' => 0, 'read_by_side' => 1, 'created_at' => now_str()]);

        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]), Artisan::output());
        $this->assertSame(0, DB::table('case_threads')->where('id', $sample)->count());
        $this->assertSame(0, DB::table('case_thread_posts')->where('thread_id', $sample)->count());
        $this->assertSame(1, DB::table('case_threads')->where('id', $real)->count());
        $this->assertSame(2, DB::table('case_thread_posts')->where('thread_id', $real)->count());
        $this->assertNull(DB::table('case_thread_posts')->where('body', '見本の人が書いた')->value('from_user_id'));
        $this->assertSame(1, $this->threads());
    }

    // T23：やり取りの記録がある仮登録は取り消せない
    public function test_t23_cancel_blocked_by_thread(): void
    {
        $admin = User::find($this->uid(self::ADMIN));
        $eid = W::createEngineer($this->sesId(self::SES1), ['name' => '仮 登録', 'email' => 'prov@example.test'], $admin, false);
        $uid = W::createUser('prov@example.test', '仮 登録', 'eg', 'x', ['ses_id' => $this->sesId(self::SES1), 'engineer_id' => $eid]);
        DB::table('users')->where('id', $uid)->update(['password' => null, 'registered_at' => null]);
        $this->mkThread($this->caseId, $eid, $this->sesId(self::SES1));
        $res = Registration::cancel(DB::table('users')->find($uid), $admin);
        $this->assertSame('bad', $res['type']);
        $this->assertStringContainsString('記録があるため、取り消せません', $res['msg']);
        $this->assertNotNull(DB::table('users')->find($uid));
    }

    // T24：版
    public function test_t24_version(): void
    {
        $this->assertSame('v0.10.0', config('park.version'));
    }

    // T25：「質問がある」の画面に案内とリンク
    public function test_t25_question_page_links_to_case(): void
    {
        $cid = $this->mkCase($this->sesId(self::SES1), 'open', '架空の案件T25');
        CaseMail::send(User::find($this->uid(self::SES1)), DB::table('cases')->find($cid), [$this->eg1 => ['subject' => '件名', 'body' => '本文']], 'same');
        $token = DB::table('case_mails')->where('case_id', $cid)->value('token');
        $this->get("/r/$token/question")->assertOk()->assertSee('担当者とやり取りできます', false)->assertSee(url("/cases/$cid"), false);
    }

    // ---------- 追加：兼任・管理者の既読・見えない人 ----------
    // 兼任（担当者で、自社のエンジニアでもある人）：自分の質問が自分に通知されない・自分の1本に担当者として書けない・今やることに出ない
    public function test_dual_person_is_not_notified_of_own_thread(): void
    {
        $a = $this->sesId(self::SES1);
        $dEng = $this->mkEgIn($a, '兼任 三郎', 'dual-eg@example.test');
        $dUser = $this->mkStaff($a, 'dual-ses@example.test', '兼任 三郎', $dEng);
        $this->offer($this->caseId, $dEng);
        // エンジニアの立場で書く
        $this->send('dual-ses@example.test', $this->caseId, $dEng, '兼任の質問', 'eg')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $dUser)->where('for_role', 'ses')->count(), '自分の発言が、担当者の立場の自分に届いてはいけない');
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $dUser)->count());
        $this->assertSame(1, $this->notes(self::SES1, 'エンジニアから案件の質問が届きました%'), 'ほかの担当者には届く');
        // 担当者の立場では、自分のやり取りに書けない
        $this->bad($this->send('dual-ses@example.test', $this->caseId, $dEng, '自分に返事', 'ses'), 'ご自身のやり取り');
        // 担当者の立場の「今やること」に、自分の質問は出ない。ほかの担当者には出る
        $this->as('dual-ses@example.test', 'ses')->get('/ses')->assertOk()->assertDontSee('兼任 三郎 さんから案件の質問');
        $this->as(self::SES1)->get('/ses')->assertOk()->assertSee('兼任 三郎 さんから案件の質問');
        // 担当者の立場で開いて既読にしても、書けないだけで壊れない
        $this->as('dual-ses@example.test', 'ses')->get("/cases/{$this->caseId}/threads/$dEng")->assertOk();
    }

    // 管理者を兼ねるエンジニアが、直属で自分の質問を書いても、管理者の立場の自分に通知が出ない
    public function test_dual_admin_not_notified_of_own_direct_thread(): void
    {
        $direct = W::directSesId();
        $deg = $this->mkEgIn($direct, '管理兼任 四郎', 'dual-adm-eg@example.test');
        $uid = W::createUser('dual-adm@example.test', '管理兼任 四郎', 'admin', 'park1234', ['engineer_id' => $deg]);
        $this->completeProfile($uid);
        $this->offer($this->caseId, $deg);
        $this->send('dual-adm@example.test', $this->caseId, $deg, '管理兼任の質問', 'eg')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $uid)->where('for_role', 'admin')->count());
        $this->assertSame(1, $this->notes(self::ADMIN, 'エンジニアから案件の質問%'));
        $this->as('dual-adm@example.test', 'admin')->get('/admin')->assertOk()->assertDontSee('管理兼任 四郎 さんから案件の質問');
        $this->bad($this->send('dual-adm@example.test', $this->caseId, $deg, '自分に返事', 'admin'), 'ご自身のやり取り');
    }

    // 会社に使える担当者がいないときは、管理者にも知らせる
    public function test_company_without_staff_notifies_admins(): void
    {
        DB::table('users')->where('ses_id', $this->sesId(self::SES1))->where('role', 'ses')->update(['active' => 0]);
        $this->send(self::EG1, $this->caseId, $this->eg1, '誰もいない会社への質問')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->notes(self::ADMIN, 'エンジニアから案件の質問が届きました%'));
    }

    // 未ログイン・ほかの形の入力・存在しない id
    public function test_guest_and_missing(): void
    {
        $this->get("/cases/{$this->caseId}/threads/{$this->eg1}")->assertRedirect();
        $this->post("/cases/{$this->caseId}/threads/{$this->eg1}", ['body' => 'x'])->assertRedirect();
        $this->get('/threads')->assertRedirect();
        $this->as(self::ADMIN)->get("/cases/999999/threads/{$this->eg1}")->assertNotFound();
        $this->get("/cases/{$this->caseId}/threads/999999")->assertNotFound();
        $this->post("/cases/{$this->caseId}/threads/999999", ['body' => 'x'])->assertNotFound();
        $this->as(self::EG1)->get('/threads')->assertForbidden();
    }

    // 下書きの案件は、他社の担当者には 404（案件ごと見えない）
    public function test_draft_case_of_other_company_is_not_found(): void
    {
        $draft = $this->mkCase($this->sesId(self::SES2), 'draft', '下書き案件');
        $this->offer($draft, $this->eg1);
        $this->as(self::SES1)->get("/cases/$draft/threads/{$this->eg1}")->assertNotFound();
    }

    // 担当者の一覧：未読が上に来る。管理者は会社で絞れる
    public function test_list_order_and_admin_filter(): void
    {
        $c2 = $this->mkCase(null, 'open', '二つ目の案件');
        $this->offer($c2, $this->eg1);
        $this->mkThread($this->caseId, $this->eg1, $this->sesId(self::SES1), [['eg', '既読の質問', 1, 1]]);
        $this->mkThread($c2, $this->eg1, $this->sesId(self::SES1), [['eg', '未読の質問', 1, 0]]);
        $html = $this->as(self::SES1)->get('/threads')->assertOk()->getContent();
        $this->assertLessThan(strpos($html, '架空の案件V0100'), strpos($html, '二つ目の案件'), '未読のある1本が上');
        $eg2 = $this->engId(self::EG2);
        $this->offer($c2, $eg2);
        $this->mkThread($c2, $eg2, $this->sesId(self::SES2), [['eg', 'B社の質問', 1, 0]]);
        $this->as(self::ADMIN)->get('/threads')->assertOk()->assertSee('二つ目の案件')->assertSee(self::B);
        $this->get('/threads?ses='.$this->sesId(self::SES2))->assertOk()->assertSee('二つ目の案件');
        $this->as(self::SES1)->get('/threads?ses='.$this->sesId(self::SES2))->assertOk()->assertDontSee(self::B);
    }

    // park:status が動く（返事のない EG の発言）
    public function test_status_counts_stale_threads(): void
    {
        $tid = $this->mkThread($this->caseId, $this->eg1, $this->sesId(self::SES1), [['eg', '古い質問', 1, 0]]);
        DB::table('case_thread_posts')->where('thread_id', $tid)->update(['created_at' => date('Y-m-d H:i:s', time() - 5 * 86400)]);
        $this->assertSame(1, \App\Services\CaseThread::staleCount());
        $this->assertSame(0, Artisan::call('park:status', ['--json' => true]));
    }
}
