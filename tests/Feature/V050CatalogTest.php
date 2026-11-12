<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\Workflow as W;
use App\Support\CaseExp;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.5.0 段階1：技術マスタの追加（PLAN.md 6章 T1〜T6・T21、レビュー指摘 L1・L2）
class V050CatalogTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::sync();
        SkillCatalog::forget();
    }

    private function term(string $name): ?object
    {
        return DB::table('skill_terms')->where('name', $name)->first();
    }

    // ---------- T1 ----------
    public function test_t1_new_terms_and_aliases_are_found(): void
    {
        $cases = [
            'Athena' => ['Amazon Athena', 'cloud'], 'M365' => ['Microsoft 365', 'cloud'], 'GAS' => ['Google Apps Script', 'lang'],
            'Doctrine' => ['Doctrine ORM', 'fw'], 'LIFF' => ['LINE Messaging API', 'cloud'], '汎用機' => ['メインフレーム', 'os'],
            'PowerBI' => ['Power BI', 'tool'], 'Amazon Redshift' => ['Redshift', 'db'], 'シングルサインオン' => ['SSO', 'infra'],
            'Tableau' => ['Tableau', 'tool'], 'ERP' => ['ERP', 'tool'], 'Word' => ['Word', 'tool'], 'dbt' => ['dbt', 'db'],
        ];
        foreach ($cases as $word => [$name, $cat]) {
            $t = SkillCatalog::find($word);
            $this->assertNotNull($t, "$word が見つからない");
            $this->assertSame($name, $t[1], $word);
            $this->assertSame($cat, $this->term($name)->category, "$name の分類");
        }
    }

    // ---------- T2 ----------
    public function test_t2_director_is_not_detected_alone_but_adobe_director_is(): void
    {
        $this->assertSame([], SkillCatalog::detect('Director クラスの方'));
        $this->assertSame([], SkillCatalog::detect('Director'));
        $this->assertContains('Adobe Director', SkillCatalog::detect('Adobe Director の経験'));
        $this->assertSame('Adobe Director', SkillCatalog::find('Director')[1]);
    }

    // ---------- T3 ----------
    public function test_t3_word_does_not_match_wordpress_and_old_matches_stay(): void
    {
        $this->assertFalse(loose_match('word', 'wordpress'));
        $this->assertFalse(loose_match('wordpress', 'word'));
        $this->assertFalse(loose_match('erp', 'redhatenterpriselinux'));
        $this->assertTrue(loose_match('word', 'word'));
        $this->assertTrue(loose_match('laravel', 'laravel5'));
        $this->assertTrue(loose_match('java', 'java8'));
        $this->assertFalse(loose_match('java', 'javascript'));

        $eid = $this->engId('eg2@park.example');
        $this->cleanEg($eid);
        W::saveSkills($eid, $this->checkForm(['lang/PHP/WordPress' => '']));
        $admin = DB::table('users')->where('email', 'admin@park.example')->first();
        $cid = W::saveCase(['title' => 'Word 必須', 'required_skills' => 'Word', 'status' => 'open'], $admin);
        $s = Matcher::score(DB::table('cases')->find($cid), DB::table('engineers')->find($eid));
        $this->assertContains('必須 Word', $s['missing'], 'WordPress だけの人は Word が足りない');
        $this->assertNotContains('必須 Word', $s['reasons']);
    }

    // ---------- T4 ----------
    public function test_t4_sync_detail_renames_fixes_and_second_run_is_quiet(): void
    {
        // 古い状態に戻す：Director（tool）、auto・未確認の Tableau（other）、auto の M365
        foreach (['Adobe Director', 'Tableau', 'M365'] as $n) DB::table('skill_terms')->where('name', $n)->delete();
        $dirId = DB::table('skill_terms')->insertGetId(['name' => 'Director', 'category' => 'tool', 'source' => 'master', 'reviewed' => true, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('skill_terms')->insert(['name' => 'Tableau', 'category' => 'other', 'source' => 'auto', 'reviewed' => false, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('skill_terms')->insert(['name' => 'M365', 'category' => 'other', 'source' => 'auto', 'reviewed' => false, 'created_at' => now(), 'updated_at' => now()]);
        SkillCatalog::forget();

        $r = SkillCatalog::syncDetail();
        $this->assertSame(['Director' => 'Adobe Director'], $r['renamed']);
        $this->assertSame($dirId, (int) $this->term('Adobe Director')->id, '行の id は変わらない');
        $this->assertNull($this->term('Director'));
        $t = $this->term('Tableau');
        $this->assertSame('tool', $t->category);
        $this->assertTrue((bool) $t->reviewed);
        $this->assertContains('Tableau', $r['fixed']);
        $this->assertTrue(collect($r['conflicts'])->contains(fn ($c) => str_starts_with($c, 'M365 → M365')), 'M365 のぶつかりが出る: '.json_encode($r['conflicts'], JSON_UNESCAPED_UNICODE));
        $this->assertNotNull($this->term('M365'), '行は消えない');

        $r2 = SkillCatalog::syncDetail();
        $this->assertSame(0, $r2['added']);
        $this->assertSame([], $r2['renamed']);
    }

    // ---------- L2 ----------
    public function test_l2_no_false_conflict_with_own_row(): void
    {
        // 別名が自分の名前と同じ形（PowerBI／Power BI など）でも、自分の行とのぶつかりは報告しない
        $r = SkillCatalog::syncDetail();
        $this->assertSame([], $r['conflicts'], json_encode($r['conflicts'], JSON_UNESCAPED_UNICODE));
        // 名前の大文字小文字だけ違う別の行があれば、それとのぶつかりは出るが、自分の行（Power BI）とのぶつかりは出ない
        DB::table('skill_terms')->insert(['name' => 'power bi', 'category' => 'other', 'source' => 'auto', 'reviewed' => false, 'created_at' => now(), 'updated_at' => now()]);
        $r = SkillCatalog::syncDetail();
        $this->assertTrue(collect($r['conflicts'])->contains(fn ($c) => str_contains($c, '→ power bi')), '別の行とのぶつかりは出る');
        $this->assertFalse(collect($r['conflicts'])->contains(fn ($c) => str_contains($c, '→ Power BI')), '自分の行とのぶつかりは出ない');
    }

    // ---------- L1 ----------
    public function test_l1_detect_matches_fullwidth_alias_in_db(): void
    {
        DB::table('skill_terms')->insert(['name' => 'ZetaTool', 'category' => 'tool', 'aliases' => 'ＺｅｔａＴ２、全角ＡＢＣ', 'source' => 'master', 'reviewed' => true, 'created_at' => now(), 'updated_at' => now()]);
        SkillCatalog::forget();
        $this->assertContains('ZetaTool', SkillCatalog::detect('ZetaT2 の経験'));
        $this->assertContains('ZetaTool', SkillCatalog::detect('ＺｅｔａＴ２の経験'));
    }

    // ---------- T5 ----------
    public function test_t5_skills_sync_command_builds_project_index(): void
    {
        $eid = $this->engId('eg1@park.example');
        $this->cleanEg($eid);
        DB::table('skill_terms')->where('name', 'Tableau')->delete();
        SkillCatalog::forget();
        $pid = $this->makeProject($eid, ['tech' => 'Tableau、Java', 'description' => 'Tableau でダッシュボードを作った']);
        $this->assertNotContains('Tableau', $this->projectTermNames($pid), '辞書に無い間は索引に無い');

        $this->artisan('park:skills-sync')->assertExitCode(0);
        SkillCatalog::forget();
        $this->assertContains('Tableau', $this->projectTermNames($pid));
        $this->artisan('park:skills-sync')->assertExitCode(0); // 2回目も落ちない
    }

    // ---------- T6 ----------
    public function test_t6_strip_removes_business_days_and_exp_picks_only_document(): void
    {
        $this->assertStringNotContainsString('営業日', SkillCatalog::strip('5営業日以内'));
        $this->assertStringNotContainsString('製造業', SkillCatalog::strip('製造業向け'));
        $line = '5営業日以内に報告書を作成';
        $this->assertSame(['資料作成'], CaseExp::detectLine($line));
        $this->assertNotContains('営業・見積', CaseExp::detectLine($line));
        $this->assertSame([], SkillCatalog::detect('可視化のみ'));
    }

    // ---------- T21 ----------
    public function test_t21_version_shown_on_login(): void
    {
        $this->get('/login')->assertOk()->assertSee('v0.5.0');
    }
}
