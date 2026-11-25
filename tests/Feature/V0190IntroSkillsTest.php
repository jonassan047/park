<?php

namespace Tests\Feature;

use App\Support\IntroSkills;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.19.0 推薦文からのスキルの抜き出し（PLAN-v0190.md の B1〜B7）。T7〜T12。名前はすべて架空
class V0190IntroSkillsTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::syncDetail();
    }

    // 抜き出した行から、項目の名前 => 年数 の表にする
    private function items(string $body): array
    {
        $out = [];
        foreach (IntroSkills::extract($body)['rows'] as $r) $out[$r['other_text'] !== '' ? $r['other_text'] : $r['item']] = $r['years_min'];
        return $out;
    }

    public function test_t7_years_are_read_after_each_word(): void
    {
        $m = $this->items("・言語：Java（5年）、PHP（3年）\n・DB：MySQL");
        $this->assertSame(5, $m['Java']);
        $this->assertSame(3, $m['PHP']);
        $this->assertArrayHasKey('MySQL', $m);
        $this->assertNull($m['MySQL']);
    }

    public function test_t8_personal_lines_and_the_block_under_them_are_not_read(): void
    {
        $r = IntroSkills::extract("氏名：架空 太郎\n年齢：30歳\n最寄駅：架空線○○駅\n希望月額：60万円\n【人物】\nJava の勉強会を主催");
        $this->assertSame([], $r['rows']);
        $this->assertSame([], $r['unplaced']);
        // 見出しの後の別の見出しからは、また読む
        $this->assertArrayHasKey('Java', $this->items("【人物】\nPHP の勉強会\n【スキル】\nJava"));
        $this->assertArrayNotHasKey('PHP', $this->items("【人物】\nPHP の勉強会\n【スキル】\nJava"));
    }

    public function test_t9_langs_and_overseas_items(): void
    {
        $r = IntroSkills::extract("英語：日常会話\n外国籍メンバーとの開発経験\nオフショア開発のブリッジSE");
        $keys = array_map(fn ($x) => $x['section'].'/'.$x['item'], $r['rows']);
        foreach (['global/英語', 'global/外国人との協業', 'global/オフショア開発 / ニアショア開発', 'global/ブリッジSE', 'work/英語・海外'] as $k) $this->assertContains($k, $keys, $k);
    }

    public function test_t10_unknown_words_are_not_added_to_the_master(): void
    {
        $before = DB::table('skill_terms')->count();
        $r = IntroSkills::extract('Hotwire');
        $this->assertSame([], $r['rows']);
        $this->assertSame($before, DB::table('skill_terms')->count());
    }

    public function test_r3_calendar_years_are_not_experience_years(): void
    {
        $this->assertNull($this->items('Java（2019年から）')['Java']);
        $this->assertNull($this->items('Java 2020年4月〜')['Java']);
        $this->assertNull($this->items('Java 2024年度')['Java']);
        $this->assertNull($this->items('Java 3年目')['Java']);
        $this->assertSame(3, $this->items('Java 3年5ヶ月')['Java']);
        $this->assertSame(3, $this->items('Java 4年11ヶ月')['Java']); // 4.9年は「3年以上」に丸まる
        $this->assertSame(1, $this->items('Java 18ヶ月')['Java']);
    }

    public function test_r4_colon_skip_ends_after_the_line_and_its_indented_continuation(): void
    {
        $r = IntroSkills::extract("備考：なし\n  Java 以外の補足\n※ Python の補足\nPHP");
        $items = array_column($r['rows'], 'item');
        $this->assertContains('PHP', $items);
        $this->assertNotContains('Java', $items);
        $this->assertNotContains('Python', $items);
        $this->assertSame(3, $r['skipped']);
        // 続きでない次の行は、すぐ読む
        $this->assertContains('Java', array_column(IntroSkills::extract("備考：なし\nJava")['rows'], 'item'));
    }

    public function test_r11_words_taken_as_work_items_are_not_listed_as_unplaced(): void
    {
        $r = IntroSkills::extract('人事');
        $this->assertContains('人事・給与', array_column($r['rows'], 'item'));
        $this->assertNotContains('人事', $r['unplaced']);
    }

    public function test_t11_words_with_no_place_are_listed_as_unplaced(): void
    {
        $r = IntroSkills::extract("金融\n基本情報技術者");
        $this->assertSame([], $r['rows']);
        $this->assertContains('金融', $r['unplaced']);
        $this->assertContains('基本情報技術者', $r['unplaced']);
    }

    public function test_t12_same_item_is_one_row(): void
    {
        $r = IntroSkills::extract("Java\nJava 3年\nJava を使った開発");
        $java = array_values(array_filter($r['rows'], fn ($x) => $x['item'] === 'Java'));
        $this->assertCount(1, $java);
        $this->assertSame(3, $java[0]['years_min']);
    }
}
