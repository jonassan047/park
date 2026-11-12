<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\Qa;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use App\Support\CaseExp;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.5.0 段階2・3：業務経験・業務領域（PLAN.md 6章 T7〜T20）。本文は架空の文だけ
class V050WorkExpTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';
    private const EG2 = 'eg2@park.example';
    private const EG3 = 'eg3@park.example';

    private const WORK = ['進捗・課題管理', '折衝・調整', 'ベンダー管理', '資料作成', '移行', '導入・刷新', '業務設計・運用設計', '品質管理', 'データ分析・BI', '障害・問い合わせ対応（ITIL）', '英語・海外', 'AI 活用', '難しいプロジェクト（大規模・複数・立て直し）', '教育・ナレッジ', '提案・事業化'];
    private const AREA = ['営業・見積', '人事・給与', '経理・財務', '総務・バックオフィス', '受発注・在庫', '生産管理', '規制対応（GxP など）', '顧客管理（CRM・コンタクトセンター）'];

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
    }

    private function admin(): object
    {
        return DB::table('users')->where('email', self::ADMIN)->first();
    }

    // 本登録済みの担当者（見本の SES 1 の会社に足す）
    private function staff(): string
    {
        $id = W::createUser('staff50@example.com', '担当 五郎', 'ses', 'park1234', ['ses_id' => (int) DB::table('users')->where('email', self::SES1)->value('ses_id')]);
        $this->completeProfile($id);
        return 'staff50@example.com';
    }

    private function itemId(string $name): string
    {
        $it = SkillForm::caseItems()[$name] ?? null;
        $this->assertNotNull($it, "業務の項目が無い：$name");
        return $it['id'];
    }

    // 画面の送信形：[名前 => 'required'|'preferred']
    private function expForm(array $pick): array
    {
        $exp = [];
        foreach ($pick as $name => $v) $exp[$this->itemId($name)] = $v;
        return ['_exp' => '1', 'exp' => $exp];
    }

    private function mkCase(array $extra = []): int
    {
        return (int) DB::table('cases')->insertGetId($extra + ['title' => 'T案件', 'status' => 'open', 'work_style' => 'any', 'created_at' => now(), 'updated_at' => now()]);
    }

    private function sesCase(string $email, array $extra = []): int
    {
        return $this->mkCase($extra + ['source_ses_id' => (int) DB::table('users')->where('email', $email)->value('ses_id')]);
    }

    private function checkWorkOnly(int $eid, array $names): void
    {
        $f = ['_sf' => '1', 'chk' => [], 'yr' => [], 'oth' => [], 'othyr' => [], '_sf_end' => '1'];
        foreach ($names as $n) $f['chk'][$this->itemId($n)] = '1';
        W::saveSkills($eid, $f);
    }

    // ---------- T7 ----------
    public function test_t7_tree_has_work_and_area_sections_and_screen_shows_them(): void
    {
        $keys = array_map(fn ($s) => $s['key'], SkillForm::tree());
        $this->assertSame(['roles', 'phases', 'lang', 'db', 'os', 'pm', 'dev', 'work', 'industry', 'area', 'pmpl'], $keys);
        $by = [];
        foreach (SkillForm::tree() as $s) $by[$s['key']] = $s;
        $this->assertSame(self::WORK, array_map(fn ($n) => $n['item'], $by['work']['items']));
        $this->assertSame(self::AREA, array_map(fn ($n) => $n['item'], $by['area']['items']));
        foreach (['work', 'area'] as $k) {
            $this->assertTrue($by[$k]['caseMatch']);
            $this->assertFalse($by[$k]['match']);
            foreach ($by[$k]['items'] as $n) { $this->assertFalse($n['years'], $n['item']); $this->assertFalse($n['other'], $n['item']); }
        }
        $eid = $this->engId(self::EG2);
        $html = $this->as(self::EG2)->get("/engineers/$eid/skills")->assertOk()->assertSee('進捗・課題管理')->assertSee('経理・財務')->getContent();
        $id = $this->itemId('進捗・課題管理');
        preg_match('#<label[^>]*>\s*<input type="checkbox" name="chk\['.preg_quote($id, '#').'\]".*?</label>#s', $html, $m);
        $this->assertNotEmpty($m, '進捗・課題管理のラベル');
        $this->assertStringNotContainsString('<select', $m[0], '年数のプルダウンが無い');
        $this->assertStringNotContainsString('yr[', $m[0]);
    }

    // ---------- T8 ----------
    public function test_t8_work_checks_are_saved_and_shown(): void
    {
        $eid = $this->engId(self::EG2);
        $this->cleanEg($eid);
        $f = $this->checkForm([]);
        $f['chk'][$this->itemId('進捗・課題管理')] = '1';
        $f['chk'][$this->itemId('英語・海外')] = '1';
        $f['chk'][$this->itemId('経理・財務')] = '1';
        $this->as(self::EG2)->post("/engineers/$eid/skills", $f)->assertRedirect();
        $rows = $this->checkRows($eid);
        $this->assertCount(2, $rows->where('section', 'work'));
        $this->assertCount(1, $rows->where('section', 'area'));
        foreach ($rows as $r) $this->assertNull($r->years_min);
        $this->assertContains('進捗・課題管理', $rows->pluck('item')->all());

        $this->as(self::ADMIN)->get("/engineers/$eid")->assertOk()->assertSee('業務経験')->assertSee('業務領域')->assertSee('進捗・課題管理')->assertSee('経理・財務');
        foreach (['', '?anon=1'] as $q) {
            $this->as(self::ADMIN)->get("/engineers/$eid/sheet$q")->assertOk()->assertSee('業務経験')->assertSee('業務領域')->assertSee('経理・財務');
        }
        $lines = collect(SkillChecks::lines($eid));
        $this->assertTrue($lines->contains(fn ($l) => str_contains(json_encode($l, JSON_UNESCAPED_UNICODE), '業務経験')));
    }

    // ---------- T9 ----------
    public function test_t9_work_rows_stay_out_of_index_summary_used_and_sheetcheck(): void
    {
        $eid = $this->engId(self::EG2);
        $this->cleanEg($eid);
        $f = $this->checkForm(['lang/PHP' => '3年以上', 'lang/Java' => '', 'db/RDB/MySQL' => '']);
        foreach (['進捗・課題管理', '折衝・調整', '資料作成', '英語・海外', '経理・財務'] as $n) $f['chk'][$this->itemId($n)] = '1';
        W::saveSkills($eid, $f);
        $this->assertCount(5, $this->checkRows($eid)->whereIn('section', ['work', 'area']));

        $idx = SkillChecks::indexMany([$eid])[$eid];
        foreach (['進捗・課題管理', '折衝・調整', '資料作成', '英語・海外', '経理・財務'] as $n) $this->assertArrayNotHasKey(norm_skill_raw($n), $idx, $n);
        $this->assertArrayHasKey('php', $idx);
        $sum = SkillChecks::summaryMany([$eid])[$eid];
        foreach (['進捗', '折衝', '資料作成', '経理'] as $w) $this->assertStringNotContainsString($w, $sum);
        $this->assertStringContainsString('PHP', $sum);
        $used = SkillChecks::usedPeople();
        $this->assertArrayHasKey($this->termId('PHP'), $used);
        $all = [];
        foreach ($used as $tid => $people) if (isset($people[$eid])) $all[] = $tid;
        $this->assertLessThanOrEqual(5, count($all), '業務の行が使っている人に混ざらない（技術3つ＋その親・子程度）');
        foreach (Matcher::skillIndex($eid) as $k => $_) $this->assertStringNotContainsString('進捗', $k);

        // Qa::sheetCheck：技術3つ＋業務5つでも「5つ以上」は満たされない
        $tips = Qa::sheetCheck($eid)['tips'];
        $this->assertTrue(collect($tips)->contains(fn ($t) => str_contains($t, '5つ以上')), '業務を数に入れない: '.json_encode($tips, JSON_UNESCAPED_UNICODE));
    }

    // ---------- T10 ----------
    public function test_t10_json_validation_rejects_bad_case_match(): void
    {
        $base = json_decode(file_get_contents(resource_path('data/skill_form.json')), true);
        SkillForm::validate($base); // 今の JSON は通る
        $secIdx = fn (array $j, string $k) => array_search($k, array_column($j['sections'], 'key'), true);
        $w = $secIdx($base, 'work'); $a = $secIdx($base, 'area'); $l = $secIdx($base, 'lang');

        $bad = [
            'years:true' => function (array $j) use ($w) { $j['sections'][$w]['items'][0]['years'] = true; return $j; },
            'other:true' => function (array $j) use ($w) { $j['sections'][$w]['items'][0]['other'] = true; return $j; },
            'caseWords なし' => function (array $j) use ($w) { unset($j['sections'][$w]['items'][0]['caseWords']); return $j; },
            'caseWords 空' => function (array $j) use ($w) { $j['sections'][$w]['items'][0]['caseWords'] = []; return $j; },
            'caseWords が文字でない' => function (array $j) use ($w) { $j['sections'][$w]['items'][0]['caseWords'] = [1]; return $j; },
            'caseMatch で match が true' => function (array $j) use ($w) { unset($j['sections'][$w]['match']); return $j; },
            'caseMatch でない節の caseWords' => function (array $j) use ($l) { $j['sections'][$l]['items'][0]['caseWords'] = ['x']; return $j; },
            'work と area で名前が重なる' => function (array $j) use ($w, $a) { $j['sections'][$a]['items'][0]['name'] = $j['sections'][$w]['items'][0]['name']; return $j; },
        ];
        foreach ($bad as $label => $mut) {
            try {
                SkillForm::validate($mut($base));
                $this->fail("検査を通ってしまった：$label");
            } catch (\RuntimeException $e) {
                $this->assertNotSame('', $e->getMessage(), $label);
            }
        }
    }

    // ---------- T11 ----------
    public function test_t11_import_picks_work_into_selects_and_keeps_skill_fields(): void
    {
        $text = "【案件】テスト取り込み\n■必須スキル\n・進捗管理の経験\n・AIX の運用\n■尚可スキル\n・英語での会話\n・進捗の報告\n";
        $r = $this->as(self::ADMIN)->post('/cases/import', ['raw_text' => $text])->assertOk();
        $html = $r->getContent();
        $sel = function (string $name) use ($html): string {
            $id = $this->itemId($name);
            preg_match('#<select name="exp\['.preg_quote($id, '#').'\]".*?</select>#s', $html, $m);
            $this->assertNotEmpty($m, "select が無い：$name");
            preg_match('#<option value="(\w*)" selected#', $m[0], $o);
            return $o[1] ?? '';
        };
        $this->assertSame('required', $sel('進捗・課題管理'));
        $this->assertSame('preferred', $sel('英語・海外'));
        $this->assertSame('', $sel('AI 活用'), 'AIX は AI 活用にならない（K2）');
        $r->assertSee('見つかった業務');
        // 技術の欄は今と同じ（業務の言い方で変わらない）
        $parsed = \App\Services\CaseParser::parse($text);
        $this->assertSame('進捗管理の経験、AIX の運用', (string) $parsed['required_skills'], '技術の欄は今のまま（業務の文もそのまま入る）');
        $this->assertSame("進捗・課題管理", $parsed['required_exp']);
        $this->assertSame("英語・海外", $parsed['preferred_exp']);
        $this->assertStringNotContainsString('AI 活用', (string) $parsed['required_exp']);
    }

    // ---------- T12 ----------
    public function test_t12_save_exp_for_each_player_and_update_rules(): void
    {
        $post = function (string $email) {
            $f = ['title' => "T12 $email", 'status' => 'open', 'required_skills' => 'PHP'] + $this->expForm(['資料作成' => 'required', '進捗・課題管理' => 'required', '英語・海外' => 'preferred', '経理・財務' => 'preferred']);
            $this->as($email)->post('/cases', $f)->assertRedirect();
            return DB::table('cases')->orderByDesc('id')->first();
        };
        foreach ([self::ADMIN, self::SES1, $this->staff()] as $email) {
            $c = $post($email);
            $this->assertSame("進捗・課題管理\n資料作成", $c->required_exp, "{$email}：JSON の並び・改行つなぎ");
            $this->assertSame("英語・海外\n経理・財務", $c->preferred_exp);
            // 空で更新（_exp 付き）→ null
            $this->as($email)->post("/cases/{$c->id}", ['title' => $c->title, 'status' => 'open', '_exp' => '1', 'exp' => []])->assertRedirect();
            $c2 = DB::table('cases')->find($c->id);
            $this->assertNull($c2->required_exp);
            $this->assertNull($c2->preferred_exp);
        }
        // _exp なしの更新（T12 の最重要）：列は変わらない
        $this->as(self::ADMIN)->post('/cases', ['title' => 'T12 保持', 'status' => 'open'] + $this->expForm(['資料作成' => 'required', '英語・海外' => 'preferred']))->assertRedirect();
        $c = DB::table('cases')->orderByDesc('id')->first();
        $this->as(self::ADMIN)->post("/cases/{$c->id}", ['title' => 'T12 保持（変更）', 'status' => 'open', 'required_skills' => 'Java'])->assertRedirect();
        $c2 = DB::table('cases')->find($c->id);
        $this->assertSame('T12 保持（変更）', $c2->title, '更新自体は通っている');
        $this->assertSame('資料作成', $c2->required_exp);
        $this->assertSame('英語・海外', $c2->preferred_exp);
        // 直接（画面を通らない）の新規は文から入れ、更新は触らない
        $id = W::saveCase(['title' => '直接', 'required_exp' => "資料作成\n存在しない名前"], $this->admin());
        $this->assertSame('資料作成', DB::table('cases')->find($id)->required_exp);
        W::saveCase(['title' => '直接2'], $this->admin(), $id);
        $this->assertSame('資料作成', DB::table('cases')->find($id)->required_exp, '更新では触らない');

        // 知らない id・値は無視。技術マスタに業務の名前が足されない
        $before = DB::table('skill_terms')->count();
        $this->as(self::ADMIN)->post('/cases', ['title' => 'T12 不正', 'status' => 'open', '_exp' => '1', 'exp' => ['nope-1' => 'required', $this->itemId('移行') => 'bogus', $this->itemId('品質管理') => 'preferred']])->assertRedirect();
        $c = DB::table('cases')->orderByDesc('id')->first();
        $this->assertNull($c->required_exp);
        $this->assertSame('品質管理', $c->preferred_exp);
        $this->assertSame($before, DB::table('skill_terms')->count());
        $this->assertNull(DB::table('skill_terms')->where('name', '品質管理')->first());
    }

    // ---------- T13 ----------
    public function test_t13_eg_and_other_ses_cannot_edit(): void
    {
        $cid = $this->sesCase(self::SES1, ['required_exp' => '資料作成']);
        $form = ['title' => '乗っ取り', 'status' => 'open'] + $this->expForm(['移行' => 'required']);
        $this->as(self::EG2)->post("/cases/$cid", $form)->assertForbidden();
        $this->as(self::SES2)->post("/cases/$cid", $form)->assertForbidden();
        $this->as(self::EG2)->post('/cases', $form)->assertForbidden();
        $c = DB::table('cases')->find($cid);
        $this->assertSame('資料作成', $c->required_exp);
        $this->assertSame('T案件', $c->title);
        // 持ち込んだ SES の担当者は編集できる（同じ会社）
        $this->as($this->staff())->post("/cases/$cid", ['title' => '担当者が更新', 'status' => 'open'] + $this->expForm(['移行' => 'required']))->assertRedirect();
        $this->assertSame('移行', DB::table('cases')->find($cid)->required_exp);
    }

    // ---------- T14 ----------
    public function test_t14_detail_shows_exp_rows_only_when_present(): void
    {
        $eid = $this->engId(self::EG2);
        $with = $this->sesCase(self::SES1, ['title' => '業務あり', 'required_exp' => '進捗・課題管理', 'preferred_exp' => '英語・海外']);
        $without = $this->sesCase(self::SES1, ['title' => '業務なし']);
        DB::table('case_offers')->insert([['case_id' => $with, 'engineer_id' => $eid, 'sent_at' => now(), 'status' => 'sent'], ['case_id' => $without, 'engineer_id' => $eid, 'sent_at' => now(), 'status' => 'sent']]);
        foreach ([self::ADMIN, self::SES1, self::SES2, $this->staff(), self::EG2] as $email) {
            $this->as($email)->get("/cases/$with")->assertOk()->assertSee('必須の業務')->assertSee('尚可の業務')->assertSee('進捗・課題管理')->assertSee('英語・海外');
            $this->as($email)->get("/cases/$without")->assertOk()->assertDontSee('必須の業務')->assertDontSee('尚可の業務');
        }
        // 必須だけのとき、尚可の行は出ない
        $only = $this->sesCase(self::SES1, ['required_exp' => '移行']);
        $this->as(self::ADMIN)->get("/cases/$only")->assertSee('必須の業務')->assertDontSee('尚可の業務');
    }

    // ---------- T15・T16 ----------
    private function pairEgs(): array
    {
        $a = $this->engId(self::EG1); $b = $this->engId(self::EG2);
        foreach ([$a, $b] as $e) $this->cleanEg($e);
        $fa = $this->checkForm(['lang/Java' => '']); $fa['chk'][$this->itemId('進捗・課題管理')] = '1';
        $fb = $this->checkForm(['lang/Java' => '']);
        W::saveSkills($a, $fa); W::saveSkills($b, $fb);
        return [$a, $b];
    }

    public function test_t15_score_includes_required_and_preferred_exp(): void
    {
        [$a, $b] = $this->pairEgs();
        $cid = $this->mkCase(['required_skills' => 'Java', 'required_exp' => '進捗・課題管理', 'preferred_exp' => '英語・海外']);
        $c = DB::table('cases')->find($cid);
        $sa = Matcher::score($c, DB::table('engineers')->find($a));
        $sb = Matcher::score($c, DB::table('engineers')->find($b));
        $this->assertContains('必須 進捗・課題管理', $sa['reasons']);
        $this->assertTrue(collect($sa['reasons'])->contains(fn ($t) => str_starts_with($t, '必須 Java')));
        $this->assertContains('必須 進捗・課題管理', $sb['missing']);
        $this->assertNotContains('必須 進捗・課題管理', $sb['reasons']);
        $this->assertGreaterThan($sb['score'], $sa['score']);
        // 尚可の業務：持っている人だけ合う点に出る
        $fa = $this->checkForm(['lang/Java' => '']); $fa['chk'][$this->itemId('英語・海外')] = '1';
        W::saveSkills($b, $fa);
        $sb2 = Matcher::score($c, DB::table('engineers')->find($b));
        $this->assertContains('尚可 英語・海外', $sb2['reasons']);
        $this->assertNotContains('尚可 英語・海外', $sa['reasons']);
        // 業務の必須が足りない人は、業務の無い案件より低い（分母は技術と業務の合計）
        $cNoExp = DB::table('cases')->find($this->mkCase(['required_skills' => 'Java']));
        $only = Matcher::score($cNoExp, DB::table('engineers')->find($this->engId(self::EG1)))['score'];
        $withExp = $sb['score'];
        $this->assertGreaterThan($withExp, $only, '業務の必須が足りない人は、業務の無い案件より低い');
        // 同じ人を業務の列の有無で比べ、まとめて渡した expMany でも同じ
        $exp = SkillChecks::expMany([$a])[$a];
        $this->assertSame($sa['score'], Matcher::score($c, DB::table('engineers')->find($a), null, $exp)['score']);
    }

    public function test_t16_case_without_exp_scores_as_before(): void
    {
        $eid = $this->engId(self::EG2);
        $this->cleanEg($eid);
        DB::table('engineers')->where('id', $eid)->update(['status' => 'available', 'available_from' => null, 'work_style' => 'any']);
        W::saveSkills($eid, $this->checkForm(['lang/PHP' => '3年以上']));
        $this->makeProject($eid, ['tech' => 'Laravel']);
        $cid = W::saveCase(['title' => 'T16', 'required_skills' => 'Laravel、PHP', 'work_style' => 'any', 'status' => 'open'], $this->admin());
        $c = DB::table('cases')->find($cid);
        $this->assertNull($c->required_exp);
        $this->assertNull($c->preferred_exp);
        $this->assertSame(82, Matcher::score($c, DB::table('engineers')->find($eid))['score']);
        // 業務にチェックがあっても、案件に業務が無ければ点数は変わらない
        $f = $this->checkForm(['lang/PHP' => '3年以上']); $f['chk'][$this->itemId('進捗・課題管理')] = '1';
        W::saveSkills($eid, $f);
        $this->assertSame(82, Matcher::score($c, DB::table('engineers')->find($eid))['score']);
        // 技術が空・業務も空の案件は、必須・尚可とも 0.5
        $empty = DB::table('cases')->find($this->mkCase());
        $this->assertSame(Matcher::score($empty, DB::table('engineers')->find($eid))['score'], Matcher::score($empty, DB::table('engineers')->find($eid), null, [])['score']);
    }

    // 業務だけの必須（技術なし）：分母は業務だけ
    public function test_t15b_exp_only_case(): void
    {
        [$a, $b] = $this->pairEgs();
        $c = DB::table('cases')->find($this->mkCase(['required_exp' => '進捗・課題管理']));
        $sa = Matcher::score($c, DB::table('engineers')->find($a));
        $sb = Matcher::score($c, DB::table('engineers')->find($b));
        $this->assertGreaterThan($sb['score'], $sa['score']);
        $this->assertContains('必須 進捗・課題管理', $sb['missing']);
    }

    // ---------- T17 ----------
    public function test_t17_case_marks_on_skill_screen(): void
    {
        [$a, $b] = $this->pairEgs();
        $cid = $this->mkCase(['title' => '印の案件', 'required_exp' => '進捗・課題管理', 'preferred_exp' => '英語・海外']);
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $b, 'sent_at' => now(), 'status' => 'sent']);
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $a, 'sent_at' => now(), 'status' => 'sent']);
        $html = $this->as(self::EG2)->get("/engineers/$b/skills?case=$cid")->assertOk()->getContent();
        $label = function (string $name) use ($html): string {
            $id = $this->itemId($name);
            preg_match('#<label[^>]*><input type="checkbox" name="chk\['.preg_quote($id, '#').'\]".*?</label>#s', $html, $m);
            $this->assertNotEmpty($m, $name);
            return $m[0];
        };
        $this->assertStringContainsString('必須', $label('進捗・課題管理'));
        $this->assertStringContainsString('尚可', $label('英語・海外'));
        $this->assertStringNotContainsString('sf-mark', $label('移行'));
        // 足りない必須（本人がチェックしていない）
        $state = SkillChecks::state($b);
        $info = SkillChecks::caseMarks($state, DB::table('cases')->find($cid));
        $this->assertContains('進捗・課題管理', $info['missing']);
        $this->assertFalse($info['empty']);
        $this->assertSame('required', $info['marks'][$this->itemId('進捗・課題管理')]);
        $this->assertSame('preferred', $info['marks'][$this->itemId('英語・海外')]);
        $html = $this->as(self::EG2)->get("/engineers/$b/skills?case=$cid")->getContent();
        $this->assertStringContainsString('あなたのスキルに無い必須', $html);
        // チェックしている人（A）には足りないに出ない
        $infoA = SkillChecks::caseMarks(SkillChecks::state($a), DB::table('cases')->find($cid));
        $this->assertNotContains('進捗・課題管理', $infoA['missing']);
        // 業務だけの案件でも「empty」ではない
        $this->assertTrue(SkillChecks::caseMarks($state, DB::table('cases')->find($this->mkCase()))['empty']);
    }

    // ---------- T18 ----------
    public function test_t18_sheet_shows_required_exp_row(): void
    {
        [$a, $b] = $this->pairEgs();
        $cid = $this->mkCase(['required_skills' => 'Java', 'required_exp' => '進捗・課題管理']);
        $html = $this->as(self::ADMIN)->get("/engineers/$a/sheet?case=$cid")->assertOk()->assertSee('進捗・課題管理')->getContent();
        $this->assertMatchesRegularExpression('#<th>進捗・課題管理</th><td>○ 経験あり</td>#', $html);
        $html = $this->as(self::ADMIN)->get("/engineers/$b/sheet?case=$cid")->getContent();
        $this->assertMatchesRegularExpression('#<th>進捗・課題管理</th><td>—</td>#', $html);
    }

    // ---------- T19 ----------
    public function test_t19_query_count_does_not_grow_with_people_with_exp(): void
    {
        $ids = [$this->engId(self::EG1), $this->engId(self::EG2), $this->engId(self::EG3)];
        $fourth = (int) DB::table('engineers')->where('name', '高橋 陽介')->value('id');
        DB::table('engineers')->whereNotIn('id', array_merge($ids, [$fourth]))->update(['status' => 'unavailable']);
        foreach (array_merge($ids, [$fourth]) as $id) {
            DB::table('engineers')->where('id', $id)->update(['status' => 'available', 'available_from' => null, 'work_style' => 'any']);
            $this->makeProject($id, ['tech' => 'Laravel、Docker']);
            $f = $this->checkForm(['lang/PHP' => '']); $f['chk'][$this->itemId('進捗・課題管理')] = '1';
            W::saveSkills($id, $f);
        }
        DB::table('engineers')->where('id', $fourth)->update(['status' => 'unavailable']);
        $cid = $this->mkCase(['required_skills' => 'Laravel、Docker', 'required_exp' => '進捗・課題管理', 'preferred_exp' => '英語・海外']);
        Matcher::forCase($cid);
        $count = function () use ($cid): array {
            DB::flushQueryLog(); DB::enableQueryLog();
            $n = count(Matcher::forCase($cid));
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();
            return [$n, $q];
        };
        [$n3, $q3] = $count();
        DB::table('engineers')->where('id', $fourth)->update(['status' => 'available']);
        [$n4, $q4] = $count();
        $this->assertSame(3, $n3);
        $this->assertSame(4, $n4);
        $this->assertSame($q3, $q4, '人数が増えても、問い合わせの数は同じ');
        // expMany 単体も同じ
        $q = function (array $ids) { DB::flushQueryLog(); DB::enableQueryLog(); SkillChecks::expMany($ids); $n = count(DB::getQueryLog()); DB::disableQueryLog(); return $n; };
        $this->assertSame($q([$ids[0]]), $q(array_merge($ids, [$fourth])));
    }

    // ---------- T20 ----------
    public function test_t20_unknown_names_in_columns_are_ignored(): void
    {
        [$a, $b] = $this->pairEgs();
        $base = DB::table('cases')->find($this->mkCase(['required_skills' => 'Java']));
        $cid = $this->mkCase(['required_skills' => 'Java', 'required_exp' => "存在しない業務\n進捗・課題管理x", 'preferred_exp' => '消えた項目']);
        $c = DB::table('cases')->find($cid);
        $e = DB::table('engineers')->find($b);
        $this->assertSame([], CaseExp::split($c->required_exp));
        $s = Matcher::score($c, $e);
        $this->assertSame(Matcher::score($base, $e)['score'], $s['score']);
        $this->assertSame([], array_filter(array_merge($s['reasons'], $s['missing']), fn ($t) => str_contains($t, '存在しない') || str_contains($t, '消えた')));
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertDontSee('存在しない業務')->assertDontSee('消えた項目')->assertDontSee('必須の業務');
        $this->assertSame([], SkillChecks::caseMarks(SkillChecks::state($b), $c)['missing']);
    }

    // ---------- 立場の確認：他の EG は他人の案件の業務を見られない ----------
    public function test_other_eg_cannot_see_case_with_exp(): void
    {
        $cid = $this->sesCase(self::SES1, ['required_exp' => '進捗・課題管理']);
        $this->as(self::EG3)->get("/cases/$cid")->assertForbidden();
    }
}
