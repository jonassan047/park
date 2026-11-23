<?php

namespace Tests\Feature;

use App\Services\CaseSearch as CS;
use App\Services\Inbound;
use App\Services\ReplyTokens;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// v0.15.0 案件詳細とエンジニア選択の作り直し（PLAN.md の A1〜D4）。名前・本文・アドレスはすべて架空（@park.example / @example.test）
// プレーヤー：管理者・SES（受注 SES）・担当者（受注 SES と同じ会社）・他社 SES・EG
class V0150CaseDetailSelectTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const STAFF = 'staff150@example.test';
    private const EG1 = 'eg1@park.example';
    private const RAW = "架空のメール本文ABC\n■必須\n・Java\n・SQL\n■尚可\n・AWS";
    private const FROM = 'park@example.test';
    private const CHECKED = '/name="on\[(req|pref|confirm)\]\[\]"[^>]*checked/';

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mail.from.address' => self::FROM]);
        DB::table('engineers')->update(['status' => 'unavailable']); // テストで作る人だけで確かめる
        $id = W::createUser(self::STAFF, '担当 百五十郎', 'ses', 'park1234', ['ses_id' => $this->sesId(self::SES1)]);
        $this->completeProfile($id);
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    private function mkCase(array $over = []): int
    {
        return W::saveCase($over + ['title' => '十五テスト案件', 'price' => 60, 'required_skills' => 'Java、SQL', 'preferred_skills' => 'AWS',
            'confirm_items' => "稼働開始はいつからですか\n面談は平日夜でも可能ですか", 'description' => '架空の作業内容', 'status' => 'open', 'raw_text' => self::RAW], $this->user(self::SES1));
    }

    private function mkEng(string $sesEmail, string $name, string $initials, array $names, array $over = []): int
    {
        $id = W::createEngineer($this->sesId($sesEmail), ['name' => $name, 'kana' => 'てすと たろう', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str(), 'initials' => $initials,
            'status' => 'available', 'email' => "eg{$id}@example.test"]);
        SkillChecks::saveFromNames($id, array_map(fn ($n) => [$n, $n === 'SQL' ? 'db' : ($n === 'AWS' ? 'infra' : 'lang'), 12], $names), 'seed');
        return $id;
    }

    private function cond(int $cid, string $email = self::SES1): array
    {
        $u = $this->user($email);
        $s = DB::table('case_searches')->where('case_id', $cid)->where('created_by', $u->id)->first();
        return CS::conditions(DB::table('cases')->find($cid), $s);
    }

    // 全項目オンの kw フォーム。$off は ['req' => [名前…], 'confirm' => [名前…]]（外す項目の名前）
    private function form(int $cid, array $off = [], array $add = [], string $email = self::SES1): array
    {
        $cond = $this->cond($cid, $email);
        $on = [];
        foreach (['req', 'pref', 'confirm'] as $side) {
            $on[$side] = [];
            foreach ($cond[$side] as $it) if (!in_array($it['name'], $off[$side] ?? [], true)) $on[$side][] = CS::key($it);
        }
        return ['kw' => 1, 'on' => $on] + $add;
    }

    private function propose(int $cid, ?array $post = null, string $email = self::SES1): int
    {
        $this->as($email)->post("/cases/$cid/search", $post ?? $this->form($cid, [], [], $email))->assertRedirect();
        return (int) DB::table('case_searches')->where('case_id', $cid)->where('created_by', $this->user($email)->id)->value('id');
    }

    private function enableInbound(): void
    {
        config(['park.inbound.host' => 'imap.example.test', 'park.inbound.user' => 'x', 'park.inbound.password' => 'secret-pass']);
    }

    private function eml(string $from, string $to, string $subject, string $body, array $extra = []): string
    {
        $h = ['From' => $from, 'To' => $to, 'Subject' => $subject, 'Date' => date('r'), 'Message-ID' => '<'.bin2hex(random_bytes(8)).'@mail.example.test>',
            'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => '8bit'];
        $lines = [];
        foreach (array_merge($h, $extra) as $k => $v) $lines[] = "$k: $v";
        return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
    }

    private function inb(int $id): object
    {
        return DB::table('inbound_mails')->find($id);
    }

    // ブラウザと同じく、画面でチェックが入っている（未送信の）追加の宛先を xpick に入れて送る
    private function sendChecked(int $sid, string $as = self::SES1): void
    {
        $x = DB::table('case_search_extras')->where('search_id', $sid)->whereNull('sent_at')->where('checked', true)->pluck('id')->all();
        $this->as($as)->post("/searches/$sid/send", ['xpick' => $x])->assertRedirect();
    }

    // 追加の宛先を1件足して送り、行を返す
    private function sendExtra(int $sid, string $addr = 'outsider@example.test', string $as = self::SES1): object
    {
        $this->as($as)->post("/searches/$sid/extras", ['extra_text' => "架空 宛先 <$addr>"])->assertRedirect();
        $this->sendChecked($sid, $as);
        return DB::table('case_search_extras')->where('search_id', $sid)->where('email', strtolower($addr))->first();
    }

    // ---------- A1〜A8：案件詳細 ----------
    public function test_a1_a8_source_staff_sees_new_layout(): void
    {
        $cid = $this->mkCase();
        foreach ([self::SES1, self::STAFF] as $who) {
            $r = $this->as($who)->get("/cases/$cid")->assertOk();
            $r->assertSee('メール本文')->assertSee('架空のメール本文ABC')->assertSee('案件票（読み取った項目）')->assertDontSee('案内済メール')->assertDontSee('プロバイダーへの返信')->assertDontSee('この案件への応募') // 送る前・返信前・応募前は出さない
                ->assertSee('AI の読み直し')->assertSee('エンジニアを検索')->assertSee('id="keywords"', false)->assertSee('エンジニアを検索')
                ->assertSee('エンジニアに確認')->assertSee('稼働開始はいつからですか')->assertSee('追加');
            $html = $r->getContent();
            $this->assertLessThan(strpos($html, 'エンジニアを検索'), strpos($html, 'AI の読み直し'), 'AI がキーワードより上');
            $this->assertStringNotContainsString('エンジニアとの相性', $html); // A8
            $this->assertSame(5, preg_match_all(self::CHECKED, $html), '既定は全部オン（必須2・尚可1・確認2）');
            $this->assertStringContainsString('name="kw"', $html);
            foreach (['add_req', 'add_pref', 'add_confirm'] as $n) $this->assertStringContainsString('name="'.$n.'"', $html);
        }
    }

    public function test_a9_others_do_not_see_mail_body_or_keywords(): void
    {
        $cid = $this->mkCase();
        foreach ([self::ADMIN, self::SES2, self::EG1] as $who) {
            $res = $this->as($who)->get("/cases/$cid");
            if ($res->getStatusCode() !== 200) { $this->assertContains($res->getStatusCode(), [403, 404]); continue; }
            $res->assertDontSee('架空のメール本文ABC')->assertDontSee('エンジニアを検索')->assertDontSee('エンジニアを検索')->assertDontSee('id="keywords"', false);
        }
        $this->as(self::ADMIN)->get("/cases/$cid/search")->assertForbidden();
        $this->as(self::SES2)->get("/cases/$cid/search")->assertForbidden();
        $this->as(self::EG1)->get("/cases/$cid/search")->assertForbidden();
        $this->as(self::ADMIN)->post("/cases/$cid/keywords", ['kw' => 1])->assertForbidden();
        $this->as(self::SES2)->post("/cases/$cid/keywords", ['kw' => 1])->assertForbidden();
        $this->as(self::EG1)->post("/cases/$cid/search", ['kw' => 1])->assertForbidden();
        $this->assertSame(0, DB::table('case_searches')->count());
    }

    public function test_a10_get_search_redirects_to_keywords(): void
    {
        $cid = $this->mkCase();
        $this->as(self::SES1)->get("/cases/$cid/search")->assertRedirect("/cases/$cid#keywords");
        $this->as(self::STAFF)->get("/cases/$cid/search")->assertRedirect("/cases/$cid#keywords");
        DB::table('cases')->where('id', $cid)->update(['status' => 'closed']);
        $this->as(self::SES1)->get("/cases/$cid/search")->assertRedirect(url("/cases/$cid"));
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('募集を終えた案件です');
    }

    public function test_empty_raw_text_shows_notice(): void
    {
        $cid = $this->mkCase(['raw_text' => '']);
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('元のメールはありません。');
    }

    // ---------- キーワードの保存・検索 ----------
    public function test_uncheck_removes_from_judge_and_stays_unchecked(): void
    {
        $cid = $this->mkCase();
        $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $javaOnly = $this->mkEng(self::SES1, '一部 次郎', 'I.J', ['Java']);
        $sid = $this->propose($cid, $this->form($cid, ['req' => ['SQL']]));
        $this->assertFalse((bool) collect($this->cond($cid)['req'])->firstWhere('name', 'SQL')['on']);
        $rows = collect(CS::rows($this->user(self::SES1), DB::table('case_searches')->find($sid))['rows']);
        $this->assertTrue((bool) $rows->first(fn ($r) => $r['e']->id === $javaOnly)['pass'], 'SQL を外したので Java だけで通る');
        $this->assertSame(1, (int) $rows->first(fn ($r) => $r['e']->id === $javaOnly)['j']['n'], '判定の件数は外した分を数えない');
        // 案件画面のチェックも外れたまま
        $html = $this->as(self::SES1)->get("/cases/$cid")->getContent();
        $this->assertSame(4, preg_match_all(self::CHECKED, $html));
        // 案件そのものは変わらない
        $row = DB::table('cases')->find($cid);
        $this->assertStringContainsString('SQL', (string) $row->required_skills);
        $this->assertSame("稼働開始はいつからですか\n面談は平日夜でも可能ですか", $row->confirm_items);
    }

    public function test_previous_off_stays_off_on_next_search(): void
    {
        $cid = $this->mkCase();
        $this->propose($cid, $this->form($cid, ['req' => ['SQL'], 'confirm' => ['稼働開始はいつからですか']]));
        $this->as(self::SES1)->post("/cases/$cid/search", [])->assertRedirect(); // kw 無し＝前回のまま
        $c = $this->cond($cid);
        $this->assertFalse((bool) collect($c['req'])->firstWhere('name', 'SQL')['on']);
        $this->assertFalse((bool) collect($c['confirm'])->firstWhere('name', '稼働開始はいつからですか')['on']);
        $this->assertTrue((bool) collect($c['req'])->firstWhere('name', 'Java')['on']);
        $this->assertTrue((bool) collect($c['confirm'])->firstWhere('name', '面談は平日夜でも可能ですか')['on']);
    }

    public function test_add_items_saved_and_case_unchanged(): void
    {
        $cid = $this->mkCase();
        $before = (array) DB::table('cases')->find($cid);
        $this->as(self::SES1)->post("/cases/$cid/keywords", $this->form($cid, [], ['add_req' => 'Docker', 'add_pref' => 'Oracle', 'add_confirm' => "英語は使えますか\n服装は自由ですか"]))
            ->assertRedirect("/cases/$cid#keywords");
        $c = $this->cond($cid);
        $this->assertContains('Docker', array_column($c['req'], 'name'));
        $this->assertContains('Oracle', array_column($c['pref'], 'name'));
        $this->assertContains('英語は使えますか', array_column($c['confirm'], 'name'));
        $this->assertContains('服装は自由ですか', array_column($c['confirm'], 'name'));
        $this->assertSame(0, DB::table('case_search_members')->count(), '「追加」は検索を走らせない');
        $after = (array) DB::table('cases')->find($cid);
        foreach (['required_skills', 'preferred_skills', 'confirm_items', 'raw_text', 'title'] as $k) $this->assertSame($before[$k], $after[$k], $k);
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('英語は使えますか')->assertSee('足した');
        // 同じ項目は二重に足さない
        $this->as(self::SES1)->post("/cases/$cid/keywords", $this->form($cid, [], ['add_confirm' => '英語は使えますか']))->assertRedirect();
        $this->assertSame(1, count(array_filter($this->cond($cid)['confirm'], fn ($i) => $i['name'] === '英語は使えますか')));
    }

    public function test_confirm_add_not_split_by_comma_and_length_capped(): void
    {
        $cid = $this->mkCase();
        $this->as(self::SES1)->post("/cases/$cid/keywords", $this->form($cid, [], ['add_confirm' => '英語、日本語は可能ですか']))->assertRedirect();
        $this->assertContains('英語、日本語は可能ですか', array_column($this->cond($cid)['confirm'], 'name'));
        $this->as(self::SES1)->post("/cases/$cid/keywords", $this->form($cid, [], ['add_confirm' => str_repeat('あ', 300)]))->assertRedirect();
        foreach ($this->cond($cid)['confirm'] as $it) $this->assertLessThanOrEqual(200, mb_strlen($it['name']));
    }

    public function test_other_staff_search_is_separate(): void
    {
        $cid = $this->mkCase();
        $this->as(self::SES1)->post("/cases/$cid/keywords", $this->form($cid, ['req' => ['SQL']]))->assertRedirect();
        $c2 = $this->cond($cid, self::STAFF);
        $this->assertTrue((bool) collect($c2['req'])->firstWhere('name', 'SQL')['on'], '担当者の検索は、SES1 の外した状態の影響を受けない');
    }

    // ---------- B：選択画面 ----------
    public function test_b_select_screen(): void
    {
        $cid = $this->mkCase();
        $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL', 'AWS']);
        $this->mkEng(self::SES1, '一部 次郎', 'I.J', ['Java']);
        $sid = $this->propose($cid);
        $r = $this->as(self::SES1)->get("/searches/$sid")->assertOk();
        $r->assertSee('エンジニアを選択')->assertSee('すべてを選択')->assertSee('id="pickall"', false)->assertSee('マッチした点')->assertSee('マッチしなかった点')
            ->assertSee('点数')->assertSee('必須 2/2')->assertSee('尚可 1/1')->assertSee('メールアドレスを追加')->assertSee('エンジニアを追加')
            ->assertSee('エンジニアへメールを送信');
        $this->assertMatchesRegularExpression('/<b>必須<\/b> Java、SQL/', $r->getContent());
        foreach ([self::ADMIN, self::SES2, self::EG1] as $who) $this->as($who)->get("/searches/$sid")->assertForbidden();
    }

    public function test_b_nobody_checked_is_refused(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid);
        $this->as(self::SES1)->post("/searches/$sid", ['pick' => [], 'xpick' => []])->assertRedirect();
        $this->assertSame(0, (int) DB::table('case_search_members')->where('search_id', $sid)->where('engineer_id', $a)->value('checked'));
        $this->as(self::SES1)->post("/searches/$sid/send", [])->assertRedirect()->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '送る相手を選んでください'));
        $this->assertSame(0, DB::table('case_mails')->count());
    }

    // ---------- C1：メールの確認事項 ----------
    public function test_c1_mail_contains_checked_confirm_items_only(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid, $this->form($cid, ['confirm' => ['面談は平日夜でも可能ですか']]));
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$a]])->assertRedirect();
        $cm = DB::table('case_mails')->where('case_id', $cid)->where('engineer_id', $a)->first();
        $this->assertStringContainsString('必ずご返信ください', $cm->body);
        $this->assertStringContainsString('・稼働開始はいつからですか', $cm->body);
        $this->assertStringNotContainsString('面談は平日夜', $cm->body);
        $this->assertSame('稼働開始はいつからですか', $cm->confirm_items);
        $this->assertStringNotContainsString('{', $cm->body);
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $cm->body);
    }

    public function test_c1_all_confirm_items_included_by_default(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid);
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$a]])->assertRedirect();
        $b = DB::table('case_mails')->where('case_id', $cid)->value('body');
        $this->assertStringContainsString('稼働開始はいつからですか', $b);
        $this->assertStringContainsString('面談は平日夜でも可能ですか', $b);
        $this->assertStringContainsString('必ずご返信ください', $b);
    }

    public function test_c1_zero_checked_has_no_section(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid, $this->form($cid, ['confirm' => ['稼働開始はいつからですか', '面談は平日夜でも可能ですか']]));
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$a]])->assertRedirect();
        $cm = DB::table('case_mails')->where('case_id', $cid)->first();
        $this->assertStringNotContainsString('ご確認ください', $cm->body);
        $this->assertStringNotContainsString('必ずご返信', $cm->body);
        $this->assertNull($cm->confirm_items);
        // 案件に確認事項が無いときも節は出ない
        $cid2 = $this->mkCase(['confirm_items' => '', 'title' => '確認なし案件']);
        $sid2 = $this->propose($cid2);
        $this->as(self::SES1)->post("/searches/$sid2/send", ['pick' => [$a]])->assertRedirect();
        $this->assertStringNotContainsString('ご確認ください', DB::table('case_mails')->where('case_id', $cid2)->value('body'));
    }

    public function test_c1_confirm_does_not_affect_pass(): void
    {
        $cid = $this->mkCase();
        $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid);
        $row = CS::rows($this->user(self::SES1), DB::table('case_searches')->find($sid))['rows'][0];
        $this->assertTrue((bool) $row['pass']);
        $this->assertSame(2, (int) $row['j']['n']);
    }

    // ---------- C2：返事のリンク ----------
    private function sentToken(int $cid, int $sid, int $eid): string
    {
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$eid]])->assertRedirect();
        return (string) DB::table('case_mails')->where('case_id', $cid)->where('engineer_id', $eid)->value('token');
    }

    public function test_c2_reply_note_required_when_confirm_items(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $tok = $this->sentToken($cid, $this->propose($cid), $a);
        $this->flushSession();
        $this->get("/r/$tok/interested")->assertOk()->assertSee('お答えが必須です')->assertSee('稼働開始はいつからですか');
        $this->post("/r/$tok", ['answer' => 'interested', 'note' => ''])->assertRedirect("/r/$tok/interested")->assertSessionHasErrors('note');
        $this->post("/r/$tok", ['answer' => 'declined', 'note' => '  '])->assertSessionHasErrors('note'); // 見送りでも必須（仮置き）
        $this->assertNull(DB::table('case_mails')->where('token', $tok)->value('replied_at'), '空のひとことでは記録されない');
        $this->post("/r/$tok", ['answer' => 'interested', 'note' => '来月からです'])->assertOk()->assertSee('ご返事を受け付けました');
    }

    public function test_c2_reply_note_optional_without_confirm_items(): void
    {
        $cid = $this->mkCase(['confirm_items' => '']);
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $tok = $this->sentToken($cid, $this->propose($cid), $a);
        $this->flushSession();
        $this->get("/r/$tok/interested")->assertOk()->assertDontSee('お答えが必須です');
        $this->post("/r/$tok", ['answer' => 'interested', 'note' => ''])->assertOk()->assertSee('ご返事を受け付けました');
    }

    public function test_c2_confirm_all_unchecked_means_optional(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid, $this->form($cid, ['confirm' => ['稼働開始はいつからですか', '面談は平日夜でも可能ですか']]));
        $tok = $this->sentToken($cid, $sid, $a);
        $this->flushSession();
        $this->post("/r/$tok", ['answer' => 'interested', 'note' => ''])->assertOk()->assertSee('ご返事を受け付けました');
    }

    // ---------- D1：追加の宛先を断る ----------
    public function test_d1_add_extras_rejects_bad_ones(): void
    {
        $cid = $this->mkCase();
        $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL'], ['email' => 'registered-eg@example.test']);
        $this->mkEng(self::SES2, '他社 花子', 'H.T', ['Java'], ['email' => 'other-eg@example.test']);
        $sid = $this->propose($cid);
        $text = implode("\n", ['not-an-address', 'ok1@example.test', 'OK1@Example.test', 'Registered-EG@example.test', 'other-eg@example.test', self::FROM, 'park+abcdef@example.test', '山田 <ok2@example.test>']);
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => $text])->assertRedirect("/searches/$sid#extras");
        $this->assertSame(['ok1@example.test', 'ok2@example.test'], DB::table('case_search_extras')->where('search_id', $sid)->orderBy('id')->pluck('email')->all());
        $this->assertSame('山田', DB::table('case_search_extras')->where('email', 'ok2@example.test')->value('name'));
        $this->assertSame(1, (int) DB::table('case_search_extras')->where('email', 'ok1@example.test')->value('checked'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'case-search-extra-add')->exists());
        $this->assertFalse(DB::table('audit_log')->where('action', 'case-search-extra-add')->where('detail', 'like', '%@%')->exists(), '監査にアドレスを書かない');
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('ok1@example.test')->assertSee('宛先に足す');
    }

    public function test_d1_limits_10_per_time_and_30_per_search(): void
    {
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $lines = fn ($from, $n) => implode("\n", array_map(fn ($i) => "u{$i}@example.test", range($from, $from + $n - 1)));
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => $lines(1, 12)])->assertRedirect();
        $this->assertSame(10, DB::table('case_search_extras')->where('search_id', $sid)->count());
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => $lines(100, 10)]);
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => $lines(200, 10)]);
        $this->assertSame(30, DB::table('case_search_extras')->where('search_id', $sid)->count());
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => 'last@example.test']);
        $this->assertSame(30, DB::table('case_search_extras')->where('search_id', $sid)->count());
    }

    public function test_d1_others_cannot_add_or_open(): void
    {
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        foreach ([self::ADMIN, self::SES2, self::EG1] as $who) {
            $this->as($who)->post("/searches/$sid/extras", ['extra_text' => 'x@example.test'])->assertForbidden();
        }
        $this->assertSame(0, DB::table('case_search_extras')->count());
        $this->as(self::STAFF)->get("/searches/$sid")->assertOk(); // 同じ会社の担当者は開ける（今の決まり）
        $this->as(self::ADMIN)->get("/searches/$sid")->assertForbidden();
    }

    public function test_remove_extra_only_unsent(): void
    {
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => "a1@example.test\na2@example.test"]);
        $a1 = DB::table('case_search_extras')->where('email', 'a1@example.test')->value('id');
        $a2 = DB::table('case_search_extras')->where('email', 'a2@example.test')->value('id');
        $this->as(self::SES1)->post("/searches/$sid/extras/$a1/remove")->assertRedirect();
        $this->assertNull(DB::table('case_search_extras')->find($a1));
        $this->as(self::SES1)->post("/searches/$sid/send", ['xpick' => [$a2]])->assertRedirect();
        $this->as(self::SES1)->post("/searches/$sid/extras/$a2/remove")->assertRedirect();
        $this->assertNotNull(DB::table('case_search_extras')->find($a2), '送った行は消えない');
        $this->as(self::SES2)->post("/searches/$sid/extras/$a2/remove")->assertForbidden();
    }

    // ---------- D2：送信 ----------
    public function test_d2_extra_send_sets_sent_at_once_and_body_ok(): void
    {
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $x = $this->sendExtra($sid);
        $this->assertNotNull($x->sent_at);
        $this->assertNotNull($x->reply_token_id);
        $this->assertSame(0, (int) $x->checked);
        $this->assertStringContainsString('架空 宛先 様', $x->body);
        $this->assertStringContainsString('必ずご返信ください', $x->body);
        $this->assertStringContainsString('稼働開始はいつからですか', $x->body);
        $this->assertStringContainsString('案内番号：PARK-', $x->body);
        $this->assertStringNotContainsString('{', $x->body);
        $this->assertStringContainsString('[PARK-', (string) DB::table('mail_outbox')->where('id', $x->outbox_id)->value('subject'));
        $this->assertSame('outsider@example.test', DB::table('mail_outbox')->where('id', $x->outbox_id)->value('to_email'));
        $this->assertTrue(DB::table('audit_log')->where('action', 'case-search-extra-send')->exists());
        $this->assertSame(0, DB::table('case_mails')->count(), 'case_mails は作らない');
        // 二重に押しても、もう1通出ない
        $this->sendChecked($sid);
        $this->as(self::SES1)->post("/searches/$sid/send", ['xpick' => [$x->id]])->assertRedirect();
        $this->assertSame(1, DB::table('mail_outbox')->where('kind', 'case-search-extra')->count());
        $this->assertSame($x->sent_at, DB::table('case_search_extras')->find($x->id)->sent_at);
        // 送った宛先は、同じ案件にもう足せない
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => 'outsider@example.test']);
        $this->assertSame(1, DB::table('case_search_extras')->count());
    }

    public function test_d2_unchecked_extra_is_not_sent_and_mixed_send(): void
    {
        $cid = $this->mkCase();
        $a = $this->mkEng(self::SES1, '満点 太郎', 'M.T', ['Java', 'SQL']);
        $sid = $this->propose($cid);
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => "x1@example.test\nx2@example.test"]);
        $x1 = DB::table('case_search_extras')->where('email', 'x1@example.test')->value('id');
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$a], 'xpick' => [$x1]])->assertRedirect();
        $this->assertNotNull(DB::table('case_search_extras')->find($x1)->sent_at);
        $this->assertNull(DB::table('case_search_extras')->where('email', 'x2@example.test')->value('sent_at'));
        $this->assertSame(1, DB::table('case_mails')->count());
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('追加の宛先')->assertSee('x1@example.test')->assertDontSee('x2@example.test');
    }

    // M3：別の担当者の検索で、同じアドレスに送らない
    public function test_m3_no_resend_from_other_staffs_search(): void
    {
        $cid = $this->mkCase();
        $sid1 = $this->propose($cid, null, self::SES1);
        $sid2 = $this->propose($cid, null, self::STAFF);
        // 両方の検索に送る前から同じアドレスを入れておく
        $this->as(self::SES1)->post("/searches/$sid1/extras", ['extra_text' => 'later@example.test']);
        $this->as(self::STAFF)->post("/searches/$sid2/extras", ['extra_text' => 'later@example.test']);
        $this->sendChecked($sid1, self::SES1);
        $this->sendChecked($sid2, self::STAFF);
        $this->assertSame(1, DB::table('mail_outbox')->where('kind', 'case-search-extra')->where('to_email', 'later@example.test')->count());
        $this->assertSame(0, (int) DB::table('case_search_extras')->where('search_id', $sid2)->value('checked'));
        $this->assertNull(DB::table('case_search_extras')->where('search_id', $sid2)->value('sent_at'));
        // 送ったあとに別の担当者が足そうとしても足せない
        $this->as(self::STAFF)->post("/searches/$sid2/extras", ['extra_text' => 'later@example.test']);
        $this->assertSame(1, DB::table('case_search_extras')->where('search_id', $sid2)->count());
    }

    public function test_d3_no_reply_destination_sends_nothing(): void
    {
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $this->as(self::SES1)->post("/searches/$sid/extras", ['extra_text' => 'z@example.test']);
        DB::table('users')->where('email', self::SES1)->update(['email' => '']);
        $u = DB::table('users')->where('email', '')->first();
        $r = CS::sendExtras($u, DB::table('cases')->find($cid), DB::table('case_searches')->find($sid));
        $this->assertSame(['sent' => 0, 'dup' => 0, 'noReply' => true], $r);
        $this->assertNull(DB::table('case_search_extras')->where('email', 'z@example.test')->value('sent_at'));
    }

    // ---------- C3〜C5：受信の紐付けと見える範囲 ----------
    public function test_c3_reply_links_to_extra_and_visibility(): void
    {
        $this->enableInbound();
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $x = $this->sendExtra($sid, 'outsider@example.test');
        $tok = DB::table('reply_tokens')->find($x->reply_token_id);
        $this->assertSame('search-extra', $tok->kind);
        $mid = DB::table('mail_outbox')->where('id', $x->outbox_id)->value('message_id');
        // ① 宛先トークン
        $r1 = Inbound::ingest($this->eml('outsider@example.test', 'park+'.$tok->token.'@example.test', 'Re: 案件', '興味があります。'), 'file');
        $m = $this->inb($r1['id']);
        $this->assertSame(['linked', 'token', 'extra', (int) $x->id, $cid], [$m->status, $m->match_by, $m->sender_kind, (int) $m->search_extra_id, (int) $m->case_id]);
        $this->assertNull($m->engineer_id);
        $this->assertSame($this->sesId(self::SES1), (int) $m->ses_id);
        $this->assertFalse((bool) $m->from_mismatch);
        // ② In-Reply-To
        $r2 = Inbound::ingest($this->eml('outsider@example.test', 'park@example.test', 'Re: 案件', '質問です。', ['In-Reply-To' => '<'.$mid.'>']), 'file');
        $this->assertSame(['linked', 'in_reply_to', 'extra'], [$this->inb($r2['id'])->status, $this->inb($r2['id'])->match_by, $this->inb($r2['id'])->sender_kind]);
        // ③ 件名の印＋同じ差出人／違う差出人は未紐付け
        $mark = ReplyTokens::subjectMark($tok->token);
        $r3 = Inbound::ingest($this->eml('outsider@example.test', 'park@example.test', "Re: 案件 $mark", '三通目'), 'file');
        $this->assertSame(['linked', 'subject'], [$this->inb($r3['id'])->status, $this->inb($r3['id'])->match_by]);
        $r4 = Inbound::ingest($this->eml('stranger@example.test', 'park@example.test', "Re: 案件 $mark", '四通目'), 'file');
        $this->assertSame('unlinked', $this->inb($r4['id'])->status);

        // 受注 SES の担当者全員にお知らせ
        foreach ([self::SES1, self::STAFF] as $who) {
            $this->assertGreaterThan(0, DB::table('app_notifications')->where('user_id', $this->user($who)->id)->where('title', 'like', '追加の宛先から案内メールへの返信%')->count(), $who);
        }
        // 見える：受注 SES の SES・担当者（差出人は伏せない）。案件詳細に件数リンク
        foreach ([self::SES1, self::STAFF] as $who) {
            $this->as($who)->get('/inbound/'.$r1['id'])->assertOk()->assertSee('outsider@example.test')->assertSee('興味があります。');
            $this->as($who)->get('/inbound?case='.$cid.'&extra='.$x->id)->assertOk()->assertSee('outsider@example.test');
            $this->as($who)->get("/cases/$cid")->assertOk()->assertSee('返信メール 3 件')->assertSee("extra={$x->id}", false);
        }
        // 見えない：他社 SES・EG
        $this->as(self::SES2)->get('/inbound/'.$r1['id'])->assertNotFound();
        $this->as(self::SES2)->get('/inbound?extra='.$x->id)->assertOk()->assertDontSee('outsider@example.test');
        $res = $this->as(self::EG1)->get('/inbound/'.$r1['id']);
        $this->assertContains($res->getStatusCode(), [403, 404]);
        $res = $this->as(self::EG1)->get('/inbound');
        if ($res->getStatusCode() === 200) $res->assertDontSee('outsider@example.test');
        $this->as(self::SES2)->get("/cases/$cid")->assertDontSee('outsider@example.test')->assertDontSee('追加の宛先');
        $res = $this->as(self::EG1)->get("/cases/$cid");
        if ($res->getStatusCode() === 200) $res->assertDontSee('outsider@example.test')->assertDontSee('追加の宛先');
        // 管理者：読むだけ（案件詳細の表に件数。リンクや操作は無い）。受信一覧は読める
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertSee('追加の宛先')->assertSee('outsider@example.test')->assertSee('返信メール 3 件')->assertDontSee("extra={$x->id}", false);
        $this->as(self::ADMIN)->get('/admin/inbound')->assertOk();
    }

    public function test_c3_address_case_insensitive(): void
    {
        $this->enableInbound();
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $x = $this->sendExtra($sid, 'Mixed@Example.test');
        $this->assertSame('mixed@example.test', $x->email);
        $mark = ReplyTokens::subjectMark(DB::table('reply_tokens')->find($x->reply_token_id)->token);
        $r = Inbound::ingest($this->eml('Mixed@Example.test', 'park@example.test', "Re: 案件 $mark", '大文字の差出人'), 'file');
        $this->assertSame('linked', $this->inb($r['id'])->status);
    }

    public function test_c5_inbound_follows_current_source_ses(): void
    {
        $this->enableInbound();
        $cid = $this->mkCase();
        $sid = $this->propose($cid);
        $x = $this->sendExtra($sid);
        $tok = DB::table('reply_tokens')->find($x->reply_token_id);
        $r = Inbound::ingest($this->eml('outsider@example.test', 'park+'.$tok->token.'@example.test', 'Re: x', 'こんにちは'), 'file');
        $this->as(self::SES2)->get('/inbound/'.$r['id'])->assertNotFound();
        DB::table('cases')->where('id', $cid)->update(['source_ses_id' => $this->sesId(self::SES2)]); // 受注 SES が移った
        $this->as(self::SES1)->get('/inbound/'.$r['id'])->assertNotFound();
        $this->as(self::SES2)->get('/inbound/'.$r['id'])->assertOk();
    }

    // ---------- M1：自社 EG の行にも「やり取り」 ----------
    public function test_m1_own_eg_row_has_thread_link(): void
    {
        $cid = $this->mkCase();
        $own = $this->mkEng(self::SES1, '自社 太郎', 'J.T', ['Java', 'SQL']);
        $other = $this->mkEng(self::SES2, '他社 花子', 'H.T', ['Java', 'SQL']);
        $sid = $this->propose($cid);
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$own, $other]])->assertRedirect();
        $html = $this->as(self::SES1)->get("/cases/$cid")->assertOk()->getContent();
        $this->assertStringContainsString("/cases/$cid/threads/$own", $html, '自社 EG の行に「やり取り」');
        $this->assertStringNotContainsString('他社 花子', $html);
        $this->as(self::STAFF)->get("/cases/$cid")->assertOk()->assertSee("/cases/$cid/threads/$own", false);
    }
}
