<?php

namespace Tests\Feature;

use App\Services\SkillChecks;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.4.0 今のスキル（engineer_skills）を新しい表（engineer_skill_checks）に移す（PLAN.md 11章）
class SkillMigrateTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private int $eid;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        $this->eid = $this->engId('eg2@park.example');
        $this->cleanEg($this->eid);
    }

    private function old(int $eid, string $name, string $cat, ?float $years): void
    {
        DB::table('engineer_skills')->insert(['engineer_id' => $eid, 'name' => $name, 'category' => $cat, 'years' => $years, 'level' => 3]);
    }

    private function oldSnapshot(): string
    {
        return json_encode(DB::table('engineer_skills')->orderBy('id')->get()->all());
    }

    // 並べて比べやすい形（道筋と年数）にする
    private function rows(int $eid): array
    {
        return $this->checkRows($eid)->map(fn ($r) => [$r->section, $r->parent, $r->item, $r->other_text, $r->years_min === null ? null : (int) $r->years_min, $r->source])->sortBy(fn ($r) => implode('|', $r), SORT_STRING)->values()->all();
    }

    private function seedOld(): void
    {
        $this->old($this->eid, 'Java', 'lang', 4);                // 合う名前 → lang//Java、4年 → 3年以上
        $this->old($this->eid, 'Spring', 'fw', 2.5);              // → Java の下の Spring Framework、1年以上
        $this->old($this->eid, 'ZzMyOwn', 'other', null);         // 合わない名前 → そのほかのその他、年数なし
        $this->old($this->eid, 'PHP', 'lang', 6);                 // 同じ技術が2行 → 年数の大きいほう（5年以上）
        $this->old($this->eid, 'php', 'lang', 1);
        $this->old($this->eid, 'ZzCloudTool', 'cloud', 3);        // 開発・運用ツールのクラウドのその他 → 年数なし
    }

    public function test_migrates_old_rows_by_the_rules(): void
    {
        $this->seedOld();
        $before = $this->oldSnapshot();
        $this->assertGreaterThanOrEqual(1, SkillChecks::migrateFromOld());

        $heading = SkillForm::headingGroupName();
        // rows() は「節|親|名前|その他の名前|年数|source」の文字の順に並べる
        $this->assertSame([
            ['dev', 'クラウド', 'その他', 'ZzCloudTool', null, 'migrated'],
            ['lang', 'Java', 'Spring Framework', '', 1, 'migrated'],
            ['lang', '', 'Java', '', 3, 'migrated'],
            ['lang', '', 'PHP', '', 5, 'migrated'],
            ['lang', $heading, 'その他', 'ZzMyOwn', null, 'migrated'],
        ], $this->rows($this->eid));
        $this->assertSame($before, $this->oldSnapshot(), '古い表は変わらない');
    }

    public function test_running_twice_gives_the_same_result(): void
    {
        $this->seedOld();
        SkillChecks::migrateFromOld();
        $first = $this->rows($this->eid);
        $this->assertNotEmpty($first);
        SkillChecks::migrateFromOld();
        $this->assertSame($first, $this->rows($this->eid));
    }

    public function test_people_who_already_have_new_rows_are_skipped(): void
    {
        $eg1 = $this->engId('eg1@park.example');
        $this->assertGreaterThan(0, DB::table('engineer_skill_checks')->where('engineer_id', $eg1)->count()); // 見本の行
        $mine = $this->rows($eg1);
        $this->old($eg1, 'Go', 'lang', 2);
        $this->seedOld();

        SkillChecks::migrateFromOld();

        $this->assertSame($mine, $this->rows($eg1), '新しい表に行がある人は変えない');
        $this->assertNotEmpty($this->rows($this->eid));
    }

    public function test_down_removes_only_migrated_rows(): void
    {
        $this->seedOld();
        SkillChecks::migrateFromOld();
        $this->as('eg2@park.example')->post("/engineers/{$this->eid}/skills", $this->checkForm(['lang/Go' => '']))->assertRedirect(); // 画面で保存（古い行は入れ替わる）
        $this->assertSame([['lang', '', 'Go', '', 1, 'form']], $this->rows($this->eid));

        $eg3 = $this->engId('eg3@park.example');
        $this->cleanEg($eg3);
        $this->old($eg3, 'Java', 'lang', 4);
        SkillChecks::migrateFromOld();
        DB::table('engineer_skill_checks')->insert(['engineer_id' => $eg3, 'section' => 'lang', 'parent' => '', 'item' => 'Go', 'other_text' => '', 'years_min' => null,
            'source' => 'form', 'created_at' => now(), 'updated_at' => now()]);

        (require database_path('migrations/2026_11_11_000002_fill_engineer_skill_checks.php'))->down();

        $this->assertSame([['lang', '', 'Go', '', null, 'form']], $this->rows($eg3));
        $this->assertSame(0, DB::table('engineer_skill_checks')->where('source', 'migrated')->count());
    }
}
