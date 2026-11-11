<?php

namespace Tests\Feature;

use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// v0.4.0 スキル項目表（resources/data/skill_form.json）と SkillForm の単体テスト。PLAN.md 4-1・11章・13章
// 技術マスタ（norm_skill が引く）が要るので、見本つきの DB で動かす
class SkillFormJsonTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
    }

    public function test_year_options_match_the_constant(): void
    {
        $json = json_decode(file_get_contents(resource_path('data/skill_form.json')), true);
        $this->assertSame(array_keys(SkillForm::YEARS), $json['yearOptions']);
        $this->assertSame(['1年未満' => 0, '1年以上' => 1, '3年以上' => 3, '5年以上' => 5], SkillForm::YEARS);
    }

    public function test_paths_and_ids_are_unique_and_sections_are_the_seven(): void
    {
        $nodes = SkillForm::nodes(); // 重なりがあれば、ここで例外
        $paths = array_map(fn ($n) => $n['section']."\0".$n['parent']."\0".$n['item'], $nodes);
        $this->assertSame(count($paths), count(array_unique($paths)));
        $this->assertSame(count($nodes), count(array_unique(array_column($nodes, 'id'))));
        $this->assertSame(['roles', 'phases', 'lang', 'db', 'os', 'pm', 'dev'], array_column(SkillForm::tree(), 'key'));
        foreach ($nodes as $n) $this->assertSame($n, SkillForm::byPath($n['section'], $n['parent'], $n['item']));
    }

    public function test_checkable_rules(): void
    {
        // 見出しだけのグループ（years が false のグループと「そのほか（…）」）にはチェックが無い。その中の項目にはある
        $this->assertFalse(SkillForm::byPath('pm', '', '課題・タスク管理')['checkable']);
        $this->assertTrue(SkillForm::byPath('pm', '課題・タスク管理', 'Jira')['checkable']);
        $this->assertFalse(SkillForm::byPath('pm', '課題・タスク管理', 'Jira')['years']);
        $this->assertFalse(SkillForm::byPath('lang', '', SkillForm::headingGroupName())['checkable']);
        $this->assertTrue(SkillForm::byPath('lang', '', 'PHP')['checkable']);
        $this->assertTrue(SkillForm::byPath('lang', '', 'PHP')['years']);
        $this->assertTrue(SkillForm::byPath('lang', 'PHP', 'その他')['other']);
        $this->assertFalse(SkillForm::byPath('phases', '', '要件定義')['years']);
        $this->assertTrue(SkillForm::byPath('roles', '', 'その他')['years']);
    }

    public function test_words_examples(): void
    {
        $cases = [
            'Node.js（実行環境）' => ['Node.js'],
            'HTML/CSS（Sass・Bootstrap・Tailwind CSS・Material UI）' => ['HTML', 'CSS', 'Sass', 'Bootstrap', 'Tailwind CSS', 'Material UI'],
            'SQL（PL/SQL・T-SQL）' => ['SQL', 'PL/SQL', 'T-SQL'],
            'Pandas / NumPy' => ['Pandas', 'NumPy'],
            'VB / VBA' => ['VB', 'VBA'],
            'ABAP（SAP）' => ['ABAP', 'SAP'],
            '（メインフレーム系ミドルウェア：CICS・IMS）' => ['CICS', 'IMS'],
            'IBM i（AS/400）' => ['IBM i', 'AS/400'],
            'プロジェクトマネージャー（PM）' => ['プロジェクトマネージャー', 'PM'],
        ];
        foreach ($cases as $name => $expected) {
            $words = SkillForm::words($name);
            $this->assertContains($name, $words, "{$name}：名前そのもの");
            foreach ($expected as $w) $this->assertContains($w, $words, "$name → $w");
        }
        $this->assertNotContains('実行環境', SkillForm::words('Node.js（実行環境）'));
        // 数字だけの言葉は外す
        $this->assertSame(['2012 / 2016 / 2019 / 2022'], SkillForm::words('2012 / 2016 / 2019 / 2022'));
        // 「/」は、HTML/CSS 以外では前後に空白があるときだけ分ける
        $this->assertSame(['GitLab CI/CD'], SkillForm::words('GitLab CI/CD'));
    }

    public function test_every_place_for_other_exists(): void
    {
        foreach (['role', 'method', 'db', 'os', 'cloud', 'infra', 'tool', 'lang', 'fw', 'domain', 'other', 'zzz'] as $cat) {
            $p = SkillForm::place('ZzNoSuchName', $cat, null);
            $this->assertSame('その他', $p['item'], $cat);
            $this->assertSame('ZzNoSuchName', $p['other_text'], $cat);
        }
        $p = SkillForm::place('Zz', 'os', null);
        $this->assertSame('UNIX', $p['parent']);
        $this->assertSame('Windows Server', SkillForm::place('Zz windows', 'os', null)['parent']);
    }

    public function test_place_examples(): void
    {
        $p = SkillForm::place('Java', 'lang', null);
        $this->assertSame(['lang', '', 'Java', ''], [$p['section'], $p['parent'], $p['item'], $p['other_text']]);
        $p = SkillForm::place('Spring', 'fw', null);
        $this->assertSame(['lang', 'Java', 'Spring Framework'], [$p['section'], $p['parent'], $p['item']]);
        $p = SkillForm::place('React', 'fw', null);
        $this->assertSame(['lang', 'JavaScript', 'React'], [$p['section'], $p['parent'], $p['item']]);
        $p = SkillForm::place('Node.js', 'fw', null);
        $this->assertSame(['lang', '', 'Node.js（実行環境）'], [$p['section'], $p['parent'], $p['item']]);
        $p = SkillForm::place('PM', 'role', null);
        $this->assertSame(['roles', '', 'プロジェクトマネージャー（PM）'], [$p['section'], $p['parent'], $p['item']]);
        foreach (['HTML', 'CSS'] as $w) {
            $p = SkillForm::place($w, 'lang', null);
            $this->assertSame(SkillForm::headingGroupName(), $p['parent'], $w);
            $this->assertStringStartsWith('HTML/CSS', $p['item'], $w);
        }
        $p = SkillForm::place('Windows', 'os', 3);
        $this->assertSame(['os', 'Windows Server', 'その他', 'Windows'], [$p['section'], $p['parent'], $p['item'], $p['other_text']]);
        $p = SkillForm::place('金融', 'domain', null);
        $this->assertSame([SkillForm::headingGroupName(), 'その他', '金融'], [$p['parent'], $p['item'], $p['other_text']]);
    }

    public function test_place_rounds_years(): void
    {
        $y = fn ($v) => SkillForm::place('PHP', 'lang', $v)['years_min'];
        $this->assertSame(1, $y(2.5));
        $this->assertSame(0, $y(0.0));
        $this->assertSame(0, $y(0.5));
        $this->assertSame(1, $y(1.0));
        $this->assertSame(3, $y(3.0));
        $this->assertSame(3, $y(4.5));
        $this->assertSame(5, $y(5.0));
        $this->assertNull($y(null));
        // 年数の無い置き場所（工程・ツール）は、いつも null
        $this->assertNull(SkillForm::place('要件定義', 'method', 5)['years_min']);
        $this->assertNull(SkillForm::place('ZzTool', 'tool', 5)['years_min']);
    }

    public function test_labels_and_values(): void
    {
        $this->assertSame('1年未満', SkillForm::label(0));
        $this->assertSame('5年以上', SkillForm::label(5));
        $this->assertNull(SkillForm::label(null));
        $this->assertNull(SkillForm::label(2));
        $this->assertSame(3, SkillForm::value('3年以上'));
        $this->assertNull(SkillForm::value('2年以上'));
        $this->assertLessThan(SkillForm::rank(0), SkillForm::rank(null));
    }
}
