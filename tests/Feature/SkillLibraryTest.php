<?php

namespace Tests\Feature;

use App\Support\SkillCatalog;
use App\Support\SkillCatalogData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 技術マスタの辞書（SkillCatalogData）：表記の決まりと、管理者だけが見られること（2026-11-21 TEZZO の指示）
class SkillLibraryTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::syncDetail();
    }

    // 辞書の全部の言葉（名前・別名）を、正規化した形で「持ち主の名前」に対応させる。2つ以上の持ち主があれば衝突
    private function owners(): array
    {
        $o = [];
        foreach (SkillCatalogData::CATALOG as $terms) foreach ($terms as $name => $aliases) {
            foreach (array_merge([$name], parse_list($aliases)) as $w) $o[norm_skill_raw($w)][$name] = true;
        }
        return $o;
    }

    public function test_no_word_belongs_to_two_terms_and_aliases_have_no_separator(): void
    {
        foreach ($this->owners() as $k => $names) $this->assertCount(1, $names, "『{$k}』が複数の行にあります：".implode('、', array_keys($names)));
        foreach (SkillCatalogData::CATALOG as $terms) foreach ($terms as $name => $aliases) {
            foreach (explode('、', $aliases) as $a) $this->assertDoesNotMatchRegularExpression('#[/／・,，;；|]#u', $a, "『{$name}』の別名『{$a}』に区切り文字があります");
        }
    }

    public function test_english_product_names_are_never_written_in_katakana(): void
    {
        // Java・Docker・Git などを「ジャバ」「ギット」と書く人はいない（Provider⇔SES⇔EG の間）。辞書に入れない
        foreach (['ジャバ', 'ジャヴァ', 'ジャバスクリプト', 'ギット', 'エスキューエル'] as $bad) {
            $this->assertNull(SkillCatalog::find($bad), "『{$bad}』は辞書に入れない");
        }
        $this->assertSame('Java', SkillCatalog::find('Java')[1]);
        $this->assertSame('Java', SkillCatalog::find('Java17')[1]);
    }

    public function test_katakana_that_is_really_written_maps_to_the_term(): void
    {
        $cases = [
            'Agile' => 'アジャイル', 'アジャイル開発' => 'アジャイル', 'スクラム' => 'アジャイル', 'Scrum Master' => 'スクラムマスター', 'スクラムマスター' => 'スクラムマスター',
            'Product Owner' => 'プロダクトオーナー', 'プロジェクトマネージャ' => 'PM', 'プロジェクトマネージャー' => 'PM', 'プログラマ' => 'PG', 'リーダ' => 'PL',
            'ウォーターフォール型' => 'ウォーターフォール', 'マイクロサービス' => 'マイクロサービス', 'サーバレス' => 'サーバーレス', 'ディープラーニング' => '深層学習',
            'エクセル' => 'Excel', 'パワポ' => 'PowerPoint', 'リナックス' => 'Linux', 'オラクル' => 'Oracle', 'Postgres' => 'PostgreSQL', 'ミドルウエア構築' => 'ミドルウェア構築',
            'ネスペ' => 'ネットワークスペシャリスト', 'セキスペ' => '情報処理安全確保支援士', 'ブリッジエンジニア' => 'ブリッジSE', 'デブオプス' => 'DevOps', 'ユニティ' => 'Unity',
        ];
        foreach ($cases as $word => $name) $this->assertSame($name, SkillCatalog::find($word)[1] ?? null, "『{$word}』は『{$name}』になるはず");
    }

    public function test_detect_finds_terms_and_skips_ordinary_english_words(): void
    {
        $found = SkillCatalog::detect('Java17 / Spring Boot / Oracle / AWS(EC2、S3) 経験。Docker Compose での開発。Teams で連絡、Exchange 連携なし');
        foreach (['Java', 'Spring Boot', 'Oracle', 'AWS', 'Amazon EC2', 'Amazon S3', 'Docker'] as $n) $this->assertContains($n, $found, $n);
        foreach (['Jetpack Compose', 'Microsoft Teams', 'Exchange Server'] as $n) $this->assertNotContains($n, $found, $n); // 普通の単語としても使う言葉は、文章からは拾わない
        $this->assertNotContains('Java', SkillCatalog::detect('JavaScript と TypeScript のみ'));
    }

    public function test_sync_puts_the_whole_library_in_the_db_with_no_conflicts(): void
    {
        $total = array_sum(array_map('count', SkillCatalogData::CATALOG));
        $this->assertGreaterThanOrEqual(800, $total);
        $names = []; foreach (SkillCatalogData::CATALOG as $t) foreach (array_keys($t) as $n) $names[] = (string) $n;
        $this->assertSame($total, DB::table('skill_terms')->whereIn('name', $names)->count());
        $this->assertSame([], SkillCatalog::syncDetail()['conflicts']);
    }

    public function test_only_admin_can_see_the_library(): void
    {
        $this->as('admin@park.example')->get('/admin/skills')->assertOk()->assertSee('アジャイル')->assertSee('Agile')->assertSee('資格');
        $this->as('ses1@park.example')->get('/admin/skills')->assertForbidden();
        $this->as('eg2@park.example')->get('/admin/skills')->assertForbidden();
        $this->flushSession();
        auth()->logout();
        $this->get('/admin/skills')->assertRedirect();
    }
}
