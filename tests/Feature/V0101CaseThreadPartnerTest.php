<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.10.1 案件のやり取りの相手を「案件を持ち込んだ会社」に変える（PLAN.md 8章 N1〜N16）。人・会社は見本データと、テストの中で作る架空のものだけ
// 会社：A＝SES2（あおぞらテック。持ち込み元）、B＝SES1（みどり情報システム。EG F の所属）、C＝SES3（無関係）、直属＝管理者
// EG：F＝B 所属（架空 花子。イニシャル K.H.）、G＝B 所属（案内していない別の人）、D＝直属
// 案件：X＝持ち込み元 A（F・D・G に届いている。A→F の案内メールあり）、Y＝持ち込み元なし、Z＝持ち込み元 B
class V0101CaseThreadPartnerTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private array $backupsBefore = [];

    private const ADMIN = 'admin@park.example';
    private const SA = 'ses2@park.example';
    private const SB = 'ses1@park.example';
    private const SC = 'ses3@park.example';
    private const A2 = 'staff-a2@example.test';
    private const B2 = 'staff-b2@example.test';
    private const C2 = 'staff-c2@example.test';
    private const F_EMAIL = 'f-eg@example.test';
    private const F_NAME = '架空 花子';
    private const A_NAME = 'あおぞらテック';
    private const B_NAME = 'みどり情報システム';
    private const MASKED = 'K.H.（みどり情報システム）';

    private int $a;
    private int $b;
    private int $c;
    private int $f;
    private int $g;
    private int $d;
    private int $x;
    private int $y;
    private int $z;
    private string $fCode;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
        $this->a = $this->sesId(self::SA);
        $this->b = $this->sesId(self::SB);
        $this->c = $this->sesId(self::SC);
        $this->mkStaff($this->a, self::A2, '持込 担当二');
        $this->mkStaff($this->b, self::B2, '所属 担当二');
        $this->mkStaff($this->c, self::C2, '無関係 担当二');
        $this->f = $this->mkEgIn($this->b, self::F_NAME, self::F_EMAIL);
        DB::table('engineers')->where('id', $this->f)->update(['initials' => 'K.H.']);
        $this->fCode = (string) DB::table('engineers')->where('id', $this->f)->value('code');
        $this->g = $this->mkEgIn($this->b, '別人 次郎', 'g-eg@example.test');
        $this->d = $this->mkEgIn(W::directSesId(), '直属 三郎', 'd-eg@example.test');
        $this->x = $this->mkCase($this->a, 'open', '架空の案件X');
        $this->y = $this->mkCase(null, 'open', '架空の案件Y');
        $this->z = $this->mkCase($this->b, 'open', '架空の案件Z');
        foreach ([$this->x, $this->y, $this->z] as $cid) $this->offer($cid, $this->f);
        foreach ([$this->x, $this->y] as $cid) $this->offer($cid, $this->g);
        $this->offer($this->x, $this->d);
        $this->mkCaseMail($this->x, $this->f);
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

    private function uid(string $email): int
    {
        return (int) $this->user($email)->id;
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    private function mkCase(?int $source, string $status, string $title): int
    {
        $cid = (int) DB::table('cases')->insertGetId(['title' => $title, 'status' => $status, 'source_ses_id' => $source, 'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('cases')->where('id', $cid)->update(['code' => \App\Services\Ids::next('C')]);
        return $cid;
    }

    private function offer(int $cid, int $eid): void
    {
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $eid, 'score' => 50, 'sent_at' => now_str()]);
    }

    // 案件の持ち込み元（A）から、他社の EG への案内メールの記録（R9 の「画面に出ている人」）
    private function mkCaseMail(int $cid, int $eid): void
    {
        DB::table('case_mails')->insert(['case_id' => $cid, 'engineer_id' => $eid, 'ses_id' => $this->a, 'sent_by' => $this->uid(self::SA), 'route' => 'group', 'mode' => 'search',
            'subject' => '件名', 'body' => '本文', 'sent_at' => now_str(), 'token' => bin2hex(random_bytes(10))]);
        $this->offerIfMissing($cid, $eid);
    }

    private function offerIfMissing(int $cid, int $eid): void
    {
        if (!DB::table('case_offers')->where('case_id', $cid)->where('engineer_id', $eid)->exists()) $this->offer($cid, $eid);
    }

    private function mkEgIn(int $sid, string $name, string $email): int
    {
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], User::find($this->uid(self::ADMIN)), false);
        DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        $uid = W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
        $this->completeProfile($uid);
        return $id;
    }

    private function mkStaff(int $sid, string $email, string $name): int
    {
        $uid = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        return $uid;
    }

    private function send(string $as, int $cid, int $eid, string $body)
    {
        return $this->as($as)->post("/cases/$cid/threads/$eid", ['body' => $body]);
    }

    private function threads(): int
    {
        return DB::table('case_threads')->count();
    }

    private function posts(): int
    {
        return DB::table('case_thread_posts')->count();
    }

    private function notes(string $email, string $like): int
    {
        return DB::table('app_notifications')->where('user_id', $this->uid($email))->where('title', 'like', $like)->count();
    }

    // やり取りに関するお知らせ（4種類）の数。app_notifications に kind 列は無いので、題で数える
    private function anyThreadNotes(string $email): int
    {
        return DB::table('app_notifications')->where('user_id', $this->uid($email))->where(fn ($q) => $q->where('title', 'like', 'エンジニアから案件の質問%')
            ->orWhere('title', 'like', '自社のエンジニアが案件のやり取り%')->orWhere('title', 'like', '担当者から案件の返事%')->orWhere('title', 'like', '管理者から案件の返事%'))->count();
    }

    private function mails(string $email): int
    {
        return DB::table('mail_outbox')->where('to_email', $email)->where('kind', 'case-thread')->count();
    }

    private function bad($res, ?string $part = null): void
    {
        $res->assertSessionHas('flash.type', 'bad');
        if ($part) $res->assertSessionHas('flash.msg', fn ($m) => str_contains($m, $part));
    }

    private function thread(int $cid, int $eid): ?object
    {
        return DB::table('case_threads')->where('case_id', $cid)->where('engineer_id', $eid)->first();
    }

    // F が X に書いた状態を作る
    private function fPostsX(string $body = '勤務地は在宅ですか SECRETQ'): void
    {
        $this->send(self::F_EMAIL, $this->x, $this->f, $body)->assertSessionHas('flash.type', 'ok');
    }

    private function readSide(): int
    {
        return (int) DB::table('case_thread_posts')->where('from_side', 'eg')->value('read_by_side');
    }

    // ---------- N1〜N4：相手の決まり ----------
    public function test_n1_partner_is_source_company(): void
    {
        $this->fPostsX();
        $t = $this->thread($this->x, $this->f);
        $this->assertSame([$this->a, $this->b], [(int) $t->partner_ses_id, (int) $t->ses_id]);
        // 相手（A）の担当者2人に通知とメール
        foreach ([self::SA, self::A2] as $e) {
            $this->assertSame(1, $this->notes($e, 'エンジニアから案件の質問が届きました：架空の案件X%'), $e);
            $this->assertSame(1, $this->mails($e), $e);
        }
        // 所属（B）には「始まりました」のお知らせ1件だけ。発言ごとの通知・メールは出ない
        foreach ([self::SB, self::B2] as $e) {
            $this->assertSame(1, $this->notes($e, '自社のエンジニアが案件のやり取りを始めました：架空の案件X%'), $e);
            $this->assertSame(0, $this->notes($e, 'エンジニアから案件の質問が届きました%'), $e);
            $this->assertSame(0, $this->mails($e), $e);
        }
        // 2回目の発言では、B への「始まりました」は増えない
        $this->send(self::F_EMAIL, $this->x, $this->f, 'もう一つ質問です')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->notes(self::SB, '自社のエンジニアが案件のやり取りを始めました%'));
        $this->assertSame(0, $this->notes(self::SB, 'エンジニアから案件の質問が届きました%'));
        // C には何も届かない
        foreach ([self::SC, self::C2] as $e) $this->assertSame(0, $this->anyThreadNotes($e), $e);
        // 記録に本文・名前を残さない
        foreach (DB::table('audit_log')->where('action', 'case-thread')->get() as $row) {
            $this->assertStringNotContainsString('勤務地', $row->detail);
            $this->assertStringNotContainsString('花子', $row->detail);
        }
    }

    public function test_n2_no_source_partner_is_own_company(): void
    {
        $this->send(self::F_EMAIL, $this->y, $this->f, '案件Yの質問')->assertSessionHas('flash.type', 'ok');
        $t = $this->thread($this->y, $this->f);
        $this->assertSame([$this->b, $this->b], [(int) $t->partner_ses_id, (int) $t->ses_id]);
        foreach ([self::SB, self::B2] as $e) {
            $this->assertSame(1, $this->notes($e, 'エンジニアから案件の質問が届きました：架空の案件Y%'), $e);
            $this->assertSame(1, $this->mails($e), $e);
            $this->assertSame(0, $this->notes($e, '自社のエンジニアが案件のやり取りを始めました%'), $e);
        }
        foreach ([self::SA, self::A2, self::SC] as $e) $this->assertSame(0, $this->anyThreadNotes($e), $e);
        // A（案件Yと無関係）は開けない
        $this->as(self::SA)->get("/cases/{$this->y}/threads/{$this->f}")->assertNotFound();
    }

    public function test_n3_source_same_as_affiliation(): void
    {
        $this->send(self::F_EMAIL, $this->z, $this->f, '案件Zの質問')->assertSessionHas('flash.type', 'ok');
        $t = $this->thread($this->z, $this->f);
        $this->assertSame([$this->b, $this->b], [(int) $t->partner_ses_id, (int) $t->ses_id]);
        $this->assertSame(0, $this->notes(self::SB, '自社のエンジニアが案件のやり取りを始めました%'));
        $this->assertSame(1, $this->notes(self::SB, 'エンジニアから案件の質問が届きました%'));
    }

    public function test_n4_direct_engineer_partner_is_admin(): void
    {
        $this->send('d-eg@example.test', $this->x, $this->d, '直属からの質問')->assertSessionHas('flash.type', 'ok');
        $t = $this->thread($this->x, $this->d);
        $direct = W::directSesId();
        $this->assertSame([$direct, $direct], [(int) $t->partner_ses_id, (int) $t->ses_id]);
        $this->assertSame(1, $this->notes(self::ADMIN, 'エンジニアから案件の質問が届きました%'));
        // 持ち込み元 A には届かず、開けず、書けない
        foreach ([self::SA, self::A2] as $e) {
            $this->assertSame(0, $this->anyThreadNotes($e), $e);
            $this->as($e)->get("/cases/{$this->x}/threads/{$this->d}")->assertNotFound();
            $this->post("/cases/{$this->x}/threads/{$this->d}", ['body' => '侵入'])->assertNotFound();
            $this->get('/threads')->assertOk()->assertDontSee('直属からの質問')->assertDontSee('直属 三郎');
        }
        // 管理者は直属の1本には書ける（対照）
        $this->send(self::ADMIN, $this->x, $this->d, '管理者の返事')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(2, DB::table('case_thread_posts')->where('thread_id', $t->id)->count());
    }

    // ---------- N5：A が返事を書ける・既読は相手側だけ ----------
    public function test_n5_read_only_by_partner_and_reply_notifies_eg(): void
    {
        $this->fPostsX();
        // 読んでも既読が変わらない人：B・B2・管理者・C（404）
        foreach ([self::SB, self::B2, self::ADMIN] as $who) {
            $this->as($who)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk();
            $this->assertSame(0, $this->readSide(), "$who が開いただけで既読になった");
        }
        $this->as(self::SC)->get("/cases/{$this->x}/threads/{$this->f}")->assertNotFound();
        $this->assertSame(0, $this->readSide());
        // A が開くと既読
        $this->as(self::SA)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ')->assertSee('メッセージ（1〜2000 字）');
        $this->assertSame(1, $this->readSide());
        // A が書く → F にお知らせとメール。F が開くまで read_by_eg は 0
        $this->send(self::A2, $this->x, $this->f, '在宅です')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->notes(self::F_EMAIL, '担当者から案件の返事が届きました：架空の案件X%'));
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', self::F_EMAIL)->where('kind', 'case-thread')->count());
        $reply = DB::table('case_thread_posts')->where('from_side', 'ses')->first();
        $this->assertSame(0, (int) $reply->read_by_eg);
        // B・管理者が開いても F の分は既読にならない
        foreach ([self::SB, self::ADMIN] as $who) {
            $this->as($who)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('在宅です');
            $this->assertSame(0, (int) DB::table('case_thread_posts')->where('id', $reply->id)->value('read_by_eg'));
        }
        // F が案件の画面を開くと既読。相手の欄には A 社の担当者の氏名（会社名）が出る
        $this->as(self::F_EMAIL)->get("/cases/{$this->x}")->assertOk()->assertSee('在宅です')->assertSee('（'.self::A_NAME.'）');
        $this->assertSame(1, (int) DB::table('case_thread_posts')->where('id', $reply->id)->value('read_by_eg'));
        // 「始まりました」のお知らせは増えていない
        $this->assertSame(1, $this->notes(self::SB, '自社のエンジニアが案件のやり取りを始めました%'));
    }

    // ---------- N6：B は読むだけ ----------
    public function test_n6_affiliation_company_is_read_only(): void
    {
        $this->fPostsX();
        foreach ([self::SB, self::B2] as $who) {
            $page = $this->as($who)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ')->assertSee('貴社は読むだけです')->getContent();
            $this->assertStringNotContainsString('メッセージ（1〜2000 字）', $page, '読むだけの会社に入力欄が出ている');
            $before = [$this->posts(), $this->threads()];
            $this->bad($this->post("/cases/{$this->x}/threads/{$this->f}", ['body' => 'B社が書く']), '読むだけ');
            $this->assertSame($before, [$this->posts(), $this->threads()]);
            $this->assertSame(0, $this->readSide());
            // 一覧には「読むだけ」。自分の未読として数えない。今やることにも出ない
            $this->get('/threads')->assertOk()->assertSee('読むだけ（相手：'.self::A_NAME.'）')->assertSee(self::F_NAME);
            $this->get('/ses')->assertOk()->assertDontSee('さんから案件の質問');
        }
        // 案件の画面の「エンジニアとのやり取り」にも読むだけと出る
        $this->as(self::SB)->get("/cases/{$this->x}")->assertOk()->assertSee('読むだけ（相手：'.self::A_NAME.'）');
    }

    // ---------- N7：C は見えない ----------
    public function test_n7_unrelated_company_sees_nothing(): void
    {
        $this->fPostsX();
        foreach ([self::SC, self::C2] as $who) {
            $this->as($who)->get("/cases/{$this->x}/threads/{$this->f}")->assertNotFound();
            $this->post("/cases/{$this->x}/threads/{$this->f}", ['body' => '侵入'])->assertNotFound();
            $this->get('/threads')->assertOk()->assertDontSee('SECRETQ')->assertDontSee('架空の案件X')->assertDontSee(self::F_NAME)->assertDontSee('K.H.');
            $r = $this->get("/cases/{$this->x}");
            if ($r->status() === 200) {
                $r->assertDontSee('エンジニアとのやり取り')->assertDontSee('SECRETQ')->assertDontSee(self::F_NAME)->assertDontSee('K.H.');
            } else {
                $r->assertNotFound();
            }
            $this->get('/ses')->assertOk()->assertDontSee('さんから案件の質問')->assertDontSee('K.H.');
            $this->get('/notifications')->assertOk()->assertDontSee('案件の質問');
        }
        $this->assertSame(1, $this->posts());
        // 別の案件の1本（Y）も、案件を持ち込んでいない会社には 404
        $this->send(self::F_EMAIL, $this->y, $this->f, 'Yの質問');
        $this->as(self::SA)->get("/cases/{$this->y}/threads/{$this->f}")->assertNotFound();
        $this->as(self::SC)->get("/cases/{$this->y}/threads/{$this->f}")->assertNotFound();
        // 他社の EG（EG2）も、他人のやり取りを見られない
        $this->offer($this->x, $this->engId('eg2@park.example'));
        $this->as('eg2@park.example')->get("/cases/{$this->x}/threads/{$this->f}")->assertNotFound();
        $this->post("/cases/{$this->x}/threads/{$this->f}", ['body' => 'なりすまし'])->assertNotFound();
        $this->assertSame(2, $this->posts());
    }

    // ---------- N8：A が書き始められる範囲（他社 EG を URL で探れない） ----------
    public function test_n8_partner_can_start_only_with_mailed_engineer(): void
    {
        // 案内していない B の EG（G。case_offers だけ）は 404（GET・POST）
        foreach ([self::SA, self::A2] as $who) {
            $this->as($who)->get("/cases/{$this->x}/threads/{$this->g}")->assertNotFound();
            $this->post("/cases/{$this->x}/threads/{$this->g}", ['body' => '探る'])->assertNotFound();
        }
        // 直属の D（case_offers だけ）も 404
        $this->as(self::SA)->get("/cases/{$this->x}/threads/{$this->d}")->assertNotFound();
        $this->post("/cases/{$this->x}/threads/{$this->d}", ['body' => '探る'])->assertNotFound();
        // 存在しない EG も 404（あるかどうかを分けない）
        $this->get("/cases/{$this->x}/threads/999999")->assertNotFound();
        $this->assertSame([0, 0], [$this->threads(), $this->posts()]);
        // 案件の画面：案内した F には［やり取り］があり、G・D には出ない
        $page = $this->get("/cases/{$this->x}")->assertOk()->getContent();
        $this->assertStringContainsString('/cases/'.$this->x.'/threads/'.$this->f.'"', $page);
        $this->assertStringNotContainsString('/threads/'.$this->g.'"', $page);
        $this->assertStringNotContainsString('/threads/'.$this->d.'"', $page);
        // 案内した F には、A が先に書き始められる。F に通知。1本の相手は A
        $this->assertSame(0, DB::table('case_threads')->count());
        $this->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('メッセージ（1〜2000 字）');
        $this->send(self::SA, $this->x, $this->f, 'この案件いかがですか')->assertSessionHas('flash.type', 'ok');
        $t = $this->thread($this->x, $this->f);
        $this->assertSame([$this->a, $this->b], [(int) $t->partner_ses_id, (int) $t->ses_id]);
        $this->assertSame(1, $this->notes(self::F_EMAIL, '担当者から案件の返事が届きました%'));
        // 持ち込み元ではない会社（C）は、案内の記録があっても書き始められない
        $this->mkCaseMail($this->y, $this->f); // 案件Y（持ち込み元なし）に案内の記録
        $this->as(self::SA)->get("/cases/{$this->y}/threads/{$this->f}")->assertNotFound();
        $this->as(self::SC)->post("/cases/{$this->x}/threads/{$this->g}", ['body' => '探る'])->assertNotFound();
        $this->assertSame(1, $this->threads());
    }

    // ---------- N9：A には本名・EG-・詳細リンクを出さない ----------
    public function test_n9_partner_pages_do_not_leak(): void
    {
        $this->fPostsX('本名を出さない確認 SECRETQ');
        $this->send(self::F_EMAIL, $this->x, $this->f, '二通目です');
        $forbidden = [self::F_NAME, $this->fCode, self::F_EMAIL, url('/engineers/'.$this->f).'"'];
        foreach ([self::SA, self::A2] as $who) {
            $this->as($who);
            DB::table('case_thread_posts')->where('from_side', 'eg')->update(['read_by_side' => 0]); // 担当者ごとに、未読の状態から見る
            $pages = [
                '/threads' => $this->get('/threads')->assertOk()->assertSee(self::MASKED)->getContent(),
                '/ses' => $this->get('/ses')->assertOk()->assertSee(self::MASKED.' さんから案件の質問：架空の案件X')->getContent(), // 開いて既読にする前に見る
                '/cases/x' => $this->get("/cases/{$this->x}")->assertOk()->assertSee(self::MASKED)->getContent(),
                '/cases/x/threads/f' => $this->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee(self::MASKED)->assertSee('本名を出さない確認 SECRETQ')->assertSee('二通目です')->getContent(),
                '/notifications' => $this->get('/notifications')->assertOk()->getContent(),
            ];
            foreach ($pages as $url => $html) {
                foreach ($forbidden as $bad) $this->assertStringNotContainsString($bad, $html, "$who の $url に「{$bad}」が出ている");
            }
            // 通知の中身（A 社あて）とメール本文
            foreach (DB::table('app_notifications')->where('user_id', $this->uid($who))->get() as $n) {
                foreach ([self::F_NAME, $this->fCode, self::F_EMAIL] as $bad) {
                    $this->assertStringNotContainsString($bad, $n->title.$n->body.$n->link, "$who の通知に「{$bad}」");
                }
            }
            foreach (DB::table('mail_outbox')->where('to_email', $who)->get() as $m) {
                foreach ([self::F_NAME, $this->fCode, self::F_EMAIL] as $bad) {
                    $this->assertStringNotContainsString($bad, $m->subject.$m->body, "$who あてのメールに「{$bad}」");
                }
            }
            $this->assertStringContainsString(self::MASKED, DB::table('app_notifications')->where('user_id', $this->uid($who))->where('title', 'like', 'エンジニアから案件の質問%')->value('body'));
        }
        // 保存してある表示名は本名でも、A 社の画面には出ない（上で確認済み）。念のため保存の形を確かめる
        $this->assertSame(self::F_NAME, DB::table('case_thread_posts')->where('from_side', 'eg')->value('from_label'));
    }

    // ---------- N10：B・管理者・本人には氏名 ----------
    public function test_n10_affiliation_admin_and_self_see_real_name(): void
    {
        $this->fPostsX();
        foreach ([self::SB, self::ADMIN] as $who) {
            $this->as($who);
            $this->get('/threads')->assertOk()->assertSee(self::F_NAME);
            $this->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee(self::F_NAME)->assertSee($this->fCode);
            $this->get("/cases/{$this->x}")->assertOk()->assertSee(self::F_NAME);
        }
        // 管理者の一覧に「相手」の列と A の会社名
        $this->as(self::ADMIN)->get('/threads')->assertOk()->assertSee('相手')->assertSee(self::A_NAME);
        // EG 本人：相手の説明と読める会社
        $this->as(self::F_EMAIL)->get("/cases/{$this->x}")->assertOk()->assertSee('担当者とのやり取り')->assertSee('SECRETQ');
        // まだ書いていない案件の0件の文：相手は持ち込み元で、所属も読める
        $this->get("/cases/{$this->z}")->assertOk()->assertSee('相手：'.self::B_NAME.'の担当者');
        $this->mkCase($this->a, 'open', '架空の案件W');
        $w = (int) DB::table('cases')->where('title', '架空の案件W')->value('id');
        $this->offer($w, $this->f);
        $this->get("/cases/$w")->assertOk()->assertSee('相手：'.self::A_NAME.'の担当者')->assertSee('（'.self::B_NAME.'も読めます）');
    }

    // ---------- N11・N12：今やること ----------
    public function test_n11_n12_todo(): void
    {
        $this->fPostsX();
        // N11：B の今やることには出ない
        foreach ([self::SB, self::B2] as $who) $this->as($who)->get('/ses')->assertOk()->assertDontSee('さんから案件の質問');
        // N12：A には出る。開いて既読にすると消える
        $this->as(self::SA)->get('/ses')->assertOk()->assertSee(self::MASKED.' さんから案件の質問：架空の案件X');
        $this->as(self::A2)->get('/ses')->assertOk()->assertSee('さんから案件の質問');
        $this->get('/threads')->assertOk()->assertSee('未読');
        $this->get("/cases/{$this->x}/threads/{$this->f}")->assertOk();
        $this->get('/ses')->assertDontSee('さんから案件の質問');
        // 管理者の今やることには、会社の1本は出ない
        $this->as(self::ADMIN)->get('/admin')->assertOk()->assertDontSee('さんから案件の質問');
    }

    // ---------- N13：管理者は読むだけ ----------
    public function test_n13_admin_is_read_only(): void
    {
        $this->fPostsX();
        $before = [$this->posts(), $this->threads()];
        $mailsA = $this->mails(self::SA);
        $this->bad($this->send(self::ADMIN, $this->x, $this->f, '管理者から補足'), '読むだけ');
        $this->assertSame($before, [$this->posts(), $this->threads()]);
        $this->assertSame($mailsA, $this->mails(self::SA));
        $this->assertSame(0, DB::table('case_thread_posts')->where('from_side', 'admin')->count());
        $this->as(self::ADMIN)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ');
        $this->assertSame(0, $this->readSide());
        // 案件Y（持ち込み元なし）の会社の1本も、管理者は書けない
        $this->send(self::F_EMAIL, $this->y, $this->f, 'Yの質問');
        $this->bad($this->send(self::ADMIN, $this->y, $this->f, '管理者から補足Y'), '読むだけ');
        $this->assertSame(0, DB::table('case_thread_posts')->where('from_side', 'admin')->count());
    }

    // ---------- N14：持ち込み元が停止中 ----------
    public function test_n14_suspended_source_company_blocks_post(): void
    {
        DB::table('ses_companies')->where('id', $this->a)->update(['status' => 'suspended']);
        $this->bad($this->send(self::F_EMAIL, $this->x, $this->f, '停止中の相手に書く'), '案件を持ち込んだ会社が参加中ではない');
        $this->assertSame([0, 0], [$this->threads(), $this->posts()]);
        // 持ち込み元なしの案件は、これまでどおり書ける
        $this->send(self::F_EMAIL, $this->y, $this->f, '案件Yの質問')->assertSessionHas('flash.type', 'ok');
        // 既にあった1本は、停止後も EG・B・管理者が読める
        DB::table('ses_companies')->where('id', $this->a)->update(['status' => 'active']);
        $this->fPostsX();
        DB::table('ses_companies')->where('id', $this->a)->update(['status' => 'suspended']);
        $this->bad($this->send(self::F_EMAIL, $this->x, $this->f, '停止後の追記'), '案件を持ち込んだ会社が参加中ではない');
        foreach ([self::SB, self::ADMIN, self::F_EMAIL] as $who) {
            $this->as($who)->get($who === self::F_EMAIL ? "/cases/{$this->x}" : "/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ');
        }
    }

    // ---------- N15：v0.10.0 の1本は A に見せない ----------
    public function test_n15_v0100_thread_stays_hidden_from_source_company(): void
    {
        $tid = (int) DB::table('case_threads')->insertGetId(['case_id' => $this->x, 'engineer_id' => $this->f, 'ses_id' => $this->b, 'partner_ses_id' => null, 'last_post_at' => now_str(), 'created_at' => now_str()]);
        DB::table('case_thread_posts')->insert(['thread_id' => $tid, 'from_user_id' => null, 'from_side' => 'eg', 'from_label' => self::F_NAME, 'body' => '旧の質問 OLDQ', 'read_by_eg' => 1, 'read_by_side' => 0, 'created_at' => now_str()]);
        // A：案件の持ち込み元で、案内メールの記録もある。それでも旧の1本（相手は B のまま）は見せない
        foreach ([self::SA, self::A2] as $who) {
            $this->as($who);
            $r = $this->get("/cases/{$this->x}/threads/{$this->f}");
            // 実装は「1本が相手 B」の間は A の書き始めを許さず 404 のはず。200 のときは旧の発言が出ていないこと
            if ($r->status() === 200) $this->assertStringNotContainsString('OLDQ', $r->getContent(), '旧の1本が A に見えている');
            else $r->assertNotFound();
            $this->post("/cases/{$this->x}/threads/{$this->f}", ['body' => '割り込み']);
            $this->get('/threads')->assertOk()->assertDontSee('OLDQ')->assertDontSee('架空の案件X');
            $this->get("/cases/{$this->x}")->assertOk()->assertDontSee('OLDQ');
            $this->get('/ses')->assertOk()->assertDontSee('さんから案件の質問');
        }
        $this->assertSame(1, $this->posts(), 'A が旧の1本に書き込めてしまった');
        // マイグレーションの埋め方（空 → 所属の会社）を通しても同じ
        $this->assertNull(DB::table('case_threads')->where('id', $tid)->value('partner_ses_id'));
        (include base_path('database/migrations/2026_11_14_000002_case_threads_partner.php'))->up();
        $this->assertSame($this->b, (int) DB::table('case_threads')->where('id', $tid)->value('partner_ses_id'));
        $this->as(self::SA)->get('/threads')->assertOk()->assertDontSee('OLDQ');
        $this->post("/cases/{$this->x}/threads/{$this->f}", ['body' => '割り込み2']);
        $this->assertSame(1, $this->posts());
        // B は今までどおり書ける（相手は B のまま）。A には B の返事も見えない
        $this->send(self::SB, $this->x, $this->f, 'Bの返事 BREPLY')->assertSessionHas('flash.type', 'ok');
        $this->as(self::SA)->get('/threads')->assertOk()->assertDontSee('BREPLY');
        // park:status：持ち込み元と相手が違う旧の1本が 1 件
        Artisan::call('park:status', ['--json' => true]);
        $d = json_decode(Artisan::output(), true);
        $key = collect(array_keys($d))->first(fn ($k) => str_contains((string) $k, 'v0.10.1 より前'));
        $found = $key !== null ? $d[$key] : $this->findStatus($d);
        $this->assertSame(1, $found);
        // 新しい形の1本（partner=A）は数えない
        $this->fPostsX();
    }

    private function findStatus(array $d): mixed
    {
        foreach ($d as $k => $v) {
            if (is_array($v)) { $r = $this->findStatus($v); if ($r !== null) return $r; }
            elseif (str_contains((string) $k, 'v0.10.1 より前')) return $v;
        }
        return null;
    }

    public function test_n15b_status_zero_for_new_form(): void
    {
        $this->fPostsX();
        Artisan::call('park:status', ['--json' => true]);
        $d = json_decode(Artisan::output(), true);
        $this->assertSame(0, $this->findStatus($d));
    }

    // ---------- N16：見本の削除 ----------
    public function test_n16_purge_samples_resets_partner(): void
    {
        $this->fPostsX(); // 見本どうしの1本（案件X・F）は、案件ごと消える
        $aid = W::createUser('real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        $this->completeProfile($aid, ['company_name' => '実在運営']);
        $admin = User::find($aid);
        $direct = W::directSesId();
        $realEg = W::createEngineer($direct, ['name' => '実在 直属', 'email' => 'real-eg@example.com'], $admin);
        // setUp の架空の人は、見本ではない人が見本の会社に入っている形になり、消す前に止まる。メールを見本の形にして、見本として消えるようにする
        foreach ([self::A2, self::B2, self::C2, self::F_EMAIL, 'g-eg@example.test'] as $em) {
            DB::table('users')->where('email', $em)->update(['email' => str_replace('@example.test', '@park.example', $em)]);
            DB::table('engineers')->where('email', $em)->update(['email' => str_replace('@example.test', '@park.example', $em)]);
        }
        $realCase = W::saveCase(['title' => '実在の案件', 'price' => 70, 'status' => 'open'], $admin);
        DB::table('cases')->where('id', $realCase)->update(['source_ses_id' => $this->a]); // 見本の会社 A が持ち込んだ実在の案件
        $this->offer($realCase, $realEg);
        $tid = (int) DB::table('case_threads')->insertGetId(['case_id' => $realCase, 'engineer_id' => $realEg, 'ses_id' => $direct, 'partner_ses_id' => $this->a, 'last_post_at' => now_str(), 'created_at' => now_str()]);
        DB::table('case_thread_posts')->insert(['thread_id' => $tid, 'from_user_id' => null, 'from_side' => 'eg', 'from_label' => '実在 直属', 'body' => '実在の質問', 'read_by_eg' => 1, 'read_by_side' => 0, 'created_at' => now_str()]);

        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]), Artisan::output());
        $t = DB::table('case_threads')->find($tid);
        $this->assertNotNull($t, '残る1本が消えた');
        $this->assertSame($direct, (int) $t->partner_ses_id, '相手が所属の会社に戻っていない');
        $this->assertSame(1, DB::table('case_thread_posts')->where('thread_id', $tid)->count());
        $this->assertNull(DB::table('cases')->where('id', $realCase)->value('source_ses_id'));
        $this->assertSame(1, $this->threads());
        $files = array_values(array_diff(glob(storage_path('app/backups/purge-samples-*.json')) ?: [], $this->backupsBefore));
        $this->assertNotEmpty($files);
        $this->assertStringContainsString('case_threads.partner_ses_id', file_get_contents($files[0]));
    }

    // ---------- 追加：所属が変わったとき（R11） ----------
    public function test_extra_affiliation_change_keeps_partner_view(): void
    {
        $this->fPostsX();
        $this->as(self::ADMIN)->post("/engineers/{$this->f}/affiliation", ['ses_id' => $this->c])->assertRedirect();
        $this->assertSame($this->c, (int) DB::table('engineers')->where('id', $this->f)->value('ses_id'));
        // 持ち込み元 A は、前の1本を今までどおり読める。前の所属 B も読める（書けない）
        $this->as(self::SA)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ');
        $this->as(self::SB)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ');
        $this->bad($this->send(self::SB, $this->x, $this->f, '移籍後に書く'));
        // 新しい所属 C には、前の1本は見えない
        foreach ([self::SC, self::C2] as $who) {
            $r = $this->as($who)->get("/cases/{$this->x}/threads/{$this->f}");
            if ($r->status() === 200) $r->assertDontSee('SECRETQ'); else $r->assertNotFound();
            $this->get('/threads')->assertOk()->assertDontSee('SECRETQ');
        }
        // F が新しく書くと、新しい所属 C の1本ができ、相手は A のまま。A には前の1本と新しい1本の両方が見え、新しい1本には書ける
        $this->send(self::F_EMAIL, $this->x, $this->f, '移籍後の質問 NEWQ')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(2, DB::table('case_threads')->where('case_id', $this->x)->where('engineer_id', $this->f)->count());
        $new = DB::table('case_threads')->where('ses_id', $this->c)->first();
        $this->assertSame($this->a, (int) $new->partner_ses_id);
        $this->as(self::SA)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ')->assertSee('NEWQ');
        $this->send(self::SA, $this->x, $this->f, '新しい返事')->assertSessionHas('flash.type', 'ok');
        // 前の所属 B には、新しい1本が見えない。新しい所属 C は新しい1本を読むだけ
        $this->as(self::SB)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertDontSee('NEWQ');
        $r = $this->as(self::SC)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('NEWQ')->assertDontSee('SECRETQ');
        $this->bad($this->send(self::SC, $this->x, $this->f, 'C社が書く'), '読むだけ');
    }

    // ---------- 追加：EG 本人以外・兼任 ----------
    public function test_extra_other_engineers_and_dual_role(): void
    {
        $this->fPostsX();
        // 同じ会社の別の EG（G）は、F のやり取りを見られず、書けない
        $this->as('g-eg@example.test')->get("/cases/{$this->x}/threads/{$this->f}")->assertNotFound();
        $this->post("/cases/{$this->x}/threads/{$this->f}", ['body' => 'なりすまし'])->assertNotFound();
        $this->get("/cases/{$this->x}")->assertOk()->assertDontSee('SECRETQ');
        // EG は一覧を使えない
        $this->get('/threads')->assertForbidden();
        // 兼任：A の担当者が、B 所属の EG F を兼ねる場合、担当者の立場では F のやり取りに書けない
        $uid = W::createUser('dual@example.test', '兼任 太郎', 'ses', 'park1234', ['ses_id' => $this->a, 'engineer_id' => $this->f]);
        $this->completeProfile($uid);
        $this->bad($this->as('dual@example.test', 'ses')->post("/cases/{$this->x}/threads/{$this->f}", ['body' => '自分に返事']), 'ご自身のやり取り');
        $this->assertSame(1, $this->posts());
    }

    // ---------- 追加：二重押し・長さ・文字 ----------
    public function test_extra_double_post_and_length_with_partner(): void
    {
        $this->fPostsX('同じ文');
        $n = DB::table('app_notifications')->count();
        $this->send(self::F_EMAIL, $this->x, $this->f, '同じ文')->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->posts());
        $this->assertSame($n, DB::table('app_notifications')->count());
        $this->bad($this->send(self::SA, $this->x, $this->f, ''), '1〜2000');
        $this->bad($this->send(self::SA, $this->x, $this->f, str_repeat('あ', 2001)), '1〜2000');
        $this->assertSame(1, $this->posts());
    }

    // ---------- 追加：閉じた案件・応募 ----------
    public function test_extra_closed_case_read_only_for_partner(): void
    {
        $this->fPostsX();
        DB::table('cases')->where('id', $this->x)->update(['status' => 'closed']);
        $this->bad($this->send(self::SA, $this->x, $this->f, '終了後'), '募集を終えた');
        $this->bad($this->send(self::F_EMAIL, $this->x, $this->f, '終了後'), '募集を終えた');
        $this->as(self::SA)->get("/cases/{$this->x}/threads/{$this->f}")->assertOk()->assertSee('SECRETQ');
        $this->assertSame(1, $this->posts());
    }

    // ---------- 追加：案件が届いていない EG には書き始められない（A でも） ----------
    public function test_extra_no_offer_no_post_even_if_mailed_record_missing_offer(): void
    {
        // case_mails はあるが case_offers が無い EG（異常データ）には、書けない
        DB::table('case_offers')->where('case_id', $this->x)->where('engineer_id', $this->f)->delete();
        $r = $this->send(self::SA, $this->x, $this->f, '届いていない');
        $this->bad($r, '届いていない');
        $this->assertSame([0, 0], [$this->threads(), $this->posts()]);
    }

    // ---------- 版 ----------
    public function test_version(): void
    {
        $this->assertSame('v0.10.1', config('park.version'));
    }
}
