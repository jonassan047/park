<?php

namespace Tests\Feature;

use App\Services\SkillTable;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.3.0 テスト設計書 版 02：M（技術マスタ）M-01〜M-03。まとめる処理（SkillTable::mergeTerms）と、使っている人の数え方
class V030MasterTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const EG2 = 'eg2@park.example';

    private int $eid;
    private int $old;  // まとめられる言葉 B
    private int $new;  // まとめ先 A

    protected function setUp(): void
    {
        parent::setUp();
        $this->eid = $this->engId(self::EG2);
        $this->old = $this->term('ZzOld');
        $this->new = $this->term('ZzNew');
        $this->cleanEg($this->eid);
    }

    // 確認済みの技術の言葉を足す。term_id を返す
    private function term(string $name): int
    {
        $id = (int) DB::table('skill_terms')->insertGetId(['name' => $name, 'category' => 'tool', 'aliases' => '', 'source' => 'user', 'reviewed' => true,
            'created_at' => now(), 'updated_at' => now()]);
        SkillCatalog::forget();
        return $id;
    }

    // スキル表に直接1行入れる
    private function skillRow(int $eid, int $termId, string $name, array $v = []): int
    {
        return (int) DB::table('engineer_skills')->insertGetId($v + ['engineer_id' => $eid, 'term_id' => $termId, 'name' => $name, 'category' => 'tool', 'years' => 1, 'level' => 3]);
    }

    private function projectRows(int $termId): int
    {
        return DB::table('engineer_project_skills')->where('term_id', $termId)->count();
    }

    public function test_m_01_merge_moves_project_index_and_skill_rows(): void
    {
        // 経歴：B だけの経歴と、A と B が両方ある経歴
        $p1 = $this->makeProject($this->eid, ['tech' => 'ZzOld']);
        $p2 = $this->makeProject($this->eid, ['tech' => 'ZzOld、ZzNew']);
        $this->assertSame(1, DB::table('engineer_project_skills')->where('project_id', $p1)->where('term_id', $this->old)->count());
        // スキル表：同じ人に A と B の両方
        $this->skillRow($this->eid, $this->old, 'ZzOld', ['years' => 2, 'level' => 2, 'note' => '先の行のひとこと']);
        $this->skillRow($this->eid, $this->new, 'ZzNew', ['years' => 3, 'level' => 4, 'is_primary' => true, 'last_used_ym' => '2025-01']);

        $this->as(self::ADMIN)->post("/admin/skills/{$this->old}/merge", ['to' => $this->new])->assertRedirect('/admin/skills');

        $this->assertNull(DB::table('skill_terms')->find($this->old), 'B はマスタから消える');
        $this->assertSame(0, $this->projectRows($this->old));
        $this->assertSame(1, DB::table('engineer_project_skills')->where('project_id', $p1)->where('term_id', $this->new)->count());
        $this->assertSame(1, DB::table('engineer_project_skills')->where('project_id', $p2)->where('term_id', $this->new)->count(), '同じ経歴では1行');
        $this->assertSame(2, $this->projectRows($this->new));

        $rows = DB::table('engineer_skills')->where('engineer_id', $this->eid)->get();
        $this->assertCount(1, $rows);
        $r = $rows->first();
        $this->assertSame($this->new, (int) $r->term_id);
        $this->assertSame('ZzNew', $r->name);
        $this->assertEquals(3, (float) $r->years);
        $this->assertSame(4, (int) $r->level);
        $this->assertTrue((bool) $r->is_primary);
        $this->assertSame('2025-01', $r->last_used_ym);
        $this->assertSame('先の行のひとこと', $r->note);
        $this->assertStringContainsString('ZzOld', (string) DB::table('skill_terms')->where('id', $this->new)->value('aliases'));
    }

    // 統合先が技術ではない分類（method）のときは、経歴の索引に入らない
    public function test_m_01_merge_into_non_tech_term_stays_out_of_project_index(): void
    {
        $p = $this->makeProject($this->eid, ['tech' => 'ZzOld']);
        $this->assertSame(1, $this->projectRows($this->old));
        DB::table('skill_terms')->where('id', $this->new)->update(['category' => 'method']);
        SkillCatalog::forget();

        $this->as(self::ADMIN)->post("/admin/skills/{$this->old}/merge", ['to' => $this->new])->assertRedirect('/admin/skills');

        $this->assertSame(0, $this->projectRows($this->new), '技術ではない言葉は索引に入らない');
        $this->assertSame(0, DB::table('engineer_project_skills')->where('project_id', $p)->count());
    }

    public function test_m_02_failure_rolls_everything_back(): void
    {
        $p = $this->makeProject($this->eid, ['tech' => 'ZzOld']);
        $this->skillRow($this->eid, $this->old, 'ZzOld');
        $this->skillRow($this->eid, $this->new, 'ZzNew');
        $aliases = DB::table('skill_terms')->where('id', $this->new)->value('aliases');

        // 最後の「マスタの B を消す」で失敗させる
        DB::unprepared("CREATE TRIGGER boom BEFORE DELETE ON skill_terms BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            SkillTable::mergeTerms($this->old, $this->new);
            $this->fail('例外になるはず');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('boom', $e->getMessage());
        }

        $this->assertSame(1, DB::table('engineer_project_skills')->where('project_id', $p)->where('term_id', $this->old)->count());
        $this->assertSame(0, DB::table('engineer_project_skills')->where('project_id', $p)->where('term_id', $this->new)->count());
        $this->assertSame(2, DB::table('engineer_skills')->where('engineer_id', $this->eid)->count());
        $this->assertSame(1, DB::table('engineer_skills')->where('term_id', $this->old)->count());
        $this->assertSame($aliases, DB::table('skill_terms')->where('id', $this->new)->value('aliases'));
        $this->assertNotNull(DB::table('skill_terms')->find($this->old));
    }

    public function test_m_03_used_counts_people_from_skill_table_and_projects(): void
    {
        $e1 = $this->engId('eg1@park.example');
        $e3 = $this->engId('eg3@park.example');
        $this->cleanEg($e1);
        $this->cleanEg($e3);
        $this->skillRow($e1, $this->new, 'ZzNew');                                            // スキル表だけ
        $this->makeProject($this->eid, ['tech' => 'ZzNew']);                                  // 経歴だけ
        $this->skillRow($e3, $this->new, 'ZzNew');                                            // 両方（2件の経歴があっても1人）
        $this->makeProject($e3, ['tech' => 'ZzNew']);
        $this->makeProject($e3, ['tech' => 'ZzNew、Docker']);

        $this->assertSame(3, SkillTable::usedCounts()[$this->new]);
        $this->as(self::ADMIN)->get('/admin/skills')->assertOk()->assertSee('使っている人（スキル表・経歴）')->assertSee('3人');
    }

    // 15章の確認 2：管理者が言葉を足したら、あとから足された言葉も経歴の索引に入る
    public function test_m_04_adding_a_term_rebuilds_project_index(): void
    {
        $p = $this->makeProject($this->eid, ['tech' => 'ZzLater']);
        $this->assertSame([], $this->projectTermNames($p));

        $this->as(self::ADMIN)->post('/admin/skills', ['name' => 'ZzLater', 'category' => 'tool'])->assertRedirect('/admin/skills');
        $this->assertSame(['ZzLater'], $this->projectTermNames($p));
    }
}
