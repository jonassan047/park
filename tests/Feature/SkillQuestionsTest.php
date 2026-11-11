<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\Qa;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.4.1 スキル画面の「業界」（複数選択）と「PM/PLご経験者の方へ」（PM・PL を選んだ人にだけ出る、単一選択3問）。PLAN.md 6章 T1〜T20。
// 立場は 管理者・SES（代表者）・担当者・EG の4つ。担当者は ses1 と同じ会社の、SES ではない人（V030PermsTest と同じ作り方）
class SkillQuestionsTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const EG = 'eg1@park.example';       // ses1（みどり情報システム）の EG
    private const EG_OTHER = 'eg2@park.example'; // ses2 の EG
    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const STAFF = 'mate@example.com';    // ses1 の担当者

    private const INDUSTRIES = [
        'IT・インターネットサービス', '業界横断', '化学/素材', '原料/金属/石油', '日用品', 'インテリア', 'アパレル', '繊維/電気', '自動車/輸送機器',
        '食品/飲料/資料', '医薬品/福祉', '人材', '教育', '金融/保険', '建設', '不動産', 'インフラ（電力・ガス・水道など）', 'コンサルティング',
        '音楽', 'サービス', '小売', '趣味', '機械/物流', 'マスコミ/メディア', 'エンターテインメント', '公共/官公庁',
    ];

    private int $eid;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        $this->eid = $this->engId(self::EG);
        $this->cleanEg($this->eid);
        DB::table('engineers')->where('id', $this->eid)->update(['self_confirmed_at' => now_str()]);
    }

    // ---------- 道具 ----------

    private function skUrl(?int $eid = null): string { return '/engineers/'.($eid ?? $this->eid).'/skills'; }

    private function pmId(): string { return $this->skillNode('roles/プロジェクトマネージャー（PM）')['id']; }
    private function plId(): string { return $this->skillNode('roles/プロジェクトリーダー（PL）')['id']; }
    private function indId(string $name): string
    {
        $n = SkillForm::byPath('industry', '', $name);
        $this->assertNotNull($n, "業界に無い：$name");
        return $n['id'];
    }

    // PM/PL の節の設問 $g（0〜2）
    private function pmplGroup(int $g): array
    {
        foreach (SkillForm::tree() as $s) if ($s['key'] === 'pmpl') return $s['groups'][$g];
        $this->fail('pmpl の節が無い');
    }
    private function gid(int $g): string { return $this->pmplGroup($g)['node']['id']; }
    // 設問 $g の選択肢 $i の id
    private function cid(int $g, int $i): string { return $this->pmplGroup($g)['items'][$i]['id']; }

    // 業界（名前の配列）・役割（'pm'・'pl'）・one を付けた送信の形
    private function form(array $industries = [], array $roles = [], array $one = [], array $extra = []): array
    {
        $f = ['_sf' => '1', 'chk' => [], 'yr' => [], 'oth' => [], 'othyr' => [], '_sf_end' => '1'];
        foreach ($industries as $n) $f['chk'][$this->indId($n)] = '1';
        foreach ($roles as $r) $f['chk'][$r === 'pm' ? $this->pmId() : $this->plId()] = '1';
        if ($one) $f['one'] = $one;
        return $extra + $f;
    }

    private function save(array $form, ?string $email = null, ?int $eid = null)
    {
        return $this->as($email ?? self::EG)->post($this->skUrl($eid), $form);
    }

    private function page(?string $email = null, ?int $eid = null)
    {
        return $this->as($email ?? self::EG)->get($this->skUrl($eid));
    }

    private function rows(string $section, ?int $eid = null)
    {
        return DB::table('engineer_skill_checks')->where('engineer_id', $eid ?? $this->eid)->where('section', $section)->orderBy('id')->get();
    }

    private function allOne(): array { return [$this->gid(0) => $this->cid(0, 2), $this->gid(1) => $this->cid(1, 2), $this->gid(2) => $this->cid(2, 1)]; }

    private function assertBadOne($response): void
    {
        $response->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && $f['msg'] === '選択肢から選んでください。');
    }

    // pmpl の節の開きタグ
    private function pmplTag(string $html): string
    {
        preg_match('#<details class="sf-sec" data-sf-sec="pmpl"[^>]*>#', $html, $m);
        $this->assertNotEmpty($m, 'pmpl の節が画面に無い');
        return $m[0];
    }

    private function insertRow(int $eid, string $section, string $parent, string $item): void
    {
        DB::table('engineer_skill_checks')->insert(['engineer_id' => $eid, 'section' => $section, 'parent' => $parent, 'item' => $item, 'other_text' => '', 'years_min' => null,
            'source' => 'form', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function staff(): void
    {
        $id = W::createUser(self::STAFF, '同僚 一郎', 'ses', 'park1234', ['ses_id' => (int) DB::table('users')->where('email', self::SES1)->value('ses_id')]);
        $this->completeProfile($id);
    }

    // ---------- T1・T2 ----------

    public function test_t1_json_has_industry_and_pmpl_in_order(): void
    {
        $tree = SkillForm::tree();
        $this->assertSame(['roles', 'phases', 'lang', 'db', 'os', 'pm', 'dev', 'industry', 'pmpl'], array_column($tree, 'key'));
        $ind = $tree[7];
        $this->assertSame('システム開発経験のある業界は？', $ind['title']);
        $this->assertSame(self::INDUSTRIES, array_column($ind['items'], 'item'));
        $this->assertCount(26, $ind['items']);
        foreach ($ind['items'] as $n) { $this->assertFalse($n['years'], $n['item']); $this->assertFalse($n['other'], $n['item']); $this->assertSame([], $n['words'], $n['item']); }
        $this->assertFalse($ind['match']);
        $pp = $tree[8];
        $this->assertSame('PM/PLご経験者の方へ', $pp['title']);
        $this->assertTrue($pp['single']);
        $this->assertFalse($pp['match']);
        $this->assertCount(3, $pp['groups']);
        $this->assertSame([4, 6, 4], array_map(fn ($g) => count($g['items']), $pp['groups']));
        $this->assertSame(['選択しない'], array_values(array_filter(array_column($pp['groups'][1]['items'], 'item'), fn ($i) => $i === '選択しない')));
        $this->assertSame([$this->pmId(), $this->plId()], $pp['showIf']);
        foreach ($pp['groups'] as $g) foreach ($g['items'] as $n) $this->assertSame([], $n['words']);
    }

    public function test_t2_screen_shows_industry_without_year_select(): void
    {
        $html = $this->page()->assertOk()->assertSee('システム開発経験のある業界は？')->assertSee('IT・インターネットサービス')->assertSee('公共/官公庁')->getContent();
        foreach (self::INDUSTRIES as $name) {
            $id = $this->indId($name);
            $this->assertStringContainsString('name="chk['.$id.']"', $html, $name);
            $this->assertStringNotContainsString('name="yr['.$id.']"', $html, $name);
            $this->assertStringNotContainsString('name="oth['.$id.']', $html, $name);
        }
        $this->assertStringNotContainsString('yr[industry-', $html);
        // 節の順：dev → industry → pmpl
        $this->assertLessThan(strpos($html, 'システム開発経験のある業界は？'), strpos($html, 'data-sf-sec="dev"'));
        $this->assertLessThan(strpos($html, 'data-sf-sec="pmpl"'), strpos($html, 'data-sf-sec="industry"'));
        $this->assertStringContainsString('0 件チェック', $html);
    }

    // ---------- T3 ----------

    public function test_t3_save_industries(): void
    {
        $this->save($this->form(['アパレル', 'IT・インターネットサービス']))->assertRedirect(url("/engineers/{$this->eid}"));
        $rows = $this->rows('industry');
        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['アパレル', 'IT・インターネットサービス'], $rows->pluck('item')->all());
        foreach ($rows as $r) { $this->assertNull($r->years_min); $this->assertSame('', $r->parent); $this->assertSame('', $r->other_text); $this->assertSame('form', $r->source); }
        // 年数を細工して送っても、年数は入らない
        $f = $this->form(['アパレル']);
        $f['yr'][$this->indId('アパレル')] = '3年以上';
        $this->save($f)->assertRedirect();
        $this->assertNull($this->rows('industry')->first()->years_min);
        // 開き直すと印が付き、件数が出る
        $html = $this->page()->getContent();
        $this->assertMatchesRegularExpression('#name="chk\['.preg_quote($this->indId('アパレル'), '#').'\]"[^>]* checked#', $html);
        $this->assertStringContainsString('1 件チェック', $html);
    }

    public function test_t3_boundary_zero_last_one_and_double_press(): void
    {
        // 二重に押す（同じ送信を2回）→ 行は増えない
        $f = $this->form(['音楽', '教育']);
        $this->save($f)->assertRedirect(); $this->save($f)->assertRedirect();
        $this->assertCount(2, $this->rows('industry'));
        // 最後の1件を外す → 1件になり、さらに全部外すと0件
        $this->save($this->form(['音楽']))->assertRedirect();
        $this->assertSame(['音楽'], $this->rows('industry')->pluck('item')->all());
        $this->save($this->form([]))->assertRedirect();
        $this->assertCount(0, $this->rows('industry'));
        // 26件全部
        $this->save($this->form(self::INDUSTRIES))->assertRedirect();
        $this->assertCount(26, $this->rows('industry'));
    }

    // ---------- T4・T5 ----------

    public function test_t4_pmpl_section_hidden_until_pm_or_pl(): void
    {
        $html = $this->page()->assertOk()->getContent();
        $tag = $this->pmplTag($html);
        $this->assertStringContainsString(' hidden', $tag);
        $this->assertStringContainsString('data-sf-showif="'.$this->pmId().','.$this->plId().'"', $tag);
        $this->assertSame(1, preg_match_all('#name="one\[[^"]*" value="[^"]*" checked#', $html), '未回答のとき印が付くのは「選択しない」だけ');
        $this->assertSame(14, substr_count($html, 'type="radio" name="one['));
        $this->assertSame(14, substr_count($html, ' disabled>'), '隠れている間のラジオは送らない');

        // PM（年数だけ。チェックなし）を保存すると、出る
        $f = $this->form();
        $f['yr'][$this->pmId()] = '1年以上';
        $this->save($f)->assertRedirect();
        $html = $this->page()->assertOk()->assertSee('PM/PLご経験者の方へ')->getContent();
        $this->assertStringNotContainsString(' hidden', $this->pmplTag($html));
        $this->assertStringContainsString(' open', $this->pmplTag($html));
        $this->assertSame(0, substr_count($html, ' disabled>'));
        $this->assertStringContainsString('PM/PLを担当したシステム開発プロジェクトで一番長期のものは？', $html);
        $this->assertStringContainsString('PM/PLを担当したシステム開発プロジェクトでの一日最大利用者数は？', $html);
        $this->assertStringContainsString('PM/PLを担当したシステム開発プロジェクトでの人数規模は？', $html);
        $this->assertStringContainsString('PM・PL のチェックを外して保存すると、ここの回答は消えます。', $html);
    }

    public function test_t4_other_roles_do_not_open_pmpl(): void
    {
        // PMO・チームリーダーだけでは出ない
        $f = $this->form();
        $f['chk'][$this->skillNode('roles/PMO')['id']] = '1';
        $f['chk'][$this->skillNode('roles/チームリーダー')['id']] = '1';
        $this->save($f)->assertRedirect();
        $this->assertStringContainsString(' hidden', $this->pmplTag($this->page()->getContent()));
        // それらがあっても、one は捨てられる
        $f['one'] = $this->allOne();
        $this->save($f)->assertRedirect();
        $this->assertCount(0, $this->rows('pmpl'));
    }

    public function test_t5_save_three_answers_with_pl(): void
    {
        $this->save($this->form([], ['pl'], $this->allOne()))->assertRedirect();
        $rows = $this->rows('pmpl');
        $this->assertCount(3, $rows);
        $this->assertEqualsCanonicalizing(
            [[$this->pmplGroup(0)['node']['item'], $this->pmplGroup(0)['items'][2]['item']], [$this->pmplGroup(1)['node']['item'], $this->pmplGroup(1)['items'][2]['item']],
                [$this->pmplGroup(2)['node']['item'], $this->pmplGroup(2)['items'][1]['item']]],
            $rows->map(fn ($r) => [$r->parent, $r->item])->all());
        foreach ($rows as $r) { $this->assertNull($r->years_min); $this->assertSame('', $r->other_text); }
        // 開き直すと、選んだラジオに印が付く
        $html = $this->page()->getContent();
        foreach ([[0, 2], [1, 2], [2, 1]] as [$g, $i]) {
            $this->assertStringContainsString('name="one['.$this->gid($g).']" value="'.$this->cid($g, $i).'" checked', $html);
        }
        $this->assertStringContainsString('3 件回答', $html);
        // 1問だけ答える（0件・1件）
        $this->save($this->form([], ['pm'], [$this->gid(0) => $this->cid(0, 0)]))->assertRedirect();
        $this->assertCount(1, $this->rows('pmpl'));
        // 選び直すと、1設問に1つのまま
        $this->save($this->form([], ['pm'], [$this->gid(0) => $this->cid(0, 3)]))->assertRedirect();
        $this->assertSame([$this->pmplGroup(0)['items'][3]['item']], $this->rows('pmpl')->pluck('item')->all());
    }

    // ---------- T6〜T10 ----------

    public function test_t6_removing_pm_and_pl_deletes_answers(): void
    {
        $this->save($this->form(['教育'], ['pm', 'pl'], $this->allOne()))->assertRedirect();
        $this->assertCount(3, $this->rows('pmpl'));
        // PM を外して PL が残れば残る
        $this->save($this->form([], ['pl'], $this->allOne()))->assertRedirect();
        $this->assertCount(3, $this->rows('pmpl'));
        // 両方外して、one を付けたまま保存 → エラーにならず、回答は消える。業界は残る
        $r = $this->save($this->form(['教育'], [], $this->allOne()));
        $r->assertRedirect(url("/engineers/{$this->eid}"));
        $this->assertTrue(!session()->has('flash') || session('flash.type') !== 'bad', '回答の破棄はエラーにしない');
        $this->assertCount(0, $this->rows('pmpl'));
        $this->assertCount(1, $this->rows('industry'));
        // PM を付け直しても、消えた回答は戻らない
        $this->save($this->form([], ['pm']))->assertRedirect();
        $this->assertCount(0, $this->rows('pmpl'));
    }

    public function test_t7_one_without_pm_pl_is_dropped(): void
    {
        $this->save($this->form(['人材'], [], $this->allOne()))->assertRedirect();
        $this->assertCount(0, $this->rows('pmpl'));
        $this->assertCount(1, $this->rows('industry')); // 保存は成功している
    }

    public function test_t8_invalid_one_values_are_refused_and_nothing_changes(): void
    {
        $this->save($this->form(['教育'], ['pm'], [$this->gid(0) => $this->cid(0, 0)]))->assertRedirect();
        $before = DB::table('engineer_skill_checks')->where('engineer_id', $this->eid)->orderBy('id')->get(['section', 'parent', 'item'])->map(fn ($r) => (array) $r)->all();
        $this->assertNotEmpty($before);

        $bad = [
            '別の設問の選択肢' => [$this->gid(0) => $this->cid(1, 0)],
            '知らない文字' => [$this->gid(0) => 'zzz-unknown'],
            '配列の値' => [$this->gid(0) => [$this->cid(0, 0)]],
            '選択肢の名前そのもの' => [$this->gid(0) => '〜半年'],
            '数字' => [$this->gid(0) => '0'],
        ];
        foreach ($bad as $label => $one) {
            // 他の節（業界の追加）を一緒に送っても、何も保存されない
            $r = $this->save($this->form(['教育', '人材'], ['pm'], $one));
            $this->assertBadOne($r);
            $now = DB::table('engineer_skill_checks')->where('engineer_id', $this->eid)->orderBy('id')->get(['section', 'parent', 'item'])->map(fn ($x) => (array) $x)->all();
            $this->assertSame($before, $now, $label);
        }
        // one が配列でない
        $r = $this->save($this->form(['人材'], ['pm'], [], ['one' => 'abc']));
        $this->assertBadOne($r);
        $this->assertCount(1, $this->rows('industry'));
        // 知らない設問の id は無視される（エラーにしない）
        $this->save($this->form(['教育'], ['pm'], ['pmpl-99' => 'x', $this->gid(0) => $this->cid(0, 0)]))->assertRedirect();
        $this->assertCount(1, $this->rows('pmpl'));
    }

    public function test_t9_chk_on_pmpl_items_is_ignored(): void
    {
        $f = $this->form([], ['pm']);
        $f['chk'][$this->cid(0, 1)] = '1';
        $f['chk'][$this->cid(0, 2)] = '1';
        $f['chk'][$this->gid(0)] = '1';
        $f['yr'][$this->cid(2, 0)] = '3年以上';
        $this->save($f)->assertRedirect();
        $this->assertCount(0, $this->rows('pmpl'));
    }

    public function test_t10_no_selection_makes_no_row_and_shows_checked(): void
    {
        $none = $this->cid(1, 0);
        $this->assertTrue(SkillForm::node($none)['none']);
        $this->save($this->form([], ['pm'], [$this->gid(1) => $none]))->assertRedirect();
        $this->assertCount(0, $this->rows('pmpl'));
        $html = $this->page()->getContent();
        $this->assertStringContainsString('name="one['.$this->gid(1).']" value="'.$none.'" checked', $html);
        // 未回答のほかの設問は、何も選ばれていない（選択しないが無い）
        $this->assertStringNotContainsString('name="one['.$this->gid(0).']" value="'.$this->cid(0, 0).'" checked', $html);
        $this->assertSame(1, preg_match_all('#type="radio" name="one\[[^"]*" value="[^"]*" checked#', $html));
        // 選択しない → 選ぶ → 選択しない に戻すと行が消える
        $this->save($this->form([], ['pm'], [$this->gid(1) => $this->cid(1, 3)]))->assertRedirect();
        $this->assertCount(1, $this->rows('pmpl'));
        $this->save($this->form([], ['pm'], [$this->gid(1) => $none]))->assertRedirect();
        $this->assertCount(0, $this->rows('pmpl'));
    }

    public function test_validation_error_keeps_answers_on_the_form(): void
    {
        $f = $this->form(['教育'], ['pm'], $this->allOne());
        $f['yr'][$this->skillNode('lang/PHP')['id']] = '9年以上'; // 年数の誤り
        $url = $this->skUrl();
        $this->as(self::EG)->from($url)->post($url, $f)->assertRedirect($url);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('name="one['.$this->gid(0).']" value="'.$this->cid(0, 2).'" checked', $html);
        $this->assertStringContainsString('name="chk['.$this->indId('教育').']"', $html);
        $this->assertCount(0, $this->rows('pmpl'));
    }

    // ---------- T11・T12 ----------

    public function test_t11_industry_and_pmpl_never_affect_matching(): void
    {
        foreach (['インフラ（電力・ガス・水道など）', 'サービス', '金融/保険'] as $n) $this->insertRow($this->eid, 'industry', '', $n);
        foreach ([0, 1, 2] as $g) $this->insertRow($this->eid, 'pmpl', $this->pmplGroup($g)['node']['item'], $this->pmplGroup($g)['items'][2 - ($g === 2 ? 1 : 0)]['item']);
        $this->assertCount(3, $this->rows('pmpl'));

        $this->assertSame([], SkillChecks::indexMany([$this->eid])[$this->eid]);
        $caseId = (int) DB::table('cases')->insertGetId(['title' => '案件インフラ', 'required_skills' => 'インフラ、サービス、金融', 'preferred_skills' => '金融、保険',
            'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $c = DB::table('cases')->find($caseId);
        $e = DB::table('engineers')->find($this->eid);
        $s = Matcher::score($c, $e);
        $this->assertSame([], array_filter($s['reasons'], fn ($x) => str_starts_with($x, '必須') || str_starts_with($x, '尚可')));
        $this->assertContains('必須 インフラ', $s['missing']);
        $this->assertContains('必須 サービス', $s['missing']);
        $this->assertContains('必須 金融', $s['missing']);

        $m = SkillChecks::caseMarks(SkillChecks::state($this->eid), $c);
        foreach (SkillForm::nodes() as $n) if (in_array($n['section'], ['industry', 'pmpl'], true)) $this->assertArrayNotHasKey($n['id'], $m['marks'], $n['item']);
        $this->assertSame(['インフラ', 'サービス', '金融'], $m['missing']); // 業界のチェックは「持っている技術」に入らない

        SkillCatalog::forget();
        foreach (SkillChecks::usedPeople() as $tid => $people) $this->assertArrayNotHasKey($this->eid, $people, "term $tid");
        // 一覧の「スキル」列にも出ない
        $this->assertSame('', SkillChecks::summaryMany([$this->eid])[$this->eid]);
    }

    public function test_t12_norm_index_and_place_ignore_industry_and_pmpl(): void
    {
        foreach (SkillForm::normIndex() as $word => $id) $this->assertDoesNotMatchRegularExpression('/^(industry|pmpl)-/', $id, $word);
        foreach ([['インフラ', 'infra'], ['サービス', 'other'], ['金融', 'other'], ['教育', 'role'], ['5人〜29人', 'other']] as [$name, $cat]) {
            $p = SkillForm::place($name, $cat, 2);
            $this->assertNotContains($p['section'], ['industry', 'pmpl'], $name);
        }
        $this->assertSame('dev', SkillForm::place('インフラ', 'infra', 2)['section']);
    }

    // ---------- T13・T14 ----------

    public function test_t13_detail_sheet_and_lines_show_the_sections(): void
    {
        $this->save($this->form(['アパレル', '金融/保険'], ['pl'], $this->allOne()))->assertRedirect();
        $lines = collect(SkillChecks::lines($this->eid))->keyBy('title');
        $this->assertTrue($lines->has('業界'));
        $this->assertStringContainsString('アパレル', $lines['業界']['text']);
        $this->assertStringContainsString('金融/保険', $lines['業界']['text']);
        $this->assertTrue($lines->has('PM/PL の経験'));
        $this->assertStringContainsString('期間：'.$this->pmplGroup(0)['items'][2]['item'], $lines['PM/PL の経験']['text']);
        $this->assertStringContainsString('一日最大利用者数：'.$this->pmplGroup(1)['items'][2]['item'], $lines['PM/PL の経験']['text']);
        $this->assertStringContainsString('人数規模：'.$this->pmplGroup(2)['items'][1]['item'], $lines['PM/PL の経験']['text']);

        $this->as(self::EG)->get("/engineers/{$this->eid}")->assertOk()->assertSee('業界')->assertSee('アパレル')->assertSee('PM/PL の経験')->assertSee('期間：1年〜3年', false);
        foreach (['', '?anon=1'] as $q) {
            $this->as(self::EG)->get("/engineers/{$this->eid}/sheet$q")->assertOk()->assertSee('業界')->assertSee('アパレル')->assertSee('PM/PL の経験')->assertSee('1年〜3年', false);
        }
        // 上位（管理者・SES）からも見える
        $this->as(self::ADMIN)->get("/engineers/{$this->eid}")->assertOk()->assertSee('PM/PL の経験');
        $this->as(self::SES1)->get("/engineers/{$this->eid}/sheet")->assertOk()->assertSee('アパレル');
        // 他社 SES・他の EG は見られない
        $this->as(self::SES2)->get("/engineers/{$this->eid}/sheet")->assertForbidden();
        $this->as(self::EG_OTHER)->get("/engineers/{$this->eid}/sheet")->assertForbidden();
    }

    public function test_t13_zero_answers_show_no_sections(): void
    {
        $this->save($this->form(['アパレル']))->assertRedirect();
        $this->save($this->form([]))->assertRedirect();
        $titles = array_column(SkillChecks::lines($this->eid), 'title');
        $this->assertNotContains('業界', $titles);
        $this->assertNotContains('PM/PL の経験', $titles);
        $this->as(self::EG)->get("/engineers/{$this->eid}/sheet")->assertOk()->assertDontSee('PM/PL の経験');
    }

    public function test_t14_lists_do_not_show_industry_and_cannot_search_by_it(): void
    {
        $this->save($this->form(['アパレル', 'IT・インターネットサービス'], ['pl'], $this->allOne()))->assertRedirect();
        $name = (string) DB::table('engineers')->where('id', $this->eid)->value('name');
        // 比べる材料：技術は一覧に出て、探せる
        $f = $this->form(['アパレル'], ['pl'], $this->allOne());
        $f['chk'][$this->skillNode('lang/PHP/Laravel')['id']] = '1';
        $this->save($f)->assertRedirect();
        $this->as(self::ADMIN)->get('/admin/engineers?q=laravel')->assertOk()->assertSee($name)->assertSee('Laravel');
        $this->as(self::ADMIN)->get('/admin/engineers')->assertOk()->assertSee($name)->assertSee('Laravel')->assertDontSee('アパレル')->assertDontSee('1年〜3年', false);
        $this->as(self::SES1)->get('/ses/engineers')->assertOk()->assertSee($name)->assertSee('Laravel')->assertDontSee('アパレル');
        // 業界では探せない
        $this->as(self::ADMIN)->get('/admin/engineers?q='.urlencode('アパレル'))->assertOk()->assertDontSee($name);
    }

    // ---------- T15 ----------

    public function test_t15_industry_rows_do_not_count_for_five_skills(): void
    {
        $this->save($this->form(['アパレル', '教育', '人材', '建設', '不動産'], ['pl'], $this->allOne()) + [])->assertRedirect();
        $f = $this->form(['アパレル', '教育', '人材', '建設', '不動産'], [], $this->allOne());
        foreach (['lang/PHP', 'lang/Java', 'db/RDB'] as $p) $f['chk'][$this->skillNode($p)['id']] = '1';
        $this->save($f)->assertRedirect();
        $this->assertGreaterThanOrEqual(8, DB::table('engineer_skill_checks')->where('engineer_id', $this->eid)->count());
        $tips = implode("\n", Qa::sheetCheck($this->eid)['tips']);
        $this->assertStringContainsString('5つ以上チェックを入れましょう', $tips);
        // 技術が5つ以上になれば消える（業界の数とは関係なく）
        $f['chk'][$this->skillNode('lang/Go')['id']] = '1';
        $f['chk'][$this->skillNode('lang/Rust')['id']] = '1';
        $this->save($f)->assertRedirect();
        $this->assertStringNotContainsString('5つ以上チェックを入れましょう', implode("\n", Qa::sheetCheck($this->eid)['tips']));
        // 年数の未選択の数にも入れない（業界・PM/PL は年数が無い）：業界だけの人
        $this->cleanEg($this->eid);
        $this->save($this->form(['アパレル'], []))->assertRedirect();
        $this->assertStringNotContainsString('年数を選んでいない項目', implode("\n", Qa::sheetCheck($this->eid)['tips']));
    }

    // ---------- T16 ----------

    public function test_t16_ses_staff_admin_can_edit_own_company_and_confirmation_resets(): void
    {
        $this->staff();
        foreach ([self::SES1, self::STAFF, self::ADMIN] as $who) {
            DB::table('engineers')->where('id', $this->eid)->update(['self_confirmed_at' => now_str()]);
            $this->page($who)->assertOk()->assertSee('システム開発経験のある業界は？')->assertSee('PM/PLご経験者の方へ');
            $this->save($this->form(['建設'], ['pm'], [$this->gid(0) => $this->cid(0, 1)]), $who)->assertRedirect(url("/engineers/{$this->eid}"));
            $this->assertSame(['建設'], $this->rows('industry')->pluck('item')->all(), $who);
            $this->assertCount(1, $this->rows('pmpl'), $who);
            $this->assertNull(DB::table('engineers')->where('id', $this->eid)->value('self_confirmed_at'), "{$who}：上位が直したら本人の確認はやり直し");
            $this->save($this->form([]), $who)->assertRedirect(); // 次の人のために空に
        }
        // 本人が直したときは、確認が残る
        DB::table('engineers')->where('id', $this->eid)->update(['self_confirmed_at' => now_str()]);
        $this->save($this->form(['不動産']), self::EG)->assertRedirect();
        $this->assertNotNull(DB::table('engineers')->where('id', $this->eid)->value('self_confirmed_at'));
    }

    public function test_t16_other_company_and_other_eg_are_refused(): void
    {
        $this->staff();
        $this->save($this->form(['建設']))->assertRedirect();
        $before = $this->rows('industry')->pluck('item')->all();
        // 他社の SES・他社 SES の EG（eg2＝ses2）は、ses1 の EG に開けない・保存できない
        foreach ([self::SES2, self::EG_OTHER] as $who) {
            $this->page($who)->assertForbidden();
            $this->save($this->form(['教育'], ['pm'], $this->allOne()), $who)->assertForbidden();
            $this->assertSame($before, $this->rows('industry')->pluck('item')->all(), $who);
            $this->assertCount(0, $this->rows('pmpl'), $who);
        }
        // ses2 の担当者も、ses1 の EG には触れない
        $id = W::createUser('mate2@example.com', '別社 担当', 'ses', 'park1234', ['ses_id' => (int) DB::table('users')->where('email', self::SES2)->value('ses_id')]);
        $this->completeProfile($id);
        $this->page('mate2@example.com')->assertForbidden();
        $this->save($this->form(['教育']), 'mate2@example.com')->assertForbidden();
        $this->assertSame($before, $this->rows('industry')->pluck('item')->all());
        // ses1 側の人（SES・担当者）も、ses2 の EG には触れない
        $eid2 = $this->engId(self::EG_OTHER);
        foreach ([self::SES1, self::STAFF] as $who) {
            $this->save($this->form(['教育']), $who, $eid2)->assertForbidden();
            $this->assertCount(0, $this->rows('industry', $eid2), $who);
        }
        // EG は自分の分は保存できる（兼ねて対照）
        $this->save($this->form(['教育']), self::EG_OTHER, $eid2)->assertRedirect();
        $this->assertCount(1, $this->rows('industry', $eid2));
    }

    // ---------- T18：JSON の検査 ----------

    private function skillJson(): array { return json_decode(file_get_contents(resource_path('data/skill_form.json')), true); }

    private function sectionIndex(array $json, string $key): int
    {
        foreach ($json['sections'] as $i => $s) if ($s['key'] === $key) return $i;
        $this->fail("JSON に節が無い：$key");
    }

    public function test_t18_validate_accepts_the_real_json(): void
    {
        SkillForm::validate($this->skillJson());
        $this->assertTrue(true);
    }

    public function test_t18_showif_with_unknown_name_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['showIf']['items'][] = '存在しない役割';
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('showIf');
        SkillForm::validate($j);
    }

    public function test_t18_showif_without_items_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['showIf']['items'] = [];
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_showif_pointing_at_a_heading_throws(): void
    {
        // 見出しだけ（チェックできない）グループの名前は、条件にできない
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['showIf'] = ['section' => 'lang', 'items' => ['そのほか（Web系・その他言語）']];
        $heading = SkillForm::headingGroupName();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['showIf']['items'] = [$heading];
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_single_section_group_with_years_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['groups'][0]['years'] = true;
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_single_section_with_other_item_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['groups'][0]['items'][] = ['name' => 'その他', 'years' => false, 'other' => true];
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_single_section_with_direct_items_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'pmpl')]['items'] = [['name' => '直の項目', 'years' => false, 'other' => false]];
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_none_outside_single_section_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'industry')]['items'][0]['none'] = true;
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_none_in_normal_group_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'lang')]['groups'][0]['items'][0]['none'] = true;
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    public function test_t18_duplicate_industry_name_throws(): void
    {
        $j = $this->skillJson();
        $j['sections'][$this->sectionIndex($j, 'industry')]['items'][] = ['name' => 'アパレル', 'years' => false, 'other' => false];
        $this->expectException(\RuntimeException::class);
        SkillForm::validate($j);
    }

    // ---------- T19：JSON から消えた選択肢 ----------

    public function test_t19_removed_choice_shows_as_gone_and_never_matches(): void
    {
        $this->save($this->form([], ['pm'], [$this->gid(0) => $this->cid(0, 0)]))->assertRedirect();
        $g0 = $this->pmplGroup(0)['node']['item'];
        // 保存済みの行の選択肢を、JSON に無い文に書き換える（JSON から消えた想定）
        DB::table('engineer_skill_checks')->where('engineer_id', $this->eid)->where('section', 'pmpl')->update(['item' => '消えた選択肢ZZ']);
        $this->insertRow($this->eid, 'industry', '', '消えた業界ZZ');
        $this->page()->assertOk()->assertSee('今の表に無い項目')->assertSee('消えた選択肢ZZ')->assertSee('消えた業界ZZ');
        $this->assertSame([], array_diff(array_keys(SkillChecks::indexMany([$this->eid])[$this->eid]), [norm_skill('プロジェクトマネージャー（PM）'), 'pm']));
        $this->assertStringNotContainsString('消えた', SkillChecks::summaryMany([$this->eid])[$this->eid]);
        // 消えた行は、残す指定をしなければ保存で消える。pmpl は条件を満たす限り選び直せる
        $this->save($this->form([], ['pm'], [$this->gid(0) => $this->cid(0, 1)]))->assertRedirect();
        $this->assertSame([$this->pmplGroup(0)['items'][1]['item']], $this->rows('pmpl')->pluck('item')->all());
        $this->assertCount(0, $this->rows('industry'));
        $this->assertSame($g0, $this->rows('pmpl')->first()->parent);
    }

    // ---------- T20 ----------

    public function test_t20_version_is_shown(): void
    {
        $this->assertSame('v0.4.1', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.4.1');
    }

    // ---------- 既存の7節は変わらない ----------

    public function test_existing_sections_still_save_alongside(): void
    {
        $f = $this->checkForm(['lang/PHP' => '5年以上', 'lang/PHP/Laravel' => '', 'roles/プロジェクトリーダー（PL）' => '3年以上']);
        $f['chk'][$this->indId('教育')] = '1';
        $f['one'] = [$this->gid(2) => $this->cid(2, 3)];
        $this->save($f)->assertRedirect();
        $items = $this->checkRows($this->eid)->pluck('item')->all();
        foreach (['PHP', 'Laravel', 'プロジェクトリーダー（PL）', '教育', $this->pmplGroup(2)['items'][3]['item']] as $x) $this->assertContains($x, $items);
        $this->assertSame(5, $this->checkRows($this->eid)->count());
        // 技術のマッチングには PHP・Laravel・PL の役割だけ入り、業界・PM/PL は入らない
        $idx = SkillChecks::indexMany([$this->eid])[$this->eid];
        $this->assertArrayHasKey(norm_skill('PHP'), $idx);
        $this->assertArrayNotHasKey(norm_skill('教育'), $idx);
        $this->assertArrayNotHasKey(norm_skill('100人以上'), $idx);
    }
}
