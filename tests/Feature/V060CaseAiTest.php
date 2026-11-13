<?php

namespace Tests\Feature;

use App\Services\CaseAi;
use App\Services\CaseParser;
use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.6.0 段階2・3：案件の必須・尚可を手元の AI に読み直してもらう（PLAN.md 6章 T7〜T24）。
// 本文は架空の文だけ。画面の欄の文は段階4で作るので、ここでは状態・DB・audit_log・知らせ・403 を確かめる
class V060CaseAiTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';

    // 規則で読むと：必須＝Java…・SQL…、尚可＝AWS…
    private const RAW = "案件名：テスト用の在庫管理画面開発\n単価：60万円\n\n■必須\n・Java での開発経験が3年以上\n・SQL の基本的な知識\n\n■尚可\n・AWS の利用経験\n";

    private array $h = ['X-Park-Ai-Token' => 'test-token'];

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        config(['services.park_ai.token' => 'test-token']);
    }

    // 取り込みの結果をそのまま入れた登録の送信の形
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

    // worker が仕事を1つ取り、答えを返す。仕事の id を返す
    private function answer($req, $pref = []): int
    {
        $job = $this->postJson('/ai/worker/next', [], $this->h)->json('job');
        $this->assertSame('case_skills', $job['kind']);
        $out = is_string($req) ? $req : json_encode(['required' => $req, 'preferred' => $pref], JSON_UNESCAPED_UNICODE);
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => $out, 'engine' => 'test'], $this->h)->assertOk();
        return $job['id'];
    }

    // 規則より行数は同じで、中身が違う答え（どの行も本文にある）
    private function sameCountAnswer(): array
    {
        return [['Java での開発経験が3年以上', 'AWS の利用経験'], ['SQL の基本的な知識']];
    }

    private function jobStatus(int $jobId): string
    {
        return DB::table('ai_jobs')->where('id', $jobId)->value('status');
    }

    private function staff(): string
    {
        $id = W::createUser('staff60@example.com', '担当 六郎', 'ses', 'park1234', ['ses_id' => (int) DB::table('users')->where('email', self::SES1)->value('ses_id')]);
        $this->completeProfile($id);
        return 'staff60@example.com';
    }

    private function aiNotices(int $cid)
    {
        return DB::table('app_notifications')->where('title', 'like', 'AI の読み直し%')->where('link', "/cases/$cid#ai");
    }

    public function test_import_does_not_queue_and_register_queues(): void // T7・T8
    {
        $this->as(self::ADMIN)->post('/cases/import', ['raw_text' => self::RAW])->assertOk();
        $this->assertSame(0, DB::table('ai_jobs')->count());

        $cid = $this->register(self::ADMIN);
        $job = $this->jobs($cid)->first();
        $this->assertNotNull($job);
        $this->assertSame('queued', $job->status);
        $in = json_decode($job->input, true);
        foreach (['system', 'user', 'options', 'text_hash', 'rule', 'base', 'edited', 'prompt', 'actor_role'] as $k) $this->assertArrayHasKey($k, $in);
        $this->assertSame(CaseAi::PROMPT, $in['system']);
        $this->assertSame(trim(self::RAW), $in['user']);
        $this->assertSame(0, $in['options']['temperature']);
        $this->assertSame(sha1(trim(self::RAW)), $in['text_hash']);
        $this->assertFalse($in['edited']);
        $this->assertCount(2, $in['rule']['required']);
        $this->assertCount(1, $in['rule']['preferred']);
    }

    public function test_edited_before_register(): void // T9
    {
        $cid = $this->register(self::ADMIN, null, ['required_skills' => 'Java']);
        $this->assertTrue(json_decode($this->jobs($cid)->value('input'), true)['edited']);
    }

    public function test_no_job_when_blocked(): void // T10
    {
        $cid = $this->register(self::ADMIN, '');
        $this->assertSame(0, $this->jobs($cid)->count());
        $long = "■必須\n・".str_repeat('あ', CaseAi::MAX_TEXT + 1);
        $cid = $this->register(self::ADMIN, $long);
        $this->assertSame(0, $this->jobs($cid)->count());
        config(['services.park_ai.token' => '']);
        $cid = $this->register(self::ADMIN);
        $this->assertDatabaseHas('cases', ['id' => $cid]);
        $this->assertSame(0, $this->jobs($cid)->count());
    }

    public function test_requeue_only_when_text_changes(): void // T11
    {
        $cid = $this->register(self::ADMIN);
        $first = $this->jobs($cid)->value('id');
        $this->post("/cases/$cid", $this->form(self::RAW, ['title' => '題名だけ変更']))->assertRedirect();
        $this->assertSame(1, $this->jobs($cid)->count());
        $this->post("/cases/$cid", $this->form(self::RAW."\n■尚可\n・Docker の利用経験\n"))->assertRedirect();
        $this->assertSame('dropped', $this->jobStatus($first));
        $this->assertSame(1, $this->jobs($cid)->where('status', 'queued')->count());
    }

    public function test_ai_parse_button_and_permissions(): void // T12
    {
        $cid = $this->register(self::SES1);
        $this->assertSame(1, $this->jobs($cid)->count());
        $this->post("/cases/$cid/ai-parse")->assertRedirect(); // 順番待ちがあるので増えない
        $this->assertSame(1, $this->jobs($cid)->count());
        $this->as(self::SES2)->post("/cases/$cid/ai-parse")->assertForbidden();
        $this->as(self::EG1)->post("/cases/$cid/ai-parse")->assertForbidden();
        $this->jobs($cid)->update(['status' => 'dropped']);
        $this->as($this->staff())->post("/cases/$cid/ai-parse")->assertRedirect(); // 同じ会社の担当者
        $this->assertSame(2, $this->jobs($cid)->count());
    }

    public function test_worker_next_shape(): void // T13
    {
        $this->as(self::ADMIN);
        $ivId = DB::table('interviews')->where('application_id', 1)->value('id');
        $this->post("/interviews/$ivId/ai")->assertRedirect();
        $cid = $this->register(self::ADMIN);
        $script = $this->postJson('/ai/worker/next', [], $this->h)->json('job');
        $this->assertSame('script', $script['kind']);
        $this->assertArrayNotHasKey('system', $script);
        $this->assertArrayNotHasKey('options', $script);
        $this->assertNotSame('', $script['prompt']);
        $job = $this->postJson('/ai/worker/next', [], $this->h)->json('job');
        $this->assertSame('case_skills', $job['kind']);
        $this->assertSame(CaseAi::PROMPT, $job['system']);
        $this->assertSame(trim(self::RAW), $job['user']);
        $this->assertSame(0, $job['options']['temperature']);
        $this->assertStringContainsString('募集メール：', $job['prompt']);
    }

    public function test_bad_answers_fail_and_keep_columns(): void // T14・S8
    {
        $cid = $this->register(self::ADMIN);
        $before = $this->cols($cid);
        foreach ([json_encode(['items' => []]), 'これは JSON ではありません'] as $i => $out) {
            if ($i) $this->as(self::ADMIN)->post("/cases/$cid/ai-parse")->assertRedirect();
            $id = $this->answer($out);
            $this->assertSame('failed', $this->jobStatus($id));
            $this->assertNotEmpty(DB::table('ai_jobs')->where('id', $id)->value('error'));
        }
        $this->as(self::ADMIN)->post("/cases/$cid/ai-parse")->assertRedirect();
        $job = $this->postJson('/ai/worker/next', [], $this->h)->json('job');
        $this->postJson("/ai/worker/{$job['id']}/result", ['error' => 'AI が止まっています', 'engine' => 'test'], $this->h)->assertOk();
        $this->assertSame('failed', $this->jobStatus($job['id']));
        $this->assertSame($before, $this->cols($cid));
    }

    public function test_restore(): void // T15
    {
        $text = "■必須\n・Java での開発経験が3年以上\n・SQL の基本的な知識\n※ 3年未満は応相談\n・チームでの設計レビュー\n";
        $r = CaseAi::restore([
            'Java での開発経験が3年以上',
            'SQL の基本的な知識 ※ 3年未満は応相談',
            'Java での開発経験が3年以下',
            'Kubernetes クラスタの運用設計の実績',
        ], $text);
        $this->assertSame('ok', $r[0]['mark']);
        $this->assertSame('ok', $r[1]['mark']);
        $this->assertSame('fixed', $r[2]['mark']);
        $this->assertSame('Java での開発経験が3年以上', $r[2]['text']);
        $this->assertSame('Java での開発経験が3年以下', $r[2]['from']);
        $this->assertSame('missing', $r[3]['mark']);
        $this->assertSame('Kubernetes クラスタの運用設計の実績', $r[3]['text']);
    }

    public function test_auto_apply(): void // T16
    {
        $cid = $this->register(self::ADMIN);
        $price = DB::table('cases')->where('id', $cid)->value('price');
        $id = $this->answer(...$this->sameCountAnswer());
        $this->assertSame('applied', $this->jobStatus($id));
        $c = DB::table('cases')->find($cid);
        $this->assertStringContainsString('AWS の利用経験', $c->required_skills);
        $this->assertStringContainsString('SQL の基本的な知識', $c->preferred_skills);
        $this->assertStringNotContainsString('SQL の基本的な知識', $c->required_skills);
        $this->assertEquals($price, $c->price);
        $this->assertDatabaseHas('audit_log', ['action' => 'ai-case-auto']);
        $this->assertSame(0, $this->aiNotices($cid)->count());
    }

    public function test_choose_when_different(): void // T17・T22
    {
        $cid = $this->register(self::SES1);
        $before = $this->cols($cid);
        $id = $this->answer(['Java での開発経験が3年以上', 'SQL の基本的な知識', 'AWS の利用経験'], ['AWS の利用経験']);
        $this->assertSame('done', $this->jobStatus($id));
        $this->assertSame($before, $this->cols($cid));
        $n = $this->aiNotices($cid)->first();
        $this->assertNotNull($n);
        $this->assertEquals(DB::table('users')->where('email', self::SES1)->value('id'), $n->user_id);
        $this->assertSame(0, DB::table('mail_outbox')->where('subject', 'like', 'AI の読み直し%')->count());

        // 担当者が「AI の結果を使う」
        $this->as($this->staff())->post("/ai-jobs/$id/apply-case")->assertRedirect("/cases/$cid#ai");
        $this->assertSame('applied', $this->jobStatus($id));
        $this->assertStringContainsString('AWS の利用経験', DB::table('cases')->where('id', $cid)->value('required_skills'));
        $this->assertDatabaseHas('audit_log', ['action' => 'ai-case-apply']);

        // 別の案件で「今のままにする」
        $cid2 = $this->register(self::SES1);
        $before2 = $this->cols($cid2);
        $id2 = $this->answer(['Java での開発経験が3年以上'], []);
        $this->assertSame('done', $this->jobStatus($id2));
        $this->as(self::SES1)->post("/ai-jobs/$id2/drop")->assertRedirect("/cases/$cid2#ai");
        $this->assertSame('dropped', $this->jobStatus($id2));
        $this->assertSame($before2, $this->cols($cid2));
        $this->assertDatabaseHas('audit_log', ['action' => 'ai-case-keep']);
    }

    public function test_no_auto_when_conditions_fail(): void // T18
    {
        $admin = (int) DB::table('users')->where('email', self::ADMIN)->value('id');
        $cases = [
            '登録の後に人が直した' => function (int $cid) { $this->as(self::ADMIN)->post("/cases/$cid", $this->form(self::RAW, ['required_skills' => 'Java']))->assertRedirect(); },
            '案内がある' => function (int $cid) use ($admin) {
                DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $this->engId(self::EG1), 'score' => 50, 'reasons' => '', 'missing' => '', 'sent_by' => $admin, 'sent_at' => now_str()]);
            },
            '募集を終えた' => function (int $cid) { DB::table('cases')->where('id', $cid)->update(['status' => 'closed']); },
            '登録の前に人が直した' => null,
        ];
        foreach ($cases as $label => $prepare) {
            $cid = $label === '登録の前に人が直した' ? $this->register(self::ADMIN, null, ['required_skills' => 'Java']) : $this->register(self::ADMIN);
            if ($prepare) $prepare($cid);
            $before = $this->cols($cid);
            $id = $this->answer(...$this->sameCountAnswer());
            $this->assertSame('done', $this->jobStatus($id), $label);
            $this->assertSame($before, $this->cols($cid), $label);
        }
        $this->assertDatabaseMissing('audit_log', ['action' => 'ai-case-auto']);
    }

    public function test_same_as_rule_closes_without_notice(): void // T19
    {
        $cid = $this->register(self::ADMIN);
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $this->engId(self::EG1), 'score' => 50, 'reasons' => '', 'missing' => '',
            'sent_by' => DB::table('users')->where('email', self::ADMIN)->value('id'), 'sent_at' => now_str()]);
        $before = $this->cols($cid);
        $id = $this->answer(['Java での開発経験が3年以上', 'SQL の基本的な知識'], ['AWS の利用経験']);
        $this->assertSame('dropped', $this->jobStatus($id));
        $this->assertSame($before, $this->cols($cid));
        $this->assertDatabaseHas('audit_log', ['action' => 'ai-case-same']);
        $this->assertSame(0, $this->aiNotices($cid)->count());
    }

    public function test_missing_wording_needs_choice(): void // T20
    {
        $cid = $this->register(self::ADMIN);
        $before = $this->cols($cid);
        $id = $this->answer(['Java での開発経験が3年以上', 'Kubernetes クラスタの運用設計の実績'], ['AWS の利用経験']);
        $this->assertSame('done', $this->jobStatus($id));
        $this->assertSame($before, $this->cols($cid));
        $cmp = CaseAi::compare(DB::table('ai_jobs')->find($id), DB::table('cases')->find($cid));
        $this->assertSame(1, $cmp['missing']);
        $this->assertContains('missing', $cmp['reasons']);
    }

    public function test_old_job_cannot_be_used_after_text_changes(): void // T21
    {
        $cid = $this->register(self::ADMIN);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->assertSame('done', $this->jobStatus($id));
        $before = $this->cols($cid);
        $this->post("/cases/$cid", $this->form(self::RAW."\n■尚可\n・Docker の利用経験\n"))->assertRedirect();
        $this->assertSame('dropped', $this->jobStatus($id));
        $after = $this->cols($cid);
        $this->post("/ai-jobs/$id/apply-case")->assertStatus(400);
        $this->assertSame($after, $this->cols($cid));
        $this->assertNotSame([], $before);
    }

    public function test_stale_result_is_dropped(): void // T24
    {
        $cid = $this->register(self::ADMIN);
        $job = $this->postJson('/ai/worker/next', [], $this->h)->json('job'); // 作業中
        $this->as(self::ADMIN)->post("/cases/$cid", $this->form(self::RAW."\n■尚可\n・Docker の利用経験\n"))->assertRedirect();
        $before = $this->cols($cid);
        $out = json_encode(['required' => ['Java での開発経験が3年以上', 'AWS の利用経験'], 'preferred' => ['SQL の基本的な知識']], JSON_UNESCAPED_UNICODE);
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => $out, 'engine' => 'test'], $this->h)->assertOk();
        $this->assertSame('dropped', $this->jobStatus($job['id']));
        $this->assertDatabaseHas('audit_log', ['action' => 'ai-case-stale']);
        $this->assertSame($before, $this->cols($cid));
    }

    public function test_other_roles_cannot_choose(): void // T23（403 のところ）
    {
        $cid = $this->register(self::SES1);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->assertSame('done', $this->jobStatus($id));
        $this->as(self::EG1)->post("/ai-jobs/$id/apply-case")->assertForbidden();
        $this->as(self::SES2)->post("/ai-jobs/$id/apply-case")->assertForbidden();
        $this->as(self::SES2)->post("/ai-jobs/$id/drop")->assertForbidden();
        $this->as(self::EG1)->post("/ai-jobs/$id/drop")->assertForbidden();
        $this->assertSame('done', $this->jobStatus($id));
    }

    // ---------- レビューの指摘（H1・M1・M2・L1・L4） ----------

    public function test_h1_rule_input_uses_parse_lines_and_empty_ai_is_not_auto(): void
    {
        // sections では読めず、今の読み方（R9）で読める本文：rule に戻した行が入る
        $cid = $this->register(self::ADMIN, "案件名：テスト用の在庫管理画面開発\n必須経験：Java での開発経験\n");
        $in = json_decode($this->jobs($cid)->value('input'), true);
        $this->assertNotEmpty($in['rule']['required']);
        $this->assertStringContainsString('Java', implode('、', $in['rule']['required']));

        // 規則でも読めない本文：AI も空なら 0＝0 だが、自動では消さない
        $cid2 = $this->register(self::ADMIN, "案件名：テスト用の在庫管理画面開発\n概要：PHP と Laravel で作る画面\n");
        $before = $this->cols($cid2);
        $id = $this->answer([], []);
        $cmp = CaseAi::compare(DB::table('ai_jobs')->find($id), DB::table('cases')->find($cid2));
        $this->assertContains('empty', $cmp['reasons']);
        $this->assertFalse($cmp['auto']);
        $this->assertSame($before, $this->cols($cid2));
        $this->assertDatabaseMissing('audit_log', ['action' => 'ai-case-auto']);
    }

    public function test_m1_text_change_queues_new_job_even_while_running(): void
    {
        $cid = $this->register(self::ADMIN);
        $running = $this->postJson('/ai/worker/next', [], $this->h)->json('job')['id'];
        $this->assertSame('running', $this->jobStatus($running));
        $this->as(self::ADMIN)->post("/cases/$cid", $this->form(self::RAW."\n■尚可\n・Docker の利用経験\n"))->assertRedirect();
        $this->assertSame(1, $this->jobs($cid)->where('status', 'queued')->count(), '作業中があっても、新しい本文の仕事が入る');
        $this->assertSame('running', $this->jobStatus($running));
        // 古い仕事が届くと、本文の違いで捨てる
        $out = json_encode(['required' => ['Java での開発経験が3年以上'], 'preferred' => []], JSON_UNESCAPED_UNICODE);
        $this->postJson("/ai/worker/$running/result", ['output' => $out, 'engine' => 'test'], $this->h)->assertOk();
        $this->assertSame('dropped', $this->jobStatus($running));
    }

    public function test_m2_blocked_text_still_drops_old_jobs_and_screen_hides_stale_table(): void
    {
        $cid = $this->register(self::ADMIN);
        $id = $this->answer(['Java での開発経験が3年以上', 'SQL の基本的な知識', 'AWS の利用経験'], ['AWS の利用経験']);
        $this->assertSame('done', $this->jobStatus($id));
        $this->as(self::ADMIN)->post("/cases/$cid", $this->form('', ['required_skills' => 'Java']))->assertRedirect();
        $this->assertSame('dropped', $this->jobStatus($id), '本文が空でも、前の done は捨てる');
        $this->assertSame(0, $this->jobs($cid)->where('status', 'queued')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'ai-case-stale']);

        // 本文が変わったあとに開いた画面：done のままでも、表と使うボタンは出さない
        $cid2 = $this->register(self::ADMIN);
        $id2 = $this->answer(['Java での開発経験が3年以上', 'SQL の基本的な知識', 'AWS の利用経験'], ['AWS の利用経験']);
        DB::table('cases')->where('id', $cid2)->update(['raw_text' => self::RAW."\n■尚可\n・Docker の利用経験\n"]);
        $this->assertSame('done', $this->jobStatus($id2));
        $this->as(self::ADMIN)->get("/cases/$cid2")->assertOk()->assertSee('本文が変わりました。もう一度頼んでください。')->assertSee('もう一度頼む')
            ->assertDontSee('AI の結果を使う')->assertDontSee('AI だけ');
    }

    public function test_l1_queue_drop_leaves_audit_and_l4_double_press_is_400(): void
    {
        $cid = $this->register(self::ADMIN);
        $first = (int) $this->jobs($cid)->value('id');
        $this->as(self::ADMIN)->post("/cases/$cid", $this->form(self::RAW."\n■尚可\n・Docker の利用経験\n"))->assertRedirect();
        $this->assertSame('dropped', $this->jobStatus($first));
        $this->assertTrue(DB::table('audit_log')->where('action', 'ai-case-stale')->where('detail', 'like', "#$first %")->exists());

        // 二重押し：applied・dropped になったあとの「使う」「今のまま」は 400
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->assertSame('done', $this->jobStatus($id));
        $this->post("/ai-jobs/$id/apply-case")->assertRedirect();
        $this->assertSame('applied', $this->jobStatus($id));
        $this->post("/ai-jobs/$id/apply-case")->assertStatus(400);
        $this->post("/ai-jobs/$id/drop")->assertStatus(400);
        $this->assertSame('applied', $this->jobStatus($id));
    }

    // ---------- 段階4：画面 ----------

    public function test_screen_queued_and_button_states(): void // T14 の前の状態・G1
    {
        $cid = $this->register(self::SES1);
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('id="ai"', false)->assertSee('手元の AI が必須・尚可を読み直しています')->assertSee('待たずに案内してもかまいません');
        $this->jobs($cid)->delete();
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('AI に読み直してもらう')->assertSee('name="_token"', false);
        config(['services.park_ai.token' => '']);
        $this->as(self::SES1)->get("/cases/$cid")->assertOk()->assertSee('AI はまだつながっていません')->assertDontSee('AI に読み直してもらう');
    }

    public function test_screen_failed_shows_rule_result_and_retry(): void // T14
    {
        $cid = $this->register(self::ADMIN);
        $before = $this->cols($cid);
        $this->answer(json_encode(['items' => []]));
        $this->assertSame($before, $this->cols($cid));
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertSee('規則の結果のまま使えます')->assertSee('もう一度頼む');
    }

    public function test_screen_done_shows_table_and_buttons_to_editors_only(): void // T17・T23
    {
        $cid = $this->register(self::SES1);
        $id = $this->answer(['Java での開発経験が3年以上', 'SQL の基本的な知識', 'AWS の利用経験'], ['AWS の利用経験']);
        $this->assertSame('done', $this->jobStatus($id));
        foreach ([self::ADMIN, self::SES1, $this->staff()] as $email) {
            $this->as($email)->get("/cases/$cid")->assertOk()
                ->assertSee('AI の読み直しの結果を確かめてください')->assertSee('規則と行数が違います')
                ->assertSee('AI だけ')->assertSee('AI の結果を使う')->assertSee('今のままにする')
                ->assertSee("/ai-jobs/$id/apply-case", false)->assertSee('name="_token"', false);
        }

        // 案内を受けた EG・ほかの SES には、欄も AI の文も出さない
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $this->engId(self::EG1), 'score' => 50, 'reasons' => '', 'missing' => '', 'sent_by' => DB::table('users')->where('email', self::ADMIN)->value('id'), 'sent_at' => now_str()]);
        $this->as(self::EG1)->get("/cases/$cid")->assertOk()->assertDontSee('id="ai"', false)->assertDontSee('AI の読み直し')->assertDontSee('AI だけ')->assertDontSee('AI の結果を使う');
        $other = $this->as(self::SES2)->get("/cases/$cid");
        if ($other->status() === 200) $other->assertDontSee('id="ai"', false)->assertDontSee('AI の読み直し')->assertDontSee('AI だけ');
        else $other->assertForbidden();
        $this->as(self::EG1)->post("/ai-jobs/$id/apply-case")->assertForbidden();
        $this->as(self::SES2)->post("/ai-jobs/$id/apply-case")->assertForbidden();
    }

    public function test_screen_edited_case_warns_before_using(): void // T18 の画面
    {
        $cid = $this->register(self::ADMIN);
        $this->as(self::ADMIN)->post("/cases/$cid", $this->form(self::RAW, ['required_skills' => 'Java']))->assertRedirect();
        $this->answer(...$this->sameCountAnswer());
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertSee('登録の前か後に人が直したか、本文を変えた案件です')->assertSee('直した必須・尚可・業務も置き換わります');
    }

    public function test_screen_same_as_rule(): void // T19
    {
        $cid = $this->register(self::ADMIN);
        DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $this->engId(self::EG1), 'score' => 50, 'reasons' => '', 'missing' => '',
            'sent_by' => DB::table('users')->where('email', self::ADMIN)->value('id'), 'sent_at' => now_str()]);
        $this->answer(['Java での開発経験が3年以上', 'SQL の基本的な知識'], ['AWS の利用経験']);
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertSee('AI も同じ読み方でした')->assertSee('もう一度頼む');
        $this->assertSame(0, $this->aiNotices($cid)->count());
    }

    public function test_screen_missing_wording_mark(): void // T20
    {
        $cid = $this->register(self::ADMIN);
        $this->answer(['Java での開発経験が3年以上', 'Kubernetes クラスタの運用設計の実績'], ['AWS の利用経験']);
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertSee('本文に無い言い方')->assertSee('Kubernetes クラスタの運用設計の実績');
    }

    public function test_screen_applied_texts(): void // G1（自動・人が選んだ）
    {
        $cid = $this->register(self::ADMIN);
        $this->answer(...$this->sameCountAnswer());
        $this->as(self::ADMIN)->get("/cases/$cid")->assertOk()->assertSee('規則と同じ数だったので AI の結果にしました');

        $cid2 = $this->register(self::ADMIN);
        $id = $this->answer(['Java での開発経験が3年以上'], []);
        $this->as(self::ADMIN)->post("/ai-jobs/$id/apply-case")->assertRedirect("/cases/$cid2#ai");
        $this->as(self::ADMIN)->get("/cases/$cid2")->assertOk()->assertSee('AI の結果を使いました')->assertDontSee('規則と同じ数だったので');
    }

    public function test_import_and_store_messages(): void // G4
    {
        $this->as(self::ADMIN)->post('/cases/import', ['raw_text' => self::RAW])->assertOk()->assertSee('登録すると、手元の AI が必須・尚可を読み直します');
        $this->as(self::ADMIN)->followingRedirects()->post('/cases', $this->form(self::RAW))->assertSee('手元の AI が必須・尚可を読み直しています');
        config(['services.park_ai.token' => '']);
        $this->as(self::ADMIN)->post('/cases/import', ['raw_text' => self::RAW])->assertOk()->assertDontSee('登録すると、手元の AI が');
    }

    public function test_admin_ai_list_links_to_case(): void // T25（一覧）
    {
        $cid = $this->register(self::ADMIN);
        $this->as(self::ADMIN)->get('/admin/ai')->assertOk()->assertSee('案件の必須・尚可の読み直し')->assertSee("/cases/$cid#ai", false);
    }

    public function test_sample_purge_removes_case_jobs(): void // T25（見本の削除）・G6
    {
        $cid = $this->register(self::SES1); // 見本の案件
        $this->assertSame(1, $this->jobs($cid)->count());
        $aid = W::createUser('real-admin60@example.com', '実在 管理', 'admin', 'abcd1234');
        $this->completeProfile($aid, ['company_name' => '実在運営']);
        \Illuminate\Support\Facades\Artisan::call('park:purge-samples', ['--yes' => true]);
        $this->assertSame(0, DB::table('ai_jobs')->where('ref_type', 'case')->where('ref_id', $cid)->count());
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) if (filemtime($f) >= time() - 60) @unlink($f); // このテストで作ったバックアップ
    }

    public function test_version_shown(): void // T26
    {
        $this->get('/login')->assertOk()->assertSee('v0.10.1');
    }
}
