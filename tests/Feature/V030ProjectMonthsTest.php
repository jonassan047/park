<?php

namespace Tests\Feature;

use App\Services\EngineerDocs;
use App\Services\Matcher;
use App\Services\ProjectSkills;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.3.0 テスト設計書 版 01：P（経歴の索引と月数）P-01〜P-14
class V030ProjectMonthsTest extends TestCase
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
        $this->travelTo(Carbon::parse('2026-11-15'));
    }

    // Laravel の経歴を作り、その技術の月数などを返す
    private function stat(array $projects, string $tech = 'Laravel'): array
    {
        foreach ($projects as [$from, $to]) $this->makeProject($this->eid, ['tech' => $tech, 'period_from' => $from, 'period_to' => $to]);
        return ProjectSkills::stats([$this->eid])[$this->eid][$this->termId($tech)] ?? [];
    }

    // ---------- 経歴の保存と索引 ----------

    public function test_p_01_save_project_builds_index(): void
    {
        $this->as(self::EG)->post("/engineers/{$this->eid}/projects", ['title' => '案件A', 'tech' => 'Laravel、Docker'])->assertRedirect();
        $pid = (int) DB::table('engineer_projects')->where('engineer_id', $this->eid)->value('id');
        $this->assertSame(['Docker', 'Laravel'], $this->projectTermNames($pid));

        // 同じ経歴を直すと、索引も作り直される
        $this->as(self::EG)->post("/engineers/{$this->eid}/projects/$pid", ['title' => '案件A', 'tech' => 'Laravel、AWS'])->assertRedirect();
        $this->assertSame(['AWS', 'Laravel'], $this->projectTermNames($pid));
    }

    public function test_p_02_ai_draft_builds_index(): void
    {
        $draft = ['projects' => [['title' => 'AI の下書きの案件', 'period_from' => '2021-04', 'period_to' => '2022-03', 'tech' => 'Laravel、Docker', 'phases' => ['実装']]]];
        $jid = DB::table('ai_jobs')->insertGetId(['kind' => 'resume', 'ref_type' => 'engineer_file', 'ref_id' => 1, 'input' => json_encode(['engineer_id' => $this->eid]),
            'status' => 'done', 'output' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'created_at' => now_str()]);

        $this->as(self::EG)->post("/ai-jobs/$jid/project/0")->assertRedirect();

        $pid = (int) DB::table('engineer_projects')->where('engineer_id', $this->eid)->where('title', 'AI の下書きの案件')->value('id');
        $this->assertGreaterThan(0, $pid);
        $this->assertSame(['Docker', 'Laravel'], $this->projectTermNames($pid));
    }

    public function test_p_03_delete_project_removes_index_rows(): void
    {
        $pid = $this->makeProject($this->eid, ['tech' => 'Laravel、Docker']);
        $this->assertSame(2, DB::table('engineer_project_skills')->where('project_id', $pid)->count());

        $this->as(self::EG)->post("/engineers/{$this->eid}/projects/$pid/delete")->assertRedirect();

        $this->assertSame(0, DB::table('engineer_projects')->where('id', $pid)->count());
        $this->assertSame(0, DB::table('engineer_project_skills')->where('project_id', $pid)->count());
    }

    // ---------- 月数の数え方 ----------

    public function test_p_04_overlap_counts_once(): void
    {
        // 2019-04〜2020-03（12か月）と 2020-01〜2020-06 は重なる。合わせて 2019-04〜2020-06 の 15 か月
        $s = $this->stat([['2019-04', '2020-03'], ['2020-01', '2020-06']]);
        $this->assertSame(15, $s['months']);
        $this->assertSame(2, $s['projects']);
        $this->assertSame('2020-06', $s['last']);
    }

    public function test_p_05_open_end_counts_to_this_month(): void
    {
        $this->assertSame(11, $this->stat([['2026-01', null]])['months']); // 2026-01〜2026-11
    }

    public function test_p_06_broken_period_counts_zero_and_pages_do_not_fail(): void
    {
        $s = $this->stat([[null, null], ['2019/4', '2020-03'], ['令和1年', null]]);
        $this->assertSame(0, $s['months']);
        $this->assertSame(3, $s['projects']); // 期間が数えられなくても件数には入る
        $this->assertSame(0, month_diff('2019/4', '2020-01'));
        $this->assertSame(0, month_diff('2020-01', '2019/4'));

        $this->as(self::EG);
        foreach (["/engineers/{$this->eid}", "/engineers/{$this->eid}/sheet", "/engineers/{$this->eid}/skills"] as $url) $this->get($url)->assertOk();
        $this->assertIsArray(EngineerDocs::skillLines($this->eid));
        $this->assertIsArray(Matcher::forCase(1, true));
        // 業務経歴書の書き出しも落ちない
        $this->assertNotNull(EngineerDocs::careersheet($this->eid));
    }

    public function test_p_07_reversed_period_counts_zero(): void
    {
        $this->assertSame(0, $this->stat([['2020-05', '2020-01']])['months']);
    }

    public function test_p_08_end_in_future_is_cut_at_this_month(): void
    {
        $this->assertSame(11, $this->stat([['2026-01', '2027-12']])['months']);
    }

    public function test_p_09_period_format_is_checked_on_save(): void
    {
        $url = "/engineers/{$this->eid}/projects";
        $count = fn () => DB::table('engineer_projects')->where('engineer_id', $this->eid)->count();

        $this->as(self::EG)->post($url, ['title' => '形が違う', 'tech' => 'Laravel', 'period_from' => '2019/04'])
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && str_contains($f['msg'], '2019-04'));
        $this->as(self::EG)->post($url, ['title' => '形が違う', 'tech' => 'Laravel', 'period_from' => '2019-04', 'period_to' => 'abc'])
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertSame(0, $count());

        // 終わりが空なら通る（今も続いている案件）
        $this->as(self::EG)->post($url, ['title' => '続いている', 'tech' => 'Laravel', 'period_from' => '2019-04', 'period_to' => ''])->assertRedirect("/engineers/{$this->eid}");
        $this->assertSame(1, $count());
    }

    // ---------- 注意の文と月数（v0.4.0 で、スキル画面・詳細から外した） ----------

    public function test_p_10_13_career_months_and_warning_are_not_shown_on_skill_screens(): void
    {
        // 年数が4段階になり、行ごとの欄も無くなったので、申告と経歴のずれの注意・経歴から数えた月数は出さない（索引は残る）
        DB::table('engineer_skill_checks')->insert(['engineer_id' => $this->eid, 'section' => 'lang', 'parent' => 'PHP', 'item' => 'Laravel', 'other_text' => '',
            'years_min' => 5, 'source' => 'form', 'created_at' => now(), 'updated_at' => now()]);
        $this->makeProject($this->eid, ['tech' => 'Laravel', 'period_from' => '2022-11', 'period_to' => '2024-10']); // 24 か月
        $this->as(self::EG);
        foreach (["/engineers/{$this->eid}/skills", "/engineers/{$this->eid}"] as $url) {
            $this->get($url)->assertOk()->assertDontSee('経歴に書かれていない期間があります')->assertDontSee('経歴 2年');
        }
        $this->assertSame(24, ProjectSkills::stats([$this->eid])[$this->eid][$this->termId('Laravel')]['months']);
    }

    // ---------- 候補は確認済みの言葉だけ ----------

    public function test_p_14_candidates_are_reviewed_terms_only(): void
    {
        $now = now_str();
        DB::table('skill_terms')->insert([
            ['name' => 'ZzReviewed', 'category' => 'tool', 'source' => 'user', 'reviewed' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'ZzUnreviewed', 'category' => 'tool', 'source' => 'auto', 'reviewed' => 0, 'created_at' => $now, 'updated_at' => $now],
        ]);
        $this->as(self::EG);
        $this->get("/engineers/{$this->eid}/projects/new")->assertOk()->assertSee('ZzReviewed')->assertDontSee('ZzUnreviewed');
    }
}
