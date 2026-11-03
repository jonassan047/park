<?php

namespace Tests\Feature;

use App\Support\SkillCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// v0.3.0 テスト設計書 版 01：D（DB と移し替え）D-01〜D-05。D-06 は手元の MariaDB での手動確認
class V030DbTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const MIG_TABLES = 'migrations/2026_11_03_000001_skill_tables.php';
    private const MIG_FILL = 'migrations/2026_11_03_000002_fill_project_skills.php';

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
    }

    private function fill(): void
    {
        (require database_path(self::MIG_FILL))->up();
    }

    // 新しい表の中身を、並べて比べられる形にする
    private function indexRows(): array
    {
        return DB::table('engineer_project_skills')->orderBy('project_id')->orderBy('term_id')->get(['project_id', 'term_id'])->map(fn ($r) => [$r->project_id, $r->term_id])->all();
    }

    public function test_d_01_tables_and_columns(): void
    {
        $this->assertTrue(Schema::hasTable('engineer_project_skills'));
        $this->assertTrue(Schema::hasColumns('engineer_project_skills', ['project_id', 'term_id']));
        $this->assertFalse(Schema::hasColumn('engineer_project_skills', 'engineer_id'));
        $this->assertTrue(Schema::hasColumns('engineer_skills', ['last_used_ym', 'is_primary', 'note', 'created_at', 'updated_at']));

        // 同じ (project_id, term_id) は2回入らない
        $pid = $this->makeProject(1, ['tech' => '']);
        $term = $this->termId('Laravel');
        DB::table('engineer_project_skills')->insert(['project_id' => $pid, 'term_id' => $term]);
        $threw = false;
        try {
            DB::table('engineer_project_skills')->insert(['project_id' => $pid, 'term_id' => $term]);
        } catch (QueryException $e) {
            $threw = true;
        }
        $this->assertTrue($threw, '同じ組み合わせが2回入った');

        // スキル表は同じ (engineer_id, term_id) を2行持てる（一意にしない）
        $row = ['engineer_id' => 1, 'name' => 'Laravel', 'category' => 'fw', 'term_id' => $term, 'years' => 1, 'level' => 3];
        DB::table('engineer_skills')->insert($row);
        DB::table('engineer_skills')->insert($row);
        $this->assertSame(2, DB::table('engineer_skills')->where('engineer_id', 1)->where('term_id', $term)->count());

        $found = collect(Schema::getIndexes('engineer_skills'))->first(fn ($i) => $i['columns'] === ['engineer_id', 'term_id']);
        $this->assertNotNull($found, '(engineer_id, term_id) の索引が無い');
        $this->assertFalse($found['unique']);
    }

    public function test_d_02_fills_only_master_tech_words(): void
    {
        $pid = (int) DB::table('engineer_projects')->insertGetId(['engineer_id' => 1, 'title' => '移し替えの見本', 'tech' => 'Laravel、要件定義、ZzNoMaster', 'langs' => 'PHP(Laravel)']);
        DB::table('engineer_project_skills')->delete();
        $terms = DB::table('skill_terms')->count();

        $this->fill();

        // 技術の分類だけが入る。工程（要件定義）とマスタに無い言葉は入らない
        $this->assertSame(['Laravel', 'PHP'], $this->projectTermNames($pid));
        $this->assertSame($terms, DB::table('skill_terms')->count());
        $this->assertSame(0, DB::table('skill_terms')->where('name', 'ZzNoMaster')->count());
    }

    public function test_d_03_fill_twice_gives_same_result(): void
    {
        DB::table('engineer_projects')->insert(['engineer_id' => 1, 'title' => '見本', 'tech' => 'Laravel、Docker', 'dbs' => 'MySQL']);
        DB::table('engineer_project_skills')->delete();
        $terms = DB::table('skill_terms')->count();

        $this->fill();
        $first = $this->indexRows();
        $this->assertNotEmpty($first);
        $this->fill();

        $this->assertSame($first, $this->indexRows());
        $this->assertSame($terms, DB::table('skill_terms')->count());
    }

    public function test_d_04_fill_leaves_original_data_untouched(): void
    {
        // term_id が空のスキル表の行も含めて、移し替えの前後で変わらない
        DB::table('engineer_skills')->insert(['engineer_id' => 1, 'name' => 'Laravel', 'category' => 'fw', 'term_id' => null, 'years' => 2, 'level' => 3]);
        DB::table('engineer_projects')->insert(['engineer_id' => 1, 'title' => '見本', 'tech' => 'laravel、ＬＡＲＡＶＥＬ、Laravel、TCP/IP、ZzNoMaster', 'os_tools' => 'Linux', 'langs' => 'PHP', 'dbs' => 'MySQL']);
        $snap = fn () => json_encode([
            DB::table('engineer_projects')->orderBy('id')->get(['id', 'tech', 'os_tools', 'langs', 'dbs'])->all(),
            DB::table('engineer_skills')->orderBy('id')->get()->all(),
        ]);
        $before = $snap();

        $this->fill();

        $this->assertSame($before, $snap());
    }

    public function test_d_05_down_and_up_again(): void
    {
        DB::table('engineer_projects')->insert(['engineer_id' => 1, 'title' => '戻しの見本', 'tech' => 'Laravel、Docker']);
        $strings = fn () => json_encode(DB::table('engineer_projects')->orderBy('id')->get(['id', 'tech', 'os_tools', 'langs', 'dbs'])->all());
        $before = $strings();

        // 新しい順に戻す
        (require database_path(self::MIG_FILL))->down();
        $this->assertSame(0, DB::table('engineer_project_skills')->count());
        (require database_path(self::MIG_TABLES))->down();

        $this->assertFalse(Schema::hasTable('engineer_project_skills'));
        $this->assertFalse(Schema::hasColumn('engineer_skills', 'last_used_ym'));
        $this->assertFalse(Schema::hasColumn('engineer_skills', 'is_primary'));
        $this->assertFalse(Schema::hasColumn('engineer_skills', 'note'));
        $this->assertSame($before, $strings());

        // 古い順に戻す
        (require database_path(self::MIG_TABLES))->up();
        $this->fill();
        $this->assertTrue(Schema::hasTable('engineer_project_skills'));
        $this->assertTrue(Schema::hasColumns('engineer_skills', ['last_used_ym', 'is_primary', 'note']));
        $this->assertGreaterThan(0, DB::table('engineer_project_skills')->count());
    }
}
