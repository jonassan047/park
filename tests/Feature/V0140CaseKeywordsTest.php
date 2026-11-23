<?php

namespace Tests\Feature;

use App\Services\CaseAi;
use App\Services\CaseMail;
use App\Services\CaseSearch;
use App\Services\Matcher;
use App\Services\Workflow as W;
use App\Support\CaseKeywords;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.14.0：案件の必須・尚可を、技術マスタの名前（キーワード）に分け、合わない文は「エンジニアに確認」へ（PLAN.md の K1〜K15）。
// 本文・会社名はすべて架空。メールは @park.example
class V0140CaseKeywordsTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';

    private const RAW = "【案件名】テスト案件\n【必須】\n・アジャイルスクラムマスター\n・技術的知見を有している人材\n【尚可】\n・AI駆動開発経験\n・社員相当としての活躍";

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::sync(); // 辞書（AI を含む）を入れた状態で試す
        SkillCatalog::forget();
    }

    private function sesId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('ses_id');
    }

    private function staff(): string
    {
        $id = W::createUser('staff140@example.com', '担当 十四郎', 'ses', 'park1234', ['ses_id' => $this->sesId(self::SES1)]);
        $this->completeProfile($id);
        return 'staff140@example.com';
    }

    // 案件を登録する（管理者は受注 SES を選ぶ）
    private function register(string $email, array $over = []): object
    {
        $form = $over + ['title' => 'K テスト案件', 'status' => 'open', 'raw_text' => self::RAW, 'source_ses_id' => $this->sesId(self::SES1), 'price' => 60,
            'required_skills' => 'アジャイル、スクラムマスター、PM機能を対応できる人材', 'confirm_items' => '技術的知見を有している人材'];
        $this->as($email)->post('/cases', $form)->assertRedirect();
        return DB::table('cases')->orderByDesc('id')->first();
    }

    // ---------- 拾い出し（K2・K3・K13） ----------
    public function test_line_picks_master_words(): void
    {
        $this->assertSame(['アジャイル', 'スクラムマスター'], CaseKeywords::line('アジャイルスクラムマスター')['skills']);
        $this->assertContains('AI', CaseKeywords::line('AI駆動開発経験')['skills']);
        $this->assertContains('PM', CaseKeywords::line('PM機能を対応できる人材')['skills']);
        $this->assertContains('Java', CaseKeywords::line('Java経験3年以上')['skills']);
        // 1行に複数の語
        $this->assertEqualsCanonicalizing(['Java', 'Spring Boot', 'SQL'], array_intersect(['Java', 'Spring Boot', 'SQL'], CaseKeywords::line('Java、Spring Boot、SQL の経験')['skills']));
    }

    public function test_line_unmatched_goes_to_confirm(): void
    {
        foreach (['技術的知見を有している人材', '社員相当としての活躍'] as $s) {
            $r = CaseKeywords::line($s);
            $this->assertSame([], $r['skills'], $s);
            $this->assertSame([], $r['exp'], $s);
        }
        $k = CaseKeywords::split(['技術的知見を有している人材', 'Java'], ['社員相当としての活躍']);
        $this->assertSame(['技術的知見を有している人材', '社員相当としての活躍'], $k['confirm']);
        $this->assertSame(['Java'], $k['required_skills']);
    }

    public function test_line_boundaries(): void
    {
        $s = CaseKeywords::line('生成AIの活用経験')['skills'];
        $this->assertContains('生成AI', $s);
        $this->assertNotContains('AI', $s);
        $this->assertNotContains('AI', CaseKeywords::line('OpenAI API を使う')['skills']);
        $this->assertSame([], CaseKeywords::line('IT業界の経験')['skills']);
        $this->assertContains('運用・保守', CaseKeywords::line('運用保守の経験')['skills']);
        $this->assertSame(['C'], CaseKeywords::line('C')['skills']); // 行そのものが語
        // 文を、マスタの新語にしない。空白の無い英数字1語だけ新しい語になる
        $this->assertNull(CaseKeywords::line('Good communication skills')['new']);
        $this->assertSame('Hotwire', CaseKeywords::line('Hotwire')['new']);
    }

    public function test_items_keep_master_names_with_separators(): void // K13
    {
        $this->assertSame(['教育・育成', 'CI/CD', 'Java', 'Spring'], skill_items('教育・育成、CI/CD、Java/Spring'));
    }

    // ---------- 取り込み ----------
    public function test_import_splits_keywords_and_confirm_without_writing(): void
    {
        $cases = DB::table('cases')->count();
        $terms = DB::table('skill_terms')->count();
        $r = $this->as(self::ADMIN)->post('/cases/import', ['raw_text' => self::RAW])->assertOk();
        $r->assertSee('アジャイル、スクラムマスター', false);
        $html = $r->getContent();
        preg_match('#<textarea name="preferred_skills"[^>]*>(.*?)</textarea>#s', $html, $m);
        $this->assertStringContainsString('AI', $m[1] ?? '');
        preg_match('#<textarea name="confirm_items"[^>]*>(.*?)</textarea>#s', $html, $m);
        $this->assertStringContainsString('技術的知見を有している人材', $m[1] ?? '');
        $this->assertStringContainsString('社員相当としての活躍', $m[1] ?? '');
        $r->assertSee('エンジニアに確認');
        $r->assertSee('元の必須・尚可の文');
        $this->assertSame($cases, DB::table('cases')->count());
        $this->assertSame($terms, DB::table('skill_terms')->count());
    }

    // ---------- 保存（K1・K4〜K8） ----------
    public function test_save_for_each_player(): void
    {
        foreach ([self::ADMIN, self::SES1, $this->staff()] as $email) {
            $c = $this->register($email);
            $this->assertSame(self::RAW, $c->raw_text, "{$email}：全文がそのまま入る");
            $this->assertSame('アジャイル、スクラムマスター、PM', $c->required_skills, $email);
            $this->assertStringContainsString('技術的知見を有している人材', (string) $c->confirm_items);
            $this->assertStringContainsString('アジャイルスクラムマスター', (string) $c->required_lines);
            $this->assertStringContainsString('AI駆動開発経験', (string) $c->preferred_lines);
        }
        $this->assertSame(0, DB::table('skill_terms')->whereIn('name', ['PM機能を対応できる人材', '技術的知見を有している人材'])->count());
        $this->assertSame(0, DB::table('skill_terms')->where('source', 'auto')->count());
    }

    public function test_save_adds_only_one_word_new_terms(): void // K6
    {
        $c = $this->register(self::ADMIN, ['required_skills' => 'PHP、Hotwire、コミュニケーション能力', 'confirm_items' => '']);
        $this->assertSame('PHP、Hotwire', $c->required_skills);
        $this->assertStringContainsString('コミュニケーション能力', (string) $c->confirm_items);
        $this->assertSame('auto', DB::table('skill_terms')->where('name', 'Hotwire')->value('source'));
        $this->assertSame(0, DB::table('skill_terms')->where('name', 'コミュニケーション能力')->count());
        $n = DB::table('app_notifications')->where('title', 'like', '案件から新しい技術%')->first();
        $this->assertNotNull($n);
        $this->assertStringContainsString('Hotwire', $n->body);
        $this->assertStringNotContainsString('コミュニケーション能力', $n->body);
    }

    public function test_update_keeps_lines_unless_raw_changes(): void
    {
        $c = $this->register(self::ADMIN);
        DB::table('cases')->where('id', $c->id)->update(['required_lines' => 'AI が入れた行']);
        $form = ['title' => $c->title, 'status' => 'open', 'raw_text' => self::RAW, 'required_skills' => $c->required_skills, 'confirm_items' => $c->confirm_items];
        $this->as(self::ADMIN)->post("/cases/{$c->id}", $form)->assertRedirect();
        $this->assertSame('AI が入れた行', DB::table('cases')->where('id', $c->id)->value('required_lines'), '本文を変えない保存では、元の行を変えない');
        $this->as(self::ADMIN)->post("/cases/{$c->id}", ['raw_text' => "【必須】\n・Java の経験"] + $form)->assertRedirect();
        $this->assertSame('Java の経験', DB::table('cases')->where('id', $c->id)->value('required_lines'), '本文を変えると、読み直す');
    }

    // ---------- AI（K5・K12） ----------
    public function test_ai_apply_replaces_confirm_and_lines(): void
    {
        $c = $this->register(self::ADMIN);
        $row = fn ($t) => ['text' => $t, 'mark' => 'ok', 'from' => null];
        CaseAi::apply($c, ['req' => [], 'pref' => [$row('AI駆動開発経験'), $row('社員相当としての活躍')]], true); // 人が「使う」を押したとき
        $n = DB::table('cases')->find($c->id);
        $this->assertStringContainsString('AI', (string) $n->preferred_skills . (string) $n->required_skills);
        $this->assertSame('社員相当としての活躍', $n->confirm_items);
        $this->assertSame("AI駆動開発経験\n社員相当としての活躍", $n->preferred_lines);
        $this->assertNull($n->required_lines);
    }

    public function test_ai_table_compares_lines(): void
    {
        config(['services.park_ai.token' => 'test-token']);
        $raw = "案件名：テスト用の在庫管理画面開発\n\n■必須\n・Java での開発経験が3年以上\n・SQL の基本的な知識\n\n■尚可\n・AWS の利用経験\n";
        $c = $this->register(self::ADMIN, ['raw_text' => $raw, 'required_skills' => 'Java、SQL', 'preferred_skills' => 'AWS', 'confirm_items' => '']);
        $h = ['X-Park-Ai-Token' => 'test-token'];
        $job = $this->postJson('/ai/worker/next', [], $h)->json('job');
        $out = json_encode(['required' => ['Java での開発経験が3年以上', 'SQL の基本的な知識', 'AWS の利用経験'], 'preferred' => ['AWS の利用経験']], JSON_UNESCAPED_UNICODE);
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => $out, 'engine' => 'test'], $h)->assertOk();
        $r = $this->as(self::ADMIN)->get("/cases/{$c->id}")->assertOk();
        $r->assertSee('AI の読み直しの結果を確かめてください');
        $r->assertSee('エンジニアに確認：');
        $r->assertDontSee('今だけ'); // 「今」は元の行なので、AI の行と同じ文は「今だけ」にならない
    }

    public function test_ai_auto_apply_keeps_human_confirm_and_lines(): void // H3
    {
        config(['services.park_ai.token' => 'test-token']);
        $raw = "案件名：テスト用の在庫管理画面開発\n\n■必須\n・Java での開発経験が3年以上\n・SQL の基本的な知識\n\n■尚可\n・AWS の利用経験\n";
        $c = $this->register(self::ADMIN, ['raw_text' => $raw, 'required_skills' => 'Java、SQL', 'preferred_skills' => 'AWS', 'confirm_items' => '人が書いた確認の文']);
        $lines = DB::table('cases')->where('id', $c->id)->value('required_lines');
        $h = ['X-Park-Ai-Token' => 'test-token'];
        $job = $this->postJson('/ai/worker/next', [], $h)->json('job');
        $out = json_encode(['required' => ['Java での開発経験が3年以上', 'AWS の利用経験'], 'preferred' => ['SQL の基本的な知識']], JSON_UNESCAPED_UNICODE);
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => $out, 'engine' => 'test'], $h)->assertOk();
        $this->assertSame('applied', DB::table('ai_jobs')->where('id', $job['id'])->value('status'));
        $n = DB::table('cases')->find($c->id);
        $this->assertSame('人が書いた確認の文', $n->confirm_items, '自動では、人が直した「エンジニアに確認」を置き換えない');
        $this->assertSame($lines, $n->required_lines);
        $this->assertStringContainsString('AWS', $n->required_skills); // 名前の欄は AI の結果になる
    }

    public function test_unreviewed_auto_term_is_not_used(): void // H2
    {
        DB::table('skill_terms')->insert(['name' => '技術的知見を有している人材', 'category' => 'other', 'source' => 'auto', 'reviewed' => false, 'created_at' => now_str(), 'updated_at' => now_str()]);
        SkillCatalog::forget();
        $this->assertSame([], CaseKeywords::line('技術的知見を有している人材')['skills']);
        $this->assertSame(['技術的知見を有している人材'], CaseKeywords::split(['技術的知見を有している人材'], [])['confirm']);
    }

    public function test_common_words_are_not_picked(): void // H4
    {
        foreach (['勤怠が良好な方', 'セキュリティ意識の高い方', '自宅に通信環境がある方', '社会保険完備'] as $s) {
            $this->assertSame([], CaseKeywords::line($s)['skills'], $s);
        }
    }

    public function test_exp_only_item_goes_to_confirm_on_save(): void // M1
    {
        $c = $this->register(self::ADMIN, ['required_skills' => 'PHP、英語での会話ができる方', 'confirm_items' => '']);
        $this->assertSame('PHP', $c->required_skills);
        $this->assertStringContainsString('英語での会話ができる方', (string) $c->confirm_items);
    }

    public function test_items_split_only_when_all_parts_are_master(): void // M2
    {
        $this->assertSame(['Java', 'Spring'], skill_items('Java/Spring'));
        $this->assertSame(['要件定義・設計の経験が3年以上'], skill_items('要件定義・設計の経験が3年以上'));
    }

    // ---------- 画面と権限（K9・K10） ----------
    public function test_eg_sees_confirm_but_not_raw_or_lines(): void
    {
        $c = $this->register(self::ADMIN, ['raw_text' => self::RAW."\n架空の株式会社ゼロの案件です"]);
        DB::table('case_offers')->insert(['case_id' => $c->id, 'engineer_id' => $this->engId(self::EG1), 'score' => 50, 'sent_at' => now_str()]);
        $r = $this->as(self::EG1)->get("/cases/{$c->id}")->assertOk();
        $r->assertSee('ご確認ください'); // EG の画面の見出し（管理者・SES・担当者は「エンジニアに確認」）
        $r->assertSee('技術的知見を有している人材');
        $r->assertDontSee('架空の株式会社ゼロ');
        $r->assertDontSee('元の必須・尚可の文');
        $r->assertDontSee('アジャイルスクラムマスター');
    }

    public function test_original_lines_only_for_editors(): void
    {
        $c = $this->register(self::ADMIN); // 受注 SES は SES1 の会社
        $this->as(self::ADMIN)->get("/cases/{$c->id}")->assertSee('元の必須・尚可の文');
        $this->as(self::SES1)->get("/cases/{$c->id}")->assertSee('元の必須・尚可の文');
        $r = $this->as(self::SES2)->get("/cases/{$c->id}")->assertOk(); // ほかの会社の SES は、見られても直せない
        $r->assertSee('エンジニアに確認');
        $r->assertDontSee('元の必須・尚可の文');
    }

    public function test_mail_has_confirm_line_only_when_present(): void
    {
        $e = (object) ['nickname' => 'テスト', 'name' => 'テスト 太郎', 'initials' => 'T.T'];
        $c = $this->register(self::ADMIN);
        // 案件の画面の案内（CaseMail）は今の一行のまま。検索のメールは v0.15.0 から「確認事項の節」（チェックした項目。CaseSearch::confirmBlock）
        $body = CaseMail::render(CaseMail::defaultBody(), $c, $e, ['reasons' => []], true, '送り主');
        $this->assertStringContainsString('■ご確認ください：技術的知見を有している人材', $body);
        $this->assertStringNotContainsString('{確認事項}', $body);
        $this->assertStringContainsString('{確認事項の節}', CaseSearch::defaultBody());
        $this->assertStringNotContainsString('{確認事項}', CaseSearch::defaultBody());
        DB::table('cases')->where('id', $c->id)->update(['confirm_items' => null]);
        $c2 = DB::table('cases')->find($c->id);
        foreach ([CaseMail::defaultBody(), CaseSearch::defaultBody()] as $tpl) {
            $this->assertStringNotContainsString('ご確認ください', CaseMail::render($tpl, $c2, $e, ['reasons' => []], true, '送り主'));
        }
    }

    // ---------- 採点（K11） ----------
    public function test_confirm_items_do_not_change_score(): void
    {
        $admin = DB::table('users')->where('email', self::ADMIN)->first();
        $a = W::saveCase(['title' => '点数 A', 'required_skills' => 'PHP', 'status' => 'open', 'price' => 60], $admin);
        $b = W::saveCase(['title' => '点数 B', 'required_skills' => 'PHP', 'status' => 'open', 'price' => 60, 'confirm_items' => "確認したい文\n別の文"], $admin);
        $e = DB::table('engineers')->find($this->engId(self::EG1));
        $sa = Matcher::score(DB::table('cases')->find($a), $e);
        $sb = Matcher::score(DB::table('cases')->find($b), $e);
        $this->assertSame($sa['score'], $sb['score']);
    }

    // ---------- 既存の案件（K14） ----------
    public function test_command_dry_run_then_apply(): void
    {
        $c = $this->register(self::ADMIN, ['required_skills' => 'Java', 'confirm_items' => '']);
        DB::table('cases')->where('id', $c->id)->update(['required_skills' => 'Java経験3年以上、技術的知見を有している人材', 'required_lines' => null, 'confirm_items' => null]);
        $before = (array) DB::table('cases')->find($c->id);
        Artisan::call('park:case-keywords');
        $this->assertSame($before, (array) DB::table('cases')->find($c->id), '--apply が無いときは書き換えない');
        $this->assertStringContainsString('→', Artisan::output());
        Artisan::call('park:case-keywords', ['--apply' => true]);
        $n = DB::table('cases')->find($c->id);
        $this->assertSame('Java', $n->required_skills);
        $this->assertSame('技術的知見を有している人材', $n->confirm_items);
        $this->assertSame("Java経験3年以上\n技術的知見を有している人材", $n->required_lines);
        $this->assertDatabaseHas('audit_log', ['action' => 'case-keywords']);
        // 行がある案件は、もう対象にならない
        Artisan::call('park:case-keywords');
        $this->assertStringContainsString('対象 0 件', Artisan::output());
    }

    public function test_migration_adds_columns_only(): void
    {
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasColumns('cases', ['required_lines', 'preferred_lines', 'confirm_items']));
        $c = $this->register(self::ADMIN, ['required_skills' => 'Java', 'raw_text' => null]);
        $this->assertSame('Java', $c->required_skills);
    }

    // ---------- 追加（tester）：3経路で同じ結果・EG のメールに元の文が出ない ----------
    public function test_three_paths_give_same_result(): void
    {
        $lines = ['アジャイルスクラムマスター', 'Java経験3年以上', '技術的知見を有している人材'];
        $expectSkills = ['アジャイル', 'スクラムマスター', 'Java'];
        $expectConfirm = ['技術的知見を有している人材'];
        $clean = fn ($s) => array_values(array_filter(array_map('trim', preg_split('/[、\n]/u', html_entity_decode((string) $s)))));
        // 1) 取り込み（画面の欄に出る）
        $raw = "【案件名】三経路\n【必須】\n・".implode("\n・", $lines);
        $html = $this->as(self::ADMIN)->post('/cases/import', ['raw_text' => $raw])->assertOk()->getContent();
        preg_match('#<textarea name="required_skills"[^>]*>(.*?)</textarea>#s', $html, $m1);
        preg_match('#<textarea name="confirm_items"[^>]*>(.*?)</textarea>#s', $html, $m2);
        $this->assertSame($expectSkills, $clean($m1[1] ?? ''), '取り込み');
        $this->assertSame($expectConfirm, $clean($m2[1] ?? ''), '取り込み（確認）');
        // 2) 保存（画面を通らない saveCase に文をそのまま渡す）
        $admin = DB::table('users')->where('email', self::ADMIN)->first();
        $id = W::saveCase(['title' => '三経路 保存', 'status' => 'open', 'price' => 60, 'required_skills' => implode('、', $lines)], $admin);
        $c = DB::table('cases')->find($id);
        $this->assertSame($expectSkills, skill_items($c->required_skills), '保存');
        $this->assertSame($expectConfirm, $clean($c->confirm_items), '保存（確認）');
        // 3) AI の差し替え
        $row = fn ($t) => ['text' => $t, 'mark' => 'ok', 'from' => null];
        CaseAi::apply($c, ['req' => array_map($row, $lines), 'pref' => []], true);
        $n = DB::table('cases')->find($id);
        $this->assertSame($expectSkills, skill_items($n->required_skills), 'AI');
        $this->assertSame($expectConfirm, $clean($n->confirm_items), 'AI（確認）');
    }

    public function test_mail_to_eg_has_no_raw_or_original_lines(): void
    {
        $e = (object) ['nickname' => 'テスト', 'name' => 'テスト 太郎', 'initials' => 'T.T'];
        $c = $this->register(self::ADMIN, ['raw_text' => self::RAW."\n架空の株式会社ゼロの案件です"]);
        foreach ([CaseMail::defaultBody(), CaseSearch::defaultBody()] as $tpl) {
            $body = CaseMail::render($tpl, $c, $e, ['reasons' => []], true, '送り主');
            $this->assertStringNotContainsString('架空の株式会社ゼロ', $body);
            $this->assertStringNotContainsString('アジャイルスクラムマスター', $body);
            $this->assertStringNotContainsString('AI駆動開発経験', $body);
        }
    }
}
