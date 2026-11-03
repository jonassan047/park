<?php

namespace Tests\Feature;

use App\Services\EngineerDocs;
use App\Services\SkillTable;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.3.0 テスト設計書 版 01：K（スキル表）K-01〜K-14。立場は EG 本人。保存は POST /engineers/{id}/skills
class V030SkillsTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const EG = 'eg2@park.example';

    private int $eid;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        $this->eid = $this->engId(self::EG);
        $this->cleanEg($this->eid);
    }

    private function save(array $rows, ?string $email = null)
    {
        $eid = $email ? $this->engId($email) : $this->eid;
        return $this->as($email ?? self::EG)->post("/engineers/$eid/skills", $this->skillForm($rows));
    }

    private function skills(?int $eid = null)
    {
        return DB::table('engineer_skills')->where('engineer_id', $eid ?? $this->eid)->orderBy('id')->get();
    }

    private function assertBad($response): void
    {
        $response->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && $f['msg'] !== '');
    }

    public function test_k_01_all_fields_are_saved_and_shown(): void
    {
        $this->save([['name' => 'Laravel', 'cat' => 'fw', 'years' => '3.5', 'level' => '4', 'last' => '2025-06', 'primary' => 1, 'note' => '保守まで担当しました']])
            ->assertRedirect("/engineers/{$this->eid}");

        $row = $this->skills()->first();
        $this->assertSame('Laravel', $row->name);
        $this->assertEquals(3.5, (float) $row->years);
        $this->assertSame(4, (int) $row->level);
        $this->assertSame('2025-06', $row->last_used_ym);
        $this->assertTrue((bool) $row->is_primary);
        $this->assertSame('保守まで担当しました', $row->note);

        $this->as(self::EG);
        $this->get("/engineers/{$this->eid}")->assertOk()->assertSee('Laravel')->assertSee('得意')->assertSee('保守まで担当しました');
        $this->get("/engineers/{$this->eid}/skills")->assertOk()->assertSee('2025-06')->assertSee('保守まで担当しました');
    }

    public function test_k_02_level_guide_has_the_five_sentences(): void
    {
        $page = $this->as(self::EG)->get("/engineers/{$this->eid}/skills")->assertOk();
        foreach (SkillTable::LEVELS as $text) $page->assertSee($text);
    }

    public function test_k_03_new_word_goes_to_master_as_unreviewed(): void
    {
        $this->save([['name' => 'ZzNewTech', 'cat' => 'tool']])->assertRedirect();

        $term = DB::table('skill_terms')->where('name', 'ZzNewTech')->first();
        $this->assertNotNull($term);
        $this->assertSame(0, (int) $term->reviewed);
        $this->assertSame((int) $term->id, (int) $this->skills()->first()->term_id);

        $this->as('admin@park.example')->get('/admin/skills')->assertOk()->assertSee('ZzNewTech')->assertSee('確認待ち');
    }

    public function test_k_04_name_length_limit(): void
    {
        $terms = DB::table('skill_terms')->count();
        $this->assertBad($this->save([['name' => str_repeat('Z', 41)]]));
        $this->assertCount(0, $this->skills());
        $this->assertSame($terms, DB::table('skill_terms')->count());

        $this->save([['name' => str_repeat('Z', 40)]])->assertRedirect("/engineers/{$this->eid}");
        $this->assertCount(1, $this->skills());
        $this->assertSame($terms + 1, DB::table('skill_terms')->count());
    }

    public function test_k_05_merges_same_term(): void
    {
        $this->save([
            ['name' => 'PostgreSQL', 'cat' => 'db', 'years' => '2', 'level' => '3'],
            ['name' => 'postgres', 'cat' => 'db', 'years' => '5', 'level' => '4', 'primary' => 1],
            ['name' => 'ＰｏｓｔｇｒｅＳＱＬ', 'cat' => 'db', 'years' => '1', 'level' => '2', 'note' => 'メモ'],
        ])->assertRedirect();

        $rows = $this->skills();
        $this->assertCount(1, $rows);
        $this->assertSame('PostgreSQL', $rows[0]->name);
        $this->assertEquals(5, (float) $rows[0]->years);
        $this->assertSame(4, (int) $rows[0]->level);
        $this->assertTrue((bool) $rows[0]->is_primary);
        $this->assertSame('メモ', $rows[0]->note);
    }

    public function test_k_06_saving_keeps_ids_of_remaining_rows(): void
    {
        $this->save([['name' => 'Java', 'level' => '3'], ['name' => 'PHP', 'level' => '3'], ['name' => 'Laravel', 'level' => '3']])->assertRedirect();
        $before = $this->skills()->keyBy('name');
        $this->assertCount(3, $before);

        $this->save([['name' => 'Java', 'level' => '5'], ['name' => 'PHP', 'level' => '3']])->assertRedirect();
        $after = $this->skills()->keyBy('name');

        $this->assertCount(2, $after);
        $this->assertFalse($after->has('Laravel'));
        foreach (['Java', 'PHP'] as $n) {
            $this->assertSame($before[$n]->id, $after[$n]->id, "$n の id が変わった");
            $this->assertSame($before[$n]->created_at, $after[$n]->created_at, "$n の登録日が変わった");
        }
        $this->assertSame(5, (int) $after['Java']->level);
    }

    public function test_k_07_audit_log_has_names_but_not_notes(): void
    {
        $this->save([['name' => 'Java', 'note' => 'ひみつのひとこと'], ['name' => 'PHP']])->assertRedirect();

        $log = DB::table('audit_log')->where('action', 'skill-save')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('Java', $log->detail);
        $this->assertStringContainsString('PHP', $log->detail);
        $this->assertStringNotContainsString('ひみつのひとこと', $log->detail);
    }

    public function test_k_08_more_than_five_primaries_is_refused(): void
    {
        $this->save([['name' => 'Java', 'level' => '2']])->assertRedirect();
        $six = array_map(fn ($n) => ['name' => $n, 'primary' => 1], ['Java', 'PHP', 'Go', 'Ruby', 'Python', 'Perl']);

        $this->assertBad($this->save($six));

        // 保存の前のまま
        $rows = $this->skills();
        $this->assertCount(1, $rows);
        $this->assertSame('Java', $rows[0]->name);
        $this->assertSame(2, (int) $rows[0]->level);
        $this->assertFalse((bool) $rows[0]->is_primary);

        // 5つまでは通る
        $this->save(array_slice($six, 0, 5))->assertRedirect();
        $this->assertCount(5, $this->skills());
    }

    public function test_k_09_last_used_month_is_checked(): void
    {
        $this->assertBad($this->save([['name' => 'Java', 'last' => '2019/04']]));
        $this->assertBad($this->save([['name' => 'Java', 'last' => now()->addMonth()->format('Y-m')]]));
        $this->assertCount(0, $this->skills());

        $this->save([['name' => 'Java', 'last' => '']])->assertRedirect();
        $this->assertNull($this->skills()->first()->last_used_ym);
        $this->save([['name' => 'Java', 'last' => '2020-05']])->assertRedirect();
        $this->assertSame('2020-05', $this->skills()->first()->last_used_ym);
    }

    public function test_k_10_note_length_limit(): void
    {
        $this->assertBad($this->save([['name' => 'Java', 'note' => str_repeat('あ', 201)]]));
        $this->assertCount(0, $this->skills());

        $this->save([['name' => 'Java', 'note' => str_repeat('あ', 200)]])->assertRedirect();
        $this->assertSame(200, mb_strlen($this->skills()->first()->note));
    }

    public function test_k_11_rows_without_term_id_are_filled_by_own_save_only(): void
    {
        $eg1 = $this->engId('eg1@park.example');
        $this->cleanEg($eg1);
        foreach ([$eg1, $this->eid] as $id) {
            DB::table('engineer_skills')->insert(['engineer_id' => $id, 'name' => 'Laravel', 'category' => 'fw', 'term_id' => null, 'years' => 2, 'level' => 3]);
        }
        $rowId = (int) DB::table('engineer_skills')->where('engineer_id', $eg1)->value('id');

        $this->save([['name' => 'Laravel', 'cat' => 'fw', 'years' => '2']], 'eg1@park.example')->assertRedirect();

        // EG1 の行は埋まる（同じ行）
        $row = DB::table('engineer_skills')->where('engineer_id', $eg1)->first();
        $this->assertSame($rowId, (int) $row->id);
        $this->assertSame($this->termId('Laravel'), (int) $row->term_id);
        $this->assertSame(1, DB::table('engineer_skills')->where('engineer_id', $eg1)->count());

        // EG2 の行は空のまま。画面も書き出しも落ちない
        $this->as(self::EG);
        foreach (["/engineers/{$this->eid}", "/engineers/{$this->eid}/skills", "/engineers/{$this->eid}/sheet"] as $url) $this->get($url)->assertOk();
        $this->assertIsArray(EngineerDocs::skillLines($this->eid));
        $this->assertNull(DB::table('engineer_skills')->where('engineer_id', $this->eid)->value('term_id'));
    }

    public function test_k_12_duplicates_are_shown_once_but_db_is_untouched(): void
    {
        $term = $this->termId('Laravel');
        foreach ([2, 4] as $y) {
            DB::table('engineer_skills')->insert(['engineer_id' => $this->eid, 'name' => 'Laravel', 'category' => 'fw', 'term_id' => $term, 'years' => $y, 'level' => 3]);
        }

        $html = $this->as(self::EG)->get("/engineers/{$this->eid}")->assertOk()->getContent();
        $this->assertSame(1, substr_count($html, '<b>Laravel</b>'));
        $lines = implode("\n", EngineerDocs::skillLines($this->eid));
        $this->assertSame(1, substr_count($lines, 'Laravel'));
        $this->assertStringContainsString('Laravel（4年）', $lines);
        $this->assertCount(2, $this->skills());
    }

    public function test_k_13_note_is_hidden_on_anonymous_sheet(): void
    {
        $this->save([['name' => 'Java', 'note' => 'ひとことのメモ']])->assertRedirect();

        $this->as(self::EG);
        $this->get("/engineers/{$this->eid}/sheet?anon=1")->assertOk()->assertDontSee('ひとことのメモ');
        $this->get("/engineers/{$this->eid}/sheet")->assertOk()->assertSee('ひとことのメモ');
    }

    public function test_k_14_primary_skills_come_first(): void
    {
        $this->save([['name' => 'Laravel', 'cat' => 'fw', 'primary' => 1], ['name' => 'PHP', 'cat' => 'lang']])->assertRedirect();

        $this->as(self::EG)->get("/engineers/{$this->eid}/sheet")->assertOk()->assertSee('得意な技術：Laravel');
        $lines = EngineerDocs::skillLines($this->eid);
        $this->assertStringStartsWith('【得意】', $lines[0]);
        $this->assertStringContainsString('Laravel', $lines[0]);
        $this->assertStringNotContainsString('PHP', $lines[0]);
    }
}
