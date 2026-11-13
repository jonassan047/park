<?php

namespace Tests\Feature;

use App\Services\CaseSearch as CS;
use App\Services\Matcher;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.7.0 エンジニア検索（PLAN.md 6章 T1〜T23）。メール本文・名前・会社は、テストの中で作る架空のものだけ
class V070SearchTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const SES3 = 'ses3@park.example';

    protected function setUp(): void
    {
        parent::setUp();
        // 見本のエンジニアは、対象から外す（テストで作る人だけで確かめる）
        DB::table('engineers')->update(['status' => 'unavailable']);
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    // 案件（持ち込んだ人のメールで登録）。既定は SES1 の案件で、必須 Java・SQL、尚可 AWS
    private function mkCase(string $email = self::SES1, array $over = []): int
    {
        return W::saveCase($over + ['title' => '検索テスト用の案件', 'price' => 60, 'required_skills' => 'Java、SQL', 'preferred_skills' => 'AWS',
            'description' => '架空の作業内容', 'status' => 'open', 'raw_text' => "架空のメール本文です\n■必須\n・Java\n・SQL"], $this->user($email));
    }

    // 本登録が済んだ形のエンジニア。$skills は [[名前, 分類, 年数]…]
    private function mkEng(string $sesEmail, string $name, string $initials, array $skills, array $over = []): int
    {
        $id = W::createEngineer($this->sesId($sesEmail), ['name' => $name, 'kana' => 'てすと たろう', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        // メールアドレスが無いと案内メールの行が作られないので、架空のものを入れる
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str(), 'initials' => $initials,
            'status' => 'available', 'email' => "eg{$id}@example.test"]);
        if ($skills) SkillChecks::saveFromNames($id, $skills, 'seed');
        return $id;
    }

    private function sk(string ...$names): array
    {
        return array_map(fn ($n) => [$n, $n === 'SQL' || $n === 'Oracle' ? 'db' : ($n === 'Docker' || $n === 'AWS' ? 'infra' : 'lang'), 12], $names);
    }

    private function search(string $as, int $cid, array $post = []): int
    {
        $this->as($as)->post("/cases/$cid/search", $post)->assertRedirect();
        return (int) DB::table('case_searches')->where('case_id', $cid)->orderByDesc('id')->value('id');
    }

    private function c(int $cid): object
    {
        return DB::table('cases')->find($cid);
    }

    private function names(array $rows): array
    {
        return array_map(fn ($r) => $r['e']->id, $rows);
    }

    private function setMessage(string $as, int $sid, int $eid, string $msg, bool $omit = false): void
    {
        $this->as($as)->post("/searches/$sid/members/$eid", ['message' => $msg] + ($omit ? ['omit_note' => 1] : []))->assertRedirect();
    }

    public function test_t1_pass_rule_boundaries(): void
    {
        foreach ([[0, 0, true], [1, 0, false], [1, 1, true], [2, 1, false], [2, 2, true], [3, 2, false], [3, 3, true], [4, 2, false], [4, 3, true],
            [5, 3, false], [5, 4, true], [8, 5, false], [8, 6, true]] as [$n, $h, $ok]) {
            $this->assertSame($ok, CS::passes($h, $n), "n=$n h=$h");
        }
    }

    public function test_t2_pool_and_own(): void
    {
        $cid = $this->mkCase();
        $own = $this->mkEng(self::SES1, '自社 一郎', 'J.I', $this->sk('Java'));
        $working = $this->mkEng(self::SES1, '稼働 二郎', 'K.J', $this->sk('Java'), ['status' => 'working']);
        $gone = $this->mkEng(self::SES1, '休止 三郎', 'K.S', $this->sk('Java'), ['status' => 'unavailable']);
        $other = $this->mkEng(self::SES2, '他社 四郎', 'T.S', $this->sk('Java'));
        $direct = $this->mkEng(self::SES3, '直属 五郎', 'C.G', $this->sk('Java'));
        DB::table('ses_companies')->where('id', $this->sesId(self::SES3))->update(['kind' => 'direct']);
        $pendingSes = DB::table('ses_companies')->where('status', 'pending')->value('id');
        $stopped = $this->mkEng(self::SES1, '停止 六郎', 'T.R', $this->sk('Java'));
        DB::table('engineers')->where('id', $stopped)->update(['ses_id' => $pendingSes]);

        $as = fn ($email) => collect(CS::pool($this->user($email), $this->c($cid)))->mapWithKeys(fn ($m) => [$m['engineer']->id => $m['own']])->all();
        $ses = $as(self::SES1);
        $this->assertSame([true, true, false, false], [$ses[$own], $ses[$working], $ses[$other], $ses[$direct]]);
        $this->assertArrayNotHasKey($gone, $ses);
        $this->assertArrayNotHasKey($stopped, $ses);
        $adm = $as(self::ADMIN);
        $this->assertTrue($adm[$direct]);
        $this->assertFalse($adm[$own]);
        $this->assertFalse($adm[$other]);
    }

    public function test_t3_order_and_months(): void
    {
        $cid = $this->mkCase(self::SES1, ['required_skills' => 'Java', 'preferred_skills' => '']);
        $a1 = $this->mkEng(self::SES1, '一番 年数', 'I.N', [['Java', 'lang', 12]]);
        $a2 = $this->mkEng(self::SES1, '二番 年数', 'N.N', [['Java', 'lang', 4]]);
        $b1 = $this->mkEng(self::SES2, '他社 年数', 'T.N', [['Java', 'lang', 12]]);
        $c = $this->c($cid);
        $u = $this->user(self::SES1);
        $sid = CS::run($u, $c, CS::conditions($c, null));
        $rows = CS::rows($u, DB::table('case_searches')->find($sid))['rows'];
        $this->assertSame([$a1, $a2, $b1], $this->names($rows));
        $this->assertGreaterThan($rows[1]['j']['monthsSum'], $rows[0]['j']['monthsSum']);
        $this->assertGreaterThan(0, $rows[1]['j']['monthsSum']);
        // 管理者：直属の会社の人が先
        DB::table('ses_companies')->where('id', $this->sesId(self::SES2))->update(['kind' => 'direct']);
        $adm = $this->user(self::ADMIN);
        $sid2 = CS::run($adm, $c, CS::conditions($c, null));
        $this->assertSame([$b1, $a1, $a2], $this->names(CS::rows($adm, DB::table('case_searches')->find($sid2))['rows']));
    }

    public function test_t4_project_only_skill_and_exp_hit(): void
    {
        $e = $this->mkEng(self::SES1, '経歴 太郎', 'K.T', []);
        $this->makeProject($e, ['tech' => 'Rust']);
        $j = CS::judge(['req' => [['name' => 'Rust', 'kind' => 'skill']], 'pref' => []], Matcher::skillIndex($e), []);
        $this->assertTrue($j['req'][0]['hit']);
        $this->assertStringStartsWith('経歴から', $j['req'][0]['label']);
        $this->assertSame([1, 1], [$j['h'], $j['n']]);

        $name = array_key_first(SkillForm::caseItems());
        $sec = SkillForm::caseItems()[$name]['section'];
        DB::table('engineer_skill_checks')->insert(['engineer_id' => $e, 'section' => $sec, 'parent' => '', 'item' => $name, 'other_text' => '', 'years_min' => null,
            'source' => 'seed', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $j = CS::judge(['req' => [['name' => $name, 'kind' => 'exp']], 'pref' => []], [], SkillChecks::expMany([$e])[$e]);
        $this->assertTrue($j['req'][0]['hit']);
    }

    public function test_t5_buttons_point_to_search_and_old_mail_remains(): void
    {
        $cid = $this->mkCase();
        foreach ([self::ADMIN, self::SES1] as $email) {
            $html = $this->as($email)->get("/cases/$cid")->assertOk()->getContent();
            $this->assertStringContainsString("/cases/$cid/search", $html);
            $this->assertStringNotContainsString("/cases/$cid/mail\"", $html);
            $this->as($email)->get("/cases/$cid/mail")->assertOk();
        }
    }

    public function test_t6_search_screen_shows_mail_or_description(): void
    {
        $cid = $this->mkCase(self::SES1, ['raw_text' => "架空の全文その1\n架空の全文その2"]);
        $this->as(self::SES1)->get("/cases/$cid/search")->assertOk()->assertSee('架空の全文その1')->assertSee('架空の全文その2')
            ->assertSee('Java')->assertSee('AWS')->assertSee('エンジニアを検索');
        $cid2 = $this->mkCase(self::SES1, ['raw_text' => '']);
        $this->as(self::SES1)->get("/cases/$cid2/search")->assertOk()->assertSee('元のメールはありません')->assertSee('架空の作業内容');
    }

    public function test_t7_added_items_are_normalized_and_case_is_unchanged(): void
    {
        $cid = $this->mkCase();
        $before = (array) DB::table('cases')->where('id', $cid)->first(['required_skills', 'preferred_skills', 'required_exp', 'preferred_exp']);
        $terms = DB::table('skill_terms')->count();
        $exp = array_key_first(SkillForm::caseItems());
        $sid = $this->search(self::SES1, $cid, ['add_req' => "ZZ架空の技術、Postgres\n$exp\nJava", 'add_pref' => 'Docker']);
        $cond = json_decode(DB::table('case_searches')->where('id', $sid)->value('conditions'), true);
        $byName = collect($cond['req'])->keyBy('name');
        $this->assertSame('skill', $byName['ZZ架空の技術']['kind']);
        $this->assertSame('added', $byName['PostgreSQL']['src']);
        $this->assertSame('exp', $byName[$exp]['kind']);
        $this->assertSame(1, collect($cond['req'])->where('name', 'Java')->count()); // 重ねない
        $this->assertContains('Docker', array_column($cond['pref'], 'name'));
        $this->assertSame($before, (array) DB::table('cases')->where('id', $cid)->first(['required_skills', 'preferred_skills', 'required_exp', 'preferred_exp']));
        $this->assertSame($terms, DB::table('skill_terms')->count());
    }

    public function test_t8_off_item_is_not_judged(): void
    {
        $cid = $this->mkCase();
        $e = $this->mkEng(self::SES1, '一部 太郎', 'I.T', $this->sk('Java'));
        $sid = $this->search(self::SES1, $cid, ['off' => ['req' => [CS::key(['kind' => 'skill', 'name' => 'SQL'])]]]);
        $rows = CS::rows($this->user(self::SES1), DB::table('case_searches')->find($sid))['rows'];
        $this->assertSame([1, 1], [$rows[0]['j']['h'], $rows[0]['j']['n']]);
        $this->assertSame($e, $rows[0]['e']->id);
    }

    public function test_t9_previous_conditions_come_back(): void
    {
        $cid = $this->mkCase();
        $this->search(self::SES1, $cid, ['add_req' => 'ZZ架空の技術', 'off' => ['pref' => [CS::key(['kind' => 'skill', 'name' => 'AWS'])]]]);
        $this->search(self::SES1, $cid, ['add_req' => 'ZZ架空の技術', 'off' => ['pref' => [CS::key(['kind' => 'skill', 'name' => 'AWS'])]]]);
        $this->assertSame(1, DB::table('case_searches')->where('case_id', $cid)->count());
        $s = DB::table('case_searches')->where('case_id', $cid)->first();
        $cond = CS::conditions($this->c($cid), $s);
        $this->assertContains('ZZ架空の技術', array_column($cond['req'], 'name'));
        $this->assertFalse($cond['pref'][0]['on']);
        $this->as(self::SES1)->get("/cases/$cid/search")->assertOk()->assertSee('ZZ架空の技術');
    }

    public function test_t10_list_shows_passers_only_and_empty_message(): void
    {
        $cid = $this->mkCase();
        $p1 = $this->mkEng(self::SES1, '合格 一郎', 'G.I', $this->sk('Java', 'SQL'));
        $p2 = $this->mkEng(self::SES1, '合格 二郎', 'G.J', $this->sk('Java', 'SQL', 'AWS'));
        $f = $this->mkEng(self::SES1, '不合 三郎', 'F.S', $this->sk('Java'));
        $sid = $this->search(self::SES1, $cid);
        $r = $this->as(self::SES1)->get("/searches/$sid")->assertOk();
        $r->assertSee('合格 一郎')->assertSee('合格 二郎')->assertDontSee('不合 三郎')->assertSee('必須 2/2')->assertSee('尚可 1/1')
            ->assertSee('全員へのメッセージ')->assertSee('エンジニアへメールを送信')->assertSee('条件に届かなかった人：1名');
        $r->assertSee('name="pick[]" value="'.$p1.'" checked', false)->assertSee('name="pick[]" value="'.$p2.'" checked', false);
        $this->assertSame(2, DB::table('case_search_members')->where('search_id', $sid)->count());
        $this->assertNotNull($f);

        $cid2 = $this->mkCase(self::SES1, ['required_skills' => 'ZZ誰も持たない技術', 'preferred_skills' => '']);
        $sid2 = $this->search(self::SES1, $cid2);
        $this->as(self::SES1)->get("/searches/$sid2")->assertOk()->assertSee('条件に合うエンジニアがいません');
    }

    public function test_t11_other_company_is_initials_and_closed_case_goes_back(): void
    {
        $cid = $this->mkCase(self::SES1, ['required_skills' => 'Java', 'preferred_skills' => '']);
        $this->mkEng(self::SES1, '自社 太郎', 'J.T', $this->sk('Java'));
        $this->mkEng(self::SES2, '他社 花子', 'X.Q', $this->sk('Java'));
        $sid = $this->search(self::SES1, $cid);
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('自社 太郎')->assertSee('X.Q（あおぞらテック）')->assertDontSee('他社 花子');
        $this->as(self::ADMIN)->get("/searches/$sid")->assertOk()->assertSee('他社 花子（あおぞらテック）');
        DB::table('cases')->where('id', $cid)->update(['status' => 'closed']);
        $this->as(self::SES1)->get("/searches/$sid")->assertRedirect(url("/cases/$cid"))->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '募集中の案件だけ'));
        $this->as(self::SES1)->get("/cases/$cid/search")->assertRedirect(url("/cases/$cid"));
    }

    public function test_t12_find_and_add_by_hand(): void
    {
        $cid = $this->mkCase(self::SES1, ['required_skills' => 'Java', 'preferred_skills' => '']);
        $this->mkEng(self::SES1, '合格 太郎', 'G.T', $this->sk('Java'));
        $weak = $this->mkEng(self::SES1, 'ゼット 落選', 'Z.R', []);
        $this->mkEng(self::SES2, '他社 花子', 'X.Q', $this->sk('Java'));
        $sid = $this->search(self::SES1, $cid);
        $u = $this->user(self::SES1);
        $this->assertSame([], CS::findEngineers($u, $this->c($cid), '他社 花子'));
        $this->assertCount(1, CS::findEngineers($u, $this->c($cid), 'X.Q'));
        $this->as(self::SES1)->get("/searches/$sid?q=".urlencode('ゼット'))->assertOk()->assertSee('ゼット 落選（みどり情報システム）')->assertSee('一覧に足す');
        $this->as(self::SES1)->post("/searches/$sid/add", ['engineer_id' => $weak])->assertRedirect();
        $this->assertSame(1, (int) DB::table('case_search_members')->where('search_id', $sid)->where('engineer_id', $weak)->value('by_hand'));
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('ゼット 落選')->assertSee('手で足した');
    }

    public function test_t13_sent_is_locked_and_checks_survive_detail_trip(): void
    {
        $cid = $this->mkCase(self::SES1, ['required_skills' => 'Java', 'preferred_skills' => '']);
        $p1 = $this->mkEng(self::SES1, '一人 目', 'H.H', $this->sk('Java'));
        $p2 = $this->mkEng(self::SES1, '二人 目', 'F.F', $this->sk('Java'));
        $p3 = $this->mkEng(self::SES1, '三人 目', 'S.S', $this->sk('Java'));
        DB::table('case_mails')->insert(['case_id' => $cid, 'engineer_id' => $p3, 'route' => 'own', 'mode' => 'same', 'subject' => 'x', 'body' => 'x', 'sent_at' => now_str()]);
        $sid = $this->search(self::SES1, $cid);
        $r = $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('送信済み');
        $r->assertDontSee('name="pick[]" value="'.$p3.'"', false);
        $this->assertSame(0, (int) DB::table('case_search_members')->where('search_id', $sid)->where('engineer_id', $p3)->value('checked'));

        $this->as(self::SES1)->post("/searches/$sid", ['pick' => [$p2], 'message_all' => 'みなさんへの架空の連絡', 'go' => $p1])
            ->assertRedirect(url("/searches/$sid/members/$p1"));
        $this->as(self::SES1)->get("/searches/$sid/members/$p1")->assertOk();
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('みなさんへの架空の連絡')
            ->assertSee('name="pick[]" value="'.$p2.'" checked', false)->assertDontSee('name="pick[]" value="'.$p1.'" checked', false);
    }

    // 必須 Java・SQL・Oracle・Docker（3つ当たれば合格）。own：自社、other：他社。一方は Docker が×
    private function worldForMail(): array
    {
        $cid = $this->mkCase(self::SES1, ['required_skills' => 'Java、SQL、Oracle、Docker', 'preferred_skills' => 'AWS']);
        $full = $this->mkEng(self::SES1, '満点 太郎', 'M.T', $this->sk('Java', 'SQL', 'Oracle', 'Docker'));
        $gap = $this->mkEng(self::SES1, '一部 次郎', 'I.J', $this->sk('Java', 'SQL', 'Oracle'));
        $other = $this->mkEng(self::SES2, '他社 三郎', 'T.S', $this->sk('Java', 'SQL', 'Oracle'), ['email' => 'other-eg@example.com']);
        $sid = $this->search(self::SES1, $cid);
        return [$cid, $sid, $full, $gap, $other];
    }

    public function test_t14_detail_shows_marks_and_consult_note(): void
    {
        [$cid, $sid, $full, $gap, $other] = $this->worldForMail();
        $me = $this->user(self::SES1)->name;
        $r = $this->as(self::SES1)->get("/searches/$sid/members/$gap")->assertOk();
        $r->assertSee('Docker')->assertSee('×', false)->assertSee('○', false)->assertSee('約 ')->assertSee('Dockerに関しては一部 次郎様とご検討ください');
        $this->as(self::SES1)->get("/searches/$sid/members/$other")->assertOk()->assertSee('Dockerに関してはT.S様とご検討ください');
        $this->as(self::SES1)->get("/searches/$sid/members/$full")->assertOk()->assertDontSee('確認のメモ');
    }

    public function test_t15_other_company_detail_hides_private_data(): void
    {
        [$cid, $sid, $full, $gap, $other] = $this->worldForMail();
        $this->as(self::SES1)->get("/searches/$sid/members/$other")->assertOk()->assertSee('【案件のご案内】')->assertSee('T.S（あおぞらテック）')
            ->assertDontSee('他社 三郎')->assertDontSee('other-eg@example.com');
        $this->as(self::SES1)->get("/searches/$sid/members/$gap")->assertOk()->assertSee("/engineers/$gap");
    }

    public function test_t16_t17_send_builds_body_from_messages(): void
    {
        [$cid, $sid, $full, $gap, $other] = $this->worldForMail();
        $this->setMessage(self::SES1, $sid, $gap, '一部さんだけへの架空の連絡');
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$full, $gap], 'message_all' => '全員への架空の連絡'])->assertRedirect();
        $bodies = DB::table('case_mails')->where('case_id', $cid)->pluck('body', 'engineer_id')->all();
        $this->assertCount(2, $bodies);
        $this->assertSame(2, DB::table('mail_outbox')->where('kind', 'case-mail')->count());
        foreach ($bodies as $b) {
            $this->assertStringContainsString('全員への架空の連絡', $b);
            $this->assertStringContainsString('■尚可：AWS', $b);
            $this->assertStringNotContainsString('{', $b);
            $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $b);
        }
        $this->assertStringContainsString('一部さんだけへの架空の連絡', $bodies[$gap]);
        $this->assertStringNotContainsString('ご検討ください', $bodies[$gap]); // 確認の一文はメールに入れない
        $this->assertStringNotContainsString('一部さんだけへの架空の連絡', $bodies[$full]);
        $this->assertStringNotContainsString('ご検討ください', $bodies[$full]);
        // 送ったあとのお知らせは、同じ人のまま次の画面で見る（as() はセッションを捨てるので使わない）
        $this->get("/cases/$cid")->assertOk()->assertSee('2名に案内メールを送りました')->assertSee('エンジニア検索');
    }

    public function test_t16b_empty_messages_leave_no_blank_lines(): void
    {
        [$cid, $sid, $full] = $this->worldForMail();
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$full]])->assertRedirect();
        $b = DB::table('case_mails')->where('engineer_id', $full)->value('body');
        $this->assertDoesNotMatchRegularExpression("/\n{3,}/", $b);
        $this->assertStringNotContainsString('{', $b);
        $this->assertStringContainsString('■あなたに合う点：', $b);
    }

    public function test_t18_records_offers_notices_and_audit(): void
    {
        [$cid, $sid, $full, $gap, $other] = $this->worldForMail();
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$full, $other]])->assertRedirect();
        $rows = DB::table('case_mails')->where('case_id', $cid)->get()->keyBy('engineer_id');
        $this->assertSame(['search', 'search'], [$rows[$full]->mode, $rows[$other]->mode]);
        $this->assertSame(['own', 'group'], [$rows[$full]->route, $rows[$other]->route]);
        $this->assertNotEmpty($rows[$full]->token);
        $offers = DB::table('case_offers')->where('case_id', $cid)->get()->keyBy('engineer_id');
        $this->assertCount(2, $offers);
        foreach ([$full, $other] as $eid) {
            $this->assertSame(Matcher::score($this->c($cid), DB::table('engineers')->find($eid))['score'], (int) $offers[$eid]->score);
        }
        $this->assertTrue(DB::table('app_notifications')->where('title', 'like', 'グループの案件が%')->exists());
        $this->assertTrue(DB::table('audit_log')->where('action', 'case-search-send')->exists());
    }

    public function test_t19_no_double_send_even_from_a_colleague(): void
    {
        [$cid, $sid, $full, $gap] = $this->worldForMail();
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$full]])->assertRedirect();
        $this->as(self::SES1)->post("/searches/$sid/send", ['pick' => [$full]])->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '送る相手がいません'));
        $staffId = W::createUser('staff70@example.com', '担当 零郎', 'ses', 'park1234', ['ses_id' => $this->sesId(self::SES1)]);
        $this->completeProfile($staffId);
        $sid2 = $this->search('staff70@example.com', $cid);
        $this->assertNotSame($sid, $sid2);
        $this->as('staff70@example.com')->post("/searches/$sid2/send", ['pick' => [$full]])->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '送る相手がいません'));
        $this->as('staff70@example.com')->post("/searches/$sid2/send", [])->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '送る相手を選んでください'));
        $this->assertSame(1, DB::table('case_mails')->where('case_id', $cid)->count());
        // 同じ会社の担当者は、SES の作った検索も開ける
        $this->as('staff70@example.com')->get("/searches/$sid")->assertOk();
    }

    public function test_t20_save_only_notice(): void
    {
        [$cid, $sid] = $this->worldForMail();
        config(['mail.default' => 'log']);
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('保存のみ');
        config(['mail.default' => 'smtp']);
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertDontSee('保存のみ');
    }

    public function test_t21_permissions(): void
    {
        [$cid, $sid, $full] = $this->worldForMail();
        $egEmail = 'eg1@park.example';
        $this->as($egEmail)->get("/cases/$cid/search")->assertForbidden();
        $this->as($egEmail)->get("/searches/$sid")->assertForbidden();
        $this->as(self::SES2)->get("/cases/$cid/search")->assertForbidden();
        $this->as(self::SES2)->get("/searches/$sid")->assertForbidden();
        $this->as(self::SES2)->post("/searches/$sid/send", ['pick' => [$full]])->assertForbidden();
        $this->as(self::ADMIN)->get("/searches/$sid")->assertOk();
        $this->assertSame(0, DB::table('case_mails')->count());
    }

    public function test_t22_other_search_members_and_out_of_range_ids(): void
    {
        $cidA = $this->mkCase(self::SES1, ['required_skills' => 'Java', 'preferred_skills' => '']);
        $cidB = $this->mkCase(self::SES1, ['required_skills' => 'Rust', 'preferred_skills' => '']);
        $this->mkEng(self::SES1, '甲 太郎', 'K.T', $this->sk('Java'));
        $rustOnly = $this->mkEng(self::SES1, '乙 次郎', 'O.J', []);
        $this->makeProject($rustOnly, ['tech' => 'Rust']);
        $gone = $this->mkEng(self::SES1, '丙 三郎', 'H.S', $this->sk('Java'), ['status' => 'unavailable']);
        $sa = $this->search(self::SES1, $cidA);
        $sb = $this->search(self::SES1, $cidB);
        $this->assertSame(1, DB::table('case_search_members')->where('search_id', $sb)->where('engineer_id', $rustOnly)->count());
        $this->as(self::SES1)->get("/searches/$sa/members/$rustOnly")->assertNotFound();
        $this->as(self::SES1)->post("/searches/$sa/members/$rustOnly", ['message' => 'x'])->assertNotFound();
        $before = DB::table('case_search_members')->count();
        $this->as(self::SES1)->post("/searches/$sa/add", ['engineer_id' => $gone])->assertRedirect();
        $this->as(self::SES1)->post("/searches/$sa/add", ['engineer_id' => 999999])->assertRedirect();
        $this->assertSame($before, DB::table('case_search_members')->count());
        // 検索の中にいない人の id を送っても、チェックは増えない
        $this->as(self::SES1)->post("/searches/$sa", ['pick' => [$rustOnly]])->assertRedirect();
        $this->assertSame(0, DB::table('case_search_members')->where('search_id', $sa)->where('engineer_id', $rustOnly)->count());
    }

    public function test_t23_version(): void
    {
        $this->assertSame('v0.8.1', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.8.1');
    }
}
