<?php

namespace Tests\Feature;

use App\Support\CaseKeywords;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.19.0 技術マスタに語学・海外を足す（PLAN-v0190.md の C1〜C4）。T1〜T4。名前はすべて架空
class V0190CatalogTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const LANGS = ['英語', '中国語', '韓国語', 'ベトナム語', '外国語', '外国人との協業', '海外拠点との連携'];

    public function test_t1_sync_adds_the_seven_langs_terms_and_second_run_is_quiet(): void
    {
        DB::table('skill_terms')->whereIn('name', self::LANGS)->delete();
        SkillCatalog::forget();
        $r = SkillCatalog::syncDetail();
        $this->assertSame(self::LANGS, array_values(array_intersect(self::LANGS, DB::table('skill_terms')->where('category', 'langs')->pluck('name')->all())));
        $this->assertGreaterThanOrEqual(7, $r['added']);
        $r2 = SkillCatalog::syncDetail();
        $this->assertSame(0, $r2['added']);
        $this->assertSame([], $r2['conflicts'], json_encode($r2['conflicts'], JSON_UNESCAPED_UNICODE));
    }

    public function test_t2_aliases_find_the_right_terms(): void
    {
        SkillCatalog::syncDetail();
        $this->assertSame('英語', SkillCatalog::find('ビジネス英語')[1]);
        $this->assertSame('外国人との協業', SkillCatalog::find('外国籍メンバー')[1]);
        $this->assertSame('オフショア開発', SkillCatalog::find('オフショア経験')[1]);
        $this->assertSame('ブリッジSE', SkillCatalog::find('ブリッジ SE')[1]); // 空白は正規化で無視されるので、別名にしなくても当たる
        $this->assertNull(SkillCatalog::find('多言語対応'));
        // 別名が、正規化すると自分の正式な名前と同じになるものは、別名に書かない
        foreach (\App\Support\SkillCatalogData::CATALOG as $cat => $terms) foreach ($terms as $name => $aliases) {
            if ($cat !== 'langs' && !in_array($name, ['ブリッジSE', 'オフショア開発'], true)) continue; // v0.19.0 で足した・直した行だけ見る
            foreach (parse_list($aliases) as $a) $this->assertNotSame(norm_skill_raw($name), norm_skill_raw($a), "『{$name}』の別名『{$a}』は名前と同じになる");
        }
        $this->assertSame('語学・海外', PARK_LABELS['skillCat']['langs']);
    }

    public function test_t3_form_has_global_section_with_nine_items(): void
    {
        $by = [];
        foreach (SkillForm::tree() as $s) $by[$s['key']] = $s;
        $this->assertArrayHasKey('global', $by);
        $this->assertSame(['英語', '中国語', '韓国語', 'ベトナム語', 'その他', '外国人との協業', '海外拠点との連携', 'オフショア開発 / ニアショア開発', 'ブリッジSE'], array_column($by['global']['items'], 'item'));
        $this->assertTrue(SkillForm::matchable('global'));
        $this->assertTrue(SkillForm::byPath('global', '', 'その他')['other']);
    }

    public function test_t4_case_lines_pick_langs_terms_and_work_item(): void
    {
        SkillCatalog::syncDetail();
        $k = CaseKeywords::split(['ビジネスレベルの英語', 'ブリッジSE経験', 'オフショア経験'], []);
        foreach (['英語', 'ブリッジSE', 'オフショア開発'] as $n) $this->assertContains($n, $k['required_skills'], $n);
        $this->assertContains('英語・海外', $k['required_exp']);
    }
}
