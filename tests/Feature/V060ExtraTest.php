<?php

namespace Tests\Feature;

use App\Services\CaseAi;
use App\Services\CaseParser;
use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.6.0 の抜けの補い（tester）：他社・EG・担当者・0件・2回押し・案内の種類・worker の合言葉。本文は架空の文だけ
class V060ExtraTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';
    private const RAW = "案件名：テスト用の在庫管理画面開発\n単価：60万円\n\n■必須\n・Java での開発経験が3年以上\n・SQL の基本的な知識\n\n■尚可\n・AWS の利用経験\n";

    private array $h = ['X-Park-Ai-Token' => 'test-token'];

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        config(['services.park_ai.token' => 'test-token']);
    }

    private function form(string $raw, array $over = []): array
    {
        $p = CaseParser::parse($raw);
        return $over + ['title' => 'テスト用の在庫管理画面開発', 'raw_text' => $raw, 'required_skills' => $p['required_skills'] ?? '', 'preferred_skills' => $p['preferred_skills'] ?? '',
            'required_exp' => $p['required_exp'] ?? null, 'preferred_exp' => $p['preferred_exp'] ?? null, 'price' => 60];
    }

    private function register(string $email, ?string $raw = null, array $over = []): int
    {
        $this->as($email)->post('/cases', $this->form($raw ?? self::RAW, $over))->assertRedirect();
        return (int) DB::table('cases')->max('id');
    }

    private function jobs(int $cid)
    {
        return DB::table('ai_jobs')->where('ref_type', 'case')->where('ref_id', $cid)->where('kind', 'case_skills');
    }

    private function cols(int $cid): array
    {
        return (array) DB::table('cases')->where('id', $cid)->first(['required_skills', 'preferred_skills', 'required_exp', 'preferred_exp']);
    }

    private function answer($req, $pref = []): int
    {
        $job = $this->postJson('/ai/worker/next', [], $this->h)->json('job');
        $out = is_string($req) ? $req : json_encode(['required' => $req, 'preferred' => $pref], JSON_UNESCAPED_UNICODE);
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => $out, 'engine' => 'test'], $this->h)->assertOk();
        return $job['id'];
    }

    private function st(int $id): string
    {
        return DB::table('ai_jobs')->where('id', $id)->value('status');
    }

    private function staff(): string
    {
        $id = W::createUser('staff60x@example.com', '担当 七郎', 'ses', 'park1234', ['ses_id' => (int) DB::table('users')->where('email', self::SES1)->value('ses_id')]);
        $this->completeProfile($id);
        return 'staff60x@example.com';
    }

    private function sameCount(): array
    {
        return [['Java での開発経験が3年以上', 'AWS の利用経験'], ['SQL の基本的な知識']];
    }

    public function test_worker_endpoints_need_token(): void
    {
        $this->register(self::ADMIN);
        $this->postJson('/ai/worker/next', [])->assertStatus(403);
        $this->postJson('/ai/worker/next', [], ['X-Park-Ai-Token' => 'wrong'])->assertStatus(403);
        $this->assertSame(1, DB::table('ai_jobs')->where('status', 'queued')->count());
    }

    public function test_ai_parse_without_text_or_token_adds_nothing(): void
    {
        $cid = $this->register(self::SES1, '');
        $this->as(self::SES1)->post("/cases/$cid/ai-parse")->assertRedirect();
        $this->assertSame(0, $this->jobs($cid)->count());
        $cid2 = $this->register(self::SES1);
        $this->jobs($cid2)->delete();
        config(['services.park_ai.token' => '']);
        $this->as(self::SES1)->post("/cases/$cid2/ai-parse")->assertRedirect();
        $this->assertSame(0, $this->jobs($cid2)->count());
    }

    public function test_ai_parse_twice_and_while_running_makes_one(): void
    {
        $cid = $this->register(self::SES1);
        $this->jobs($cid)->delete();
        $this->as(self::SES1)->post("/cases/$cid/ai-parse")->assertRedirect();
        $this->as(self::SES1)->post("/cases/$cid/ai-parse")->assertRedirect();
        $this->assertSame(1, $this->jobs($cid)->count());
        $this->postJson('/ai/worker/next', [], $this->h); // running
        $this->as(self::SES1)->post("/cases/$cid/ai-parse")->assertRedirect();
        $this->assertSame(1, $this->jobs($cid)->count());
    }

    public function test_other_company_and_eg_cannot_ai_parse_staff_can_and_sees_box(): void
    {
        $cid = $this->register(self::SES1);
        $this->jobs($cid)->delete();
        $this->as(self::SES2)->post("/cases/$cid/ai-parse")->assertForbidden();
        $this->as(self::EG1)->post("/cases/$cid/ai-parse")->assertForbidden();
        $this->assertSame(0, $this->jobs($cid)->count());
        $this->as($this->staff())->get("/cases/$cid")->assertOk()->assertSee('id="ai"', false)->assertSee('AI に読み直してもらう');
        $r = $this->as(self::SES2)->get("/cases/$cid");
        if ($r->status() === 200) $r->assertDontSee('id="ai"', false)->assertDontSee('AI に読み直してもらう');
        $r = $this->as(self::EG1)->get("/cases/$cid");
        if ($r->status() === 200) $r->assertDontSee('id="ai"', false)->assertDontSee('AI に読み直してもらう');
    }

    public function test_staff_can_keep_and_other_company_cannot_drop(): void
    {
        $cid = $this->register(self::SES1);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $before = $this->cols($cid);
        $this->as(self::SES2)->post("/ai-jobs/$id/drop")->assertForbidden();
        $this->as($this->staff())->post("/ai-jobs/$id/drop")->assertRedirect("/cases/$cid#ai");
        $this->assertSame('dropped', $this->st($id));
        $this->assertSame($before, $this->cols($cid));
        $this->as(self::SES1)->post("/ai-jobs/$id/drop")->assertStatus(400);
    }

    public function test_apply_case_on_not_done_job_is_400(): void
    {
        $cid = $this->register(self::ADMIN);
        $id = (int) $this->jobs($cid)->value('id');
        $before = $this->cols($cid);
        $this->as(self::ADMIN)->post("/ai-jobs/$id/apply-case")->assertStatus(400);
        $this->assertSame('queued', $this->st($id));
        $this->assertSame($before, $this->cols($cid));
    }

    public function test_case_of_ses2_is_isolated_from_ses1_staff(): void
    {
        $cid = $this->register(self::SES2);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->assertSame('done', $this->st($id));
        $this->as(self::SES1)->post("/ai-jobs/$id/apply-case")->assertForbidden();
        $this->as($this->staff())->post("/ai-jobs/$id/apply-case")->assertForbidden();
        $this->assertSame('done', $this->st($id));
        $this->assertNotNull($cid);
    }

    public function test_no_auto_when_case_mail_or_application_exists_and_draft_allows(): void
    {
        $admin = (int) DB::table('users')->where('email', self::ADMIN)->value('id');
        $eg = $this->engId(self::EG1);
        $sesId = (int) DB::table('users')->where('email', self::SES1)->value('ses_id');

        $c1 = $this->register(self::ADMIN);
        DB::table('case_mails')->insert(['case_id' => $c1, 'engineer_id' => $eg, 'ses_id' => null, 'sent_by' => $admin, 'route' => 'group', 'mode' => 'same',
            'subject' => '件名', 'body' => '本文', 'sent_at' => now_str()]);
        $b1 = $this->cols($c1);
        $this->assertSame('done', $this->st($this->answer(...$this->sameCount())));
        $this->assertSame($b1, $this->cols($c1));

        $c2 = $this->register(self::ADMIN);
        DB::table('applications')->insert(['case_id' => $c2, 'engineer_id' => $eg, 'ses_id' => $sesId, 'status' => 'applied', 'applied_at' => now_str()]);
        $b2 = $this->cols($c2);
        $this->assertSame('done', $this->st($this->answer(...$this->sameCount())));
        $this->assertSame($b2, $this->cols($c2));

        $c3 = $this->register(self::ADMIN);
        DB::table('cases')->where('id', $c3)->update(['status' => 'draft']);
        $this->assertSame('applied', $this->st($this->answer(...$this->sameCount())));
        $this->assertNotSame($this->cols($c3)['required_skills'], $b2['required_skills']);
    }

    public function test_auto_apply_for_ses_registered_case_changes_only_four_columns(): void
    {
        $cid = $this->register(self::SES1);
        $row = (array) DB::table('cases')->find($cid);
        $id = $this->answer(...$this->sameCount());
        $this->assertSame('applied', $this->st($id));
        $after = (array) DB::table('cases')->find($cid);
        foreach (['title', 'price', 'raw_text', 'status', 'source_ses_id'] as $k) $this->assertEquals($row[$k] ?? null, $after[$k] ?? null, $k);
    }

    public function test_result_posted_twice_does_not_change_finished_job(): void
    {
        $cid = $this->register(self::ADMIN);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->assertSame('done', $this->st($id));
        $this->postJson("/ai/worker/$id/result", ['output' => json_encode(['required' => [], 'preferred' => []]), 'engine' => 'test'], $this->h);
        $this->assertSame('done', $this->st($id));
        $this->assertSame(1, $this->jobs($cid)->count());
    }

    public function test_ai_empty_arrays_when_rule_has_rows_needs_choice(): void
    {
        $cid = $this->register(self::ADMIN);
        $before = $this->cols($cid);
        $id = $this->answer([], []);
        $this->assertSame('done', $this->st($id));
        $this->assertSame($before, $this->cols($cid));
        $cmp = CaseAi::compare(DB::table('ai_jobs')->find($id), DB::table('cases')->find($cid));
        $this->assertFalse($cmp['auto']);
    }

    public function test_garbage_output_fails_and_retry_works(): void
    {
        $cid = $this->register(self::ADMIN);
        $before = $this->cols($cid);
        $id = $this->answer('これは JSON ではありません');
        $this->assertSame('failed', $this->st($id));
        $this->assertSame($before, $this->cols($cid));
        $this->as(self::ADMIN)->post("/cases/$cid/ai-parse")->assertRedirect();
        $this->assertSame(1, $this->jobs($cid)->where('status', 'queued')->count());
    }

    public function test_sections_and_restore_zero_cases(): void
    {
        $this->assertSame(['required' => [], 'preferred' => []], CaseParser::sections(''));
        $this->assertSame(['required' => [], 'preferred' => []], CaseParser::sections("案件名：なし\n概要：説明だけの文\n"));
        $this->assertSame([], CaseAi::restore([], self::RAW));
    }

    public function test_text_changed_then_staff_cannot_use_old_job(): void
    {
        $cid = $this->register(self::SES1);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->as(self::SES1)->post("/cases/$cid", $this->form(self::RAW."\n■尚可\n・Docker の利用経験\n"))->assertRedirect();
        $after = $this->cols($cid);
        $this->as($this->staff())->post("/ai-jobs/$id/apply-case")->assertStatus(400);
        $this->assertSame($after, $this->cols($cid));
        $this->as(self::SES2)->post("/ai-jobs/$id/apply-case")->assertForbidden();
    }
}
