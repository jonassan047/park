<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseParser;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 見本データを入れて、3つの役割で主な画面と操作を一通りたどる
class ParkTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // 2要素認証まで済ませてログインした状態にする
    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function pages(array $paths): void
    {
        foreach ($paths as $p) $this->get($p)->assertOk();
    }

    public function test_public_pages_and_apply(): void
    {
        $this->pages(['/', '/login', '/apply', '/forgot']);
        $this->get('/admin')->assertRedirect(url('/login'));
        $this->post('/apply', ['name' => 'テスト SES', 'contact_name' => '試験 一郎', 'email' => 'new-ses@park.example', 'agree' => '1'])->assertOk();
        $this->assertDatabaseHas('ses_companies', ['name' => 'テスト SES', 'status' => 'pending']);
        $this->post('/login', ['email' => 'admin@park.example', 'password' => 'park1234'])->assertRedirect(url('/two-factor/challenge'));
    }

    public function test_admin_pages_and_actions(): void
    {
        $this->as('admin@park.example');
        $this->pages(['/admin', '/admin/ses', '/admin/ses/1', '/admin/ses/4', '/admin/ses/new', '/admin/engineers', '/admin/engineers?q=java', '/admin/cases', '/admin/cases?status=all',
            '/admin/applications', '/admin/contracts', '/admin/invoices', '/admin/messages', '/admin/mail', '/admin/mail/1', '/admin/reports', '/admin/settings', '/admin/audit',
            '/cases/1', '/cases/1?all=1', '/cases/2', '/cases/new', '/cases/1/edit', '/engineers/1', '/engineers/1/sheet', '/engineers/1/sheet?case=1&anon=1', '/engineers/1/skills',
            '/engineers/1/projects/new', '/engineers/1/projects/1/edit', '/engineers/2/messages', '/applications/1', '/applications/2', '/interviews/1', '/contracts/1',
            '/notifications', '/account', '/engineers/new']);

        $this->post('/admin/ses/4/approve')->assertRedirect();
        $this->assertDatabaseHas('ses_companies', ['id' => 4, 'status' => 'active']);

        $this->post('/cases/import', ['raw_text' => "【案件名】在庫管理システム開発\n【単価】〜65万円\n【必須】Java、SQL\n【尚可】AWS\n【場所】リモート可\n【開始】12月〜\n【面談】1回"])
            ->assertOk()->assertSee('在庫管理システム開発')->assertSee('65');
        $this->post('/cases', ['title' => '在庫管理システム開発', 'price' => '65', 'required_skills' => 'Java、SQL', 'work_style' => 'any', 'status' => 'open'])->assertRedirect();
        $caseId = DB::table('cases')->where('title', '在庫管理システム開発')->value('id');
        $this->get("/cases/$caseId")->assertSee('エンジニアに案内する');
        $this->get("/cases/$caseId/mail")->assertSee('山田 太郎');
        $this->post("/cases/$caseId/distribute", ['engineer_id' => [4], 'message' => 'どうぞ'])->assertRedirect();
        $this->assertDatabaseHas('case_offers', ['case_id' => $caseId, 'engineer_id' => 4]);

        $this->post('/applications/1/status', ['status' => 'rejected', 'reason' => '他の候補者で決定'])->assertRedirect();
        $this->assertDatabaseHas('applications', ['id' => 1, 'status' => 'rejected']);

        $this->post('/admin/invoices/build', ['period' => date('Y-m')])->assertRedirect();
        $this->get('/invoices/1')->assertOk()->assertSee('ご請求書');
        $this->post('/admin/invoices/1/issue')->assertRedirect();
        $this->post('/admin/mail/broadcast', ['target' => 'ses', 'subject' => 'お知らせ', 'body' => 'テスト'])->assertRedirect();
        $this->post('/contracts/1/extend', ['months' => 3])->assertRedirect();
        $this->assertSame(1, (int) DB::table('contracts')->where('id', 1)->value('extensions'));
        $this->post('/admin/settings', ['fee_initial_rate' => '8'])->assertRedirect();
        $this->assertSame('8', setting('fee_initial_rate'));
    }

    public function test_full_selection_flow(): void
    {
        $this->as('admin@park.example');
        // 応募1（山田・書類通過→面談設定済み）：面談の結果 → 成約
        $ivId = DB::table('interviews')->where('application_id', 1)->value('id');
        $this->post("/interviews/$ivId/result", ['result' => 'pass', 'feedback' => '好印象'])->assertRedirect();
        $this->post('/applications/1/contract', ['price' => 68, 'start_date' => date('Y-m-d')])->assertRedirect();
        $this->assertDatabaseHas('applications', ['id' => 1, 'status' => 'won']);
        $this->assertDatabaseHas('cases', ['id' => 1, 'status' => 'filled']);
        $this->assertDatabaseHas('engineers', ['id' => 1, 'status' => 'working']);
    }

    public function test_ses_pages_and_permissions(): void
    {
        $this->as('ses1@park.example');
        $this->pages(['/ses', '/ses/engineers', '/ses/cases', '/ses/cases?mine=1', '/ses/applications', '/ses/contracts', '/ses/invoices', '/ses/company', '/cases/1', '/engineers/1', '/engineers/new', '/cases/new']);
        $this->get('/engineers/2')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->post('/engineers', ['name' => '新人 一郎', 'kana' => 'しんじん いちろう', 'email' => 'eg-new@park.example', 'years_exp' => '1'])->assertRedirect();
        $this->assertDatabaseHas('engineers', ['name' => '新人 一郎', 'initials' => 'I.S']);
        $this->post('/cases/2/apply', ['engineer_id' => 1, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $this->assertDatabaseHas('applications', ['case_id' => 2, 'engineer_id' => 1]);
        // 二重応募は受け付けない
        $this->post('/cases/2/apply', ['engineer_id' => 1, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1]);
        $this->assertSame(1, DB::table('applications')->where('case_id', 2)->where('engineer_id', 1)->count());
        // 他社のエンジニアで応募は出せない
        $this->post('/cases/2/apply', ['engineer_id' => 2])->assertForbidden();
    }

    public function test_engineer_pages_and_actions(): void
    {
        $this->as('eg2@park.example');
        $this->pages(['/eg', '/eg/offers', '/eg/applications', '/cases/2', '/engineers/2', '/engineers/2/sheet', '/engineers/2/messages', '/engineers/2/skills', '/engineers/2/edit']);
        $this->get('/cases/1')->assertForbidden();
        $this->get('/engineers/1')->assertForbidden();
        $this->get('/applications/1')->assertForbidden();
        $this->post('/cases/2/apply', ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $this->assertDatabaseHas('applications', ['case_id' => 2, 'engineer_id' => 2]);
        $this->post('/engineers/2/projects', ['title' => '社内ツールの開発', 'tech' => 'Vue、PHP', 'phases' => ['実装', '単体テスト'],
            'description' => '社内の申請ツールを開発した。画面とAPIの両方を担当し、運用まで行った。', 'achievements' => '申請の処理時間を半分に短縮'])->assertRedirect();
        $this->assertDatabaseHas('engineer_projects', ['title' => '社内ツールの開発', 'phases' => '実装、単体テスト']);
        $this->post('/engineers/2/skills', ['skill_name' => ['Go', ''], 'skill_cat' => ['lang', 'other'], 'skill_years' => ['1', ''], 'skill_level' => ['2', '3']])->assertRedirect();
        $this->assertSame(1, DB::table('engineer_skills')->where('engineer_id', 2)->count());
        $this->post('/engineers/2/messages', ['body' => '見てください', 'review' => '1'])->assertRedirect();
        $this->assertDatabaseHas('messages', ['engineer_id' => 2, 'read_by_admin' => 0]);
    }

    public function test_invite_and_consent(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        $id = W::createEngineer(1, ['name' => '招待 花子', 'email' => 'invite@park.example'], $admin);
        $token = DB::table('users')->where('email', 'invite@park.example')->value('token');
        $this->get("/invite/$token")->assertOk()->assertSee('個人情報の取り扱い');
        $this->post("/invite/$token", ['password' => 'abcd1234', 'password_confirmation' => 'abcd1234'])->assertSessionHasErrors('consent');
        $this->post("/invite/$token", ['password' => 'abcd1234', 'password_confirmation' => 'abcd1234', 'consent' => '1'])->assertRedirect(url('/two-factor/challenge'));
        // 初めてのログインも、確認コードを入れるまで中に入れない
        $this->get('/eg')->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastCode('invite@park.example')])->assertRedirect(url('/eg'));
        $this->assertNotNull(DB::table('engineers')->where('id', $id)->value('consent_at'));
        $this->assertGreaterThan(10, DB::table('mail_outbox')->count());
    }

    public function test_case_parser_and_matching(): void
    {
        $p = CaseParser::parse("【案件名】在庫管理\n【単価】60〜70万円\n【必須】Java、Spring Boot\n【開始】2027年1月〜\n【面談】2回\n経験5年以上");
        $this->assertSame('在庫管理', $p['title']);
        $this->assertSame(70.0, $p['price']);
        $this->assertSame('2027-01-01', $p['start_date']);
        $this->assertSame(2, $p['interviews_count']);
        $this->assertSame(5, $p['min_years']);
        $this->assertFalse(loose_match('javascript', 'java'));
        $this->assertTrue(loose_match('springboot', 'spring'));
        $this->assertSame('2027-01-31', month_end('2026-11-15', 2));
        $this->assertSame('10', num_disp('10.0'));
        $this->assertSame('6.5', num_disp(6.5));
        $this->assertSame('100', num_disp(100));
    }

    // いちばん新しい確認コード（メールの件名から）
    private function lastCode(string $email): string
    {
        $subject = DB::table('mail_outbox')->where('to_email', $email)->where('kind', '2fa')->orderByDesc('id')->value('subject');
        preg_match('/(\d{6})/', $subject, $m);
        return $m[1];
    }

    public function test_two_factor_by_email_code(): void
    {
        // パスワードだけでは中に入れない。メールに確認コードが届く
        $this->post('/login', ['email' => 'eg1@park.example', 'password' => 'park1234'])->assertRedirect(url('/two-factor/challenge'));
        $this->get('/eg')->assertRedirect(url('/two-factor/challenge'));
        $this->get('/two-factor/challenge')->assertOk()->assertSee('eg***@park.example');
        $this->get('/two-factor/setup')->assertRedirect(url('/two-factor/challenge'));
        $code = $this->lastCode('eg1@park.example');
        $wrong = $code === '000000' ? '111111' : '000000';
        $this->post('/two-factor/challenge', ['code' => $wrong])->assertRedirect(url('/two-factor/challenge'));
        $this->get('/eg')->assertRedirect(url('/two-factor/challenge'));
        // 正しいコードで入れる（全角でもよい）
        $this->post('/two-factor/challenge', ['code' => mb_convert_kana($code, 'N')])->assertRedirect(url('/eg'));
        $this->get('/eg')->assertOk();

        // 使ったコードは、次のログインでは使えない
        $this->post('/logout');
        $this->post('/login', ['email' => 'eg1@park.example', 'password' => 'park1234']);
        $this->assertNotSame($code, $new = $this->lastCode('eg1@park.example'));
        // すぐの送り直しは断る。5回まちがえると、送り直すまで正しいコードも通らない
        $this->post('/two-factor/resend')->assertSessionHas('flash.type', 'bad');
        $wrong = $new === '000000' ? '111111' : '000000';
        for ($i = 0; $i < 5; $i++) $this->post('/two-factor/challenge', ['code' => $wrong]);
        $this->post('/two-factor/challenge', ['code' => $new])->assertRedirect(url('/two-factor/challenge'));
        $this->get('/eg')->assertRedirect(url('/two-factor/challenge'));
        $this->travel(2)->minutes();
        $this->post('/two-factor/resend')->assertSessionHas('flash.type', 'ok');
        $this->post('/two-factor/challenge', ['code' => $this->lastCode('eg1@park.example')])->assertRedirect(url('/eg'));
        // 10分たったコードは使えない
        $this->post('/logout');
        $this->post('/login', ['email' => 'eg1@park.example', 'password' => 'park1234']);
        $this->travel(11)->minutes();
        $this->post('/two-factor/challenge', ['code' => $this->lastCode('eg1@park.example')])->assertRedirect(url('/two-factor/challenge'));
    }

    public function test_self_confirm_and_history(): void
    {
        // SES が書き換えると、本人の確認がやり直しになり、応募できない
        $this->as('ses1@park.example')->post('/engineers/1', ['name' => '山田 太郎', 'summary' => 'SES が書き換えた'])->assertRedirect();
        $this->assertNull(DB::table('engineers')->where('id', 1)->value('self_confirmed_at'));
        $this->post('/cases/2/apply', ['engineer_id' => 1, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1]);
        $this->assertSame(0, DB::table('applications')->where('case_id', 2)->where('engineer_id', 1)->count());
        $this->get('/ses')->assertSee('ご本人の確認がまだ');
        // 本人がホームで確かめると、応募できる。他人の確認はできない
        $eg = $this->as('eg1@park.example');
        $eg->get('/eg')->assertSee('登録の内容の確認');
        $eg->post('/engineers/2/confirm')->assertForbidden();
        $eg->post('/engineers/1/confirm')->assertRedirect(url('/eg'));
        $this->assertNotNull(DB::table('engineers')->where('id', 1)->value('self_confirmed_at'));
        $this->as('ses1@park.example')->post('/cases/2/apply', ['engineer_id' => 1, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1]);
        $this->assertSame(1, DB::table('applications')->where('case_id', 2)->where('engineer_id', 1)->count());

        // 成約したら、本人のホームに「経歴の更新」が出る。書くと消え、終わるとまた出る
        $this->as('admin@park.example');
        $ivId = DB::table('interviews')->where('application_id', 1)->value('id');
        $this->post("/interviews/$ivId/result", ['result' => 'pass']);
        $this->post('/applications/1/contract', ['price' => 68, 'start_date' => date('Y-m-d')]);
        $kid = DB::table('contracts')->where('application_id', 1)->value('id');
        $eg = $this->as('eg1@park.example');
        $eg->get('/eg')->assertSee('経歴の更新');
        $eg->get("/engineers/1/projects/new?contract=$kid")->assertOk()->assertSee('PARK で決まった案件');
        $eg->post('/engineers/1/projects', ['title' => '決まった案件', 'contract' => $kid])->assertRedirect();
        $this->assertNotNull(DB::table('contracts')->where('id', $kid)->value('history_updated_at'));
        $eg->get('/eg')->assertDontSee('経歴の更新');
        DB::table('contracts')->where('id', $kid)->update(['history_updated_at' => date('Y-m-d H:i:s', strtotime('-1 day'))]);
        $this->as('admin@park.example')->post("/contracts/$kid/end", ['end_date' => date('Y-m-d'), 'end_reason' => '満了']);
        $this->assertDatabaseHas('app_notifications', ['title' => '経歴を更新してください：'.DB::table('cases')->where('id', 1)->value('title')]);
        $this->as('eg1@park.example')->get('/eg')->assertSee('終わった案件を書く');

        // 会社の情報：運営が書き換えると、SES の確認がやり直し
        $this->as('admin@park.example')->post('/admin/ses/1', ['name' => 'みどり情報システム', 'notes' => '運営のメモ']);
        $this->assertNull(DB::table('ses_companies')->where('id', 1)->value('self_confirmed_at'));
        $this->as('ses1@park.example')->get('/ses/company')->assertSee('会社の情報の確認');
        $this->post('/ses/company/confirm')->assertRedirect(url('/ses/company'));
        $this->assertNotNull(DB::table('ses_companies')->where('id', 1)->value('self_confirmed_at'));
    }

    public function test_ses_case_mail_flow(): void
    {
        // 1. SES が案件を登録する
        $this->as('ses1@park.example');
        $this->post('/cases', ['title' => 'Java の保守案件', 'required_skills' => 'Java、SQL', 'price' => 60, 'status' => 'open'])->assertRedirect();
        $cid = DB::table('cases')->where('title', 'Java の保守案件')->value('id');
        DB::table('engineers')->where('id', 1)->update(['nickname' => 'たろちゃん']);
        // 2. 候補：自社の人は名前つき。グループのほかの人は名前を伏せる
        $this->get("/cases/$cid/mail")->assertOk()->assertSee('山田 太郎');
        $this->get("/cases/$cid/mail?scope=group")->assertOk()->assertDontSee('佐藤 美咲')->assertSee('あおぞらテック');
        // 3・4. 同じ内容で作る → 送る前の確かめ（宛名は愛称）
        $tpl = ['mode' => 'same', 'subject' => '【案件】{案件名}', 'body' => "{呼び名} さん\n{案件名}／{単価}"];
        $this->post("/cases/$cid/mail/preview", $tpl + ['engineer_id' => [1, 2]])->assertOk()->assertSee('たろちゃん さん')->assertDontSee('佐藤');
        $this->post("/cases/$cid/mail/send", ['mode' => 'same', 'to' => [1 => ['send' => 1, 'subject' => '【案件】Java の保守案件', 'body' => 'たろちゃん さん'], 2 => ['send' => 1, 'subject' => 'x', 'body' => 'y']]])->assertRedirect(url("/cases/$cid"));
        $this->assertSame(1, DB::table('case_mails')->where('case_id', $cid)->count()); // 自社の画面からは、ほかの SES の人に送れない
        $m = DB::table('case_mails')->where('case_id', $cid)->first();
        $this->assertSame('own', $m->route);
        $this->assertSame('ses1@park.example', DB::table('mail_outbox')->where('id', $m->outbox_id)->value('reply_to')); // 返信は SES に届く
        // 5. 返事を記録 → 6. 書類をダウンロード
        $this->post("/case-mails/{$m->id}/reply", ['reply' => 'interested', 'note' => '単価は相談したい'])->assertRedirect();
        $this->assertDatabaseHas('case_mails', ['id' => $m->id, 'reply' => 'interested', 'reply_via' => 'ses']);
        $this->get("/case-mails/{$m->id}/doc/careersheet")->assertOk();
        $this->get("/cases/$cid")->assertSee('案内メール')->assertSee('単価は相談したい');

        // グループのほかの人（プラスα）：PARK から送り、名前を伏せた書類は「応募したい」のあとだけ
        $this->post("/cases/$cid/mail/send", ['scope' => 'group', 'mode' => 'each', 'to' => [2 => ['send' => 1, 'subject' => '件名', 'body' => '本文']]])->assertRedirect();
        $g = DB::table('case_mails')->where('case_id', $cid)->where('engineer_id', 2)->first();
        $this->assertSame('group', $g->route);
        $this->assertNull(DB::table('mail_outbox')->where('id', $g->outbox_id)->value('reply_to'));
        $this->get("/case-mails/{$g->id}/doc/careersheet-anon")->assertForbidden();
        $this->get("/case-mails/{$g->id}/doc/resume")->assertForbidden();
        // 本人が PARK の画面で見送ると、返事が記録される
        $this->as('eg2@park.example')->post("/cases/$cid/decline", ['reason' => '時期が合わない']);
        $this->assertDatabaseHas('case_mails', ['id' => $g->id, 'reply' => 'declined', 'reply_via' => 'app']);
        // 案件を持ち込んでいない SES は、案内できない
        $this->as('ses2@park.example')->get("/cases/$cid/mail")->assertForbidden();
        // 持ち込んでいない SES は、自社のエンジニアを推薦できる（持ち込んだ SES に知らせる）
        $this->post("/cases/$cid/recommend", ['engineer_id' => 2])->assertRedirect();
        $this->assertDatabaseHas('app_notifications', ['user_id' => DB::table('users')->where('email', 'ses1@park.example')->value('id'), 'title' => 'エンジニアの推薦がありました：Java の保守案件']);
        $this->post("/cases/$cid/recommend", ['engineer_id' => 1])->assertForbidden(); // 他社のエンジニアは推薦できない
        // 案内メールの返事のリンク：開くだけでは記録しない。ボタンで記録し、送った SES に知らせる
        $tok = DB::table('case_mails')->where('id', $m->id)->value('token');
        $this->assertStringContainsString("/r/$tok/interested", DB::table('mail_outbox')->where('id', $m->outbox_id)->value('body'));
        auth()->logout();
        $this->get("/r/$tok/question")->assertOk()->assertSee('Java の保守案件');
        $this->assertDatabaseHas('case_mails', ['id' => $m->id, 'reply_via' => 'ses']);
        $this->post("/r/$tok", ['answer' => 'question', 'note' => '在宅は何日？'])->assertOk()->assertSee('受け付けました');
        $this->assertDatabaseHas('case_mails', ['id' => $m->id, 'reply' => 'question', 'reply_via' => 'link', 'reply_note' => '在宅は何日？']);
        $this->get('/r/'.str_repeat('0', 32).'/question')->assertNotFound();
        // 「今やること」に出る
        $this->as('ses1@park.example')->get('/ses')->assertSee('今やること')->assertSee('山田 太郎 さんから質問');
        $this->as('eg1@park.example')->get('/eg')->assertSee('今やること');
        $this->as('admin@park.example')->get('/admin')->assertSee('今やること');

        // 書類を返した（応募した）→ 面談依頼が来た → 面談スクリプト → 結果 → 成約（SES だけで進められる）
        $this->as('ses1@park.example')->post("/case-mails/{$m->id}/submitted")->assertRedirect();
        $appId = DB::table('applications')->where('case_id', $cid)->where('engineer_id', 1)->value('id');
        $this->assertDatabaseHas('applications', ['id' => $appId, 'status' => 'submitted']);
        $this->get("/cases/$cid")->assertSee('応募・面談の画面へ');
        $this->post("/applications/$appId/interview", ['scheduled_at' => date('Y-m-d').'T10:00', 'place' => 'オンライン', 'request_note' => "設計の経験\nリーダー経験の有無"])->assertRedirect();
        $iv = DB::table('interviews')->where('application_id', $appId)->first();
        $qa = json_decode($iv->qa_json, true);
        $this->assertSame('確認：案件とスキル', $qa[0]['category']);
        $this->assertContains('リーダー経験の有無', array_column($qa, 'q'));
        $this->get("/interviews/{$iv->id}")->assertOk()->assertSee('面談スクリプト')->assertSee('リーダー経験の有無');
        $this->assertDatabaseHas('app_notifications', ['user_id' => DB::table('users')->where('email', 'admin@park.example')->value('id'), 'link' => "/interviews/{$iv->id}"]);
        $this->post("/interviews/{$iv->id}/result", ['result' => 'pass'])->assertRedirect();
        $this->post("/applications/$appId/contract", ['price' => 60, 'start_date' => date('Y-m-d')])->assertRedirect();
        $this->assertDatabaseHas('applications', ['id' => $appId, 'status' => 'won']);
        // ほかの SES の応募は触れない
        $this->as('ses2@park.example')->post("/applications/$appId/interview", ['place' => 'x'])->assertForbidden();
    }

    public function test_ai_job_box(): void
    {
        config(['services.park_ai.token' => 'test-token']);
        $h = ['X-Park-Ai-Token' => 'test-token'];
        // 合言葉がないと、AI は仕事を取れない
        $this->postJson('/ai/worker/next')->assertForbidden();
        $this->postJson('/ai/worker/next', [], $h)->assertJson(['job' => null]);

        // 面談スクリプト：頼む → AI が取りに来る → 返す → 人が採用する
        $this->as('admin@park.example');
        $ivId = DB::table('interviews')->where('application_id', 1)->value('id');
        $this->post("/interviews/$ivId/ai")->assertRedirect();
        $this->get("/interviews/$ivId")->assertSee('順番待ち');
        $job = $this->postJson('/ai/worker/next', [], $h)->json('job');
        $this->assertSame('script', $job['kind']);
        $this->assertStringContainsString('盛らない', $job['prompt']);
        $n = count(json_decode(DB::table('interviews')->where('id', $ivId)->value('qa_json'), true));
        $items = array_fill(0, $n, ['category' => '確認', 'q' => '整えた質問', 'hint' => '整えたヒント']);
        $items[0] = ['category' => '自己紹介', 'q' => '自己紹介をお願いします。', 'hint' => '1分で。'];
        $out = "```json\n".json_encode(['items' => $items], JSON_UNESCAPED_UNICODE)."\n```";
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => $out, 'engine' => 'test'], $h)->assertOk();
        $this->get("/interviews/$ivId")->assertSee('この下書きを採用する');
        $this->get('/admin')->assertSee('AI の下書きができています');
        $this->post("/ai-jobs/{$job['id']}/apply")->assertRedirect();
        $this->assertSame('自己紹介をお願いします。', json_decode(DB::table('interviews')->where('id', $ivId)->value('qa_json'), true)[0]['q']);

        // 経歴書（テキスト）から経験票の下書き → 1件ずつ足す
        \Illuminate\Support\Facades\Storage::fake('local');
        $this->as('eg2@park.example');
        $this->post('/engineers/2/docs/upload', ['file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('経歴書.txt', '2020年4月〜2022年3月 物流会社の倉庫システム PHP')]);
        $fid = DB::table('engineer_files')->where('engineer_id', 2)->where('filename', '経歴書.txt')->value('id');
        $this->post("/engineers/2/docs/$fid/ai")->assertRedirect();
        $job = $this->postJson('/ai/worker/next', [], $h)->json('job');
        $this->assertSame('resume', $job['kind']);
        $this->assertStringContainsString('倉庫システム', $job['prompt']);
        $res = ['projects' => [['title' => '倉庫システムの開発', 'period_from' => '2020-04', 'period_to' => '2022-03', 'langs' => 'PHP', 'phases' => ['実装']]]];
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => json_encode($res, JSON_UNESCAPED_UNICODE), 'engine' => 'test'], $h);
        $this->get('/engineers/2')->assertSee('倉庫システムの開発')->assertSee('経験票に足す');
        $this->post("/ai-jobs/{$job['id']}/project/0")->assertRedirect();
        $this->assertDatabaseHas('engineer_projects', ['engineer_id' => 2, 'title' => '倉庫システムの開発', 'period_from' => '2020-04', 'phases' => '実装']);
        // ほかの人の下書きは触れない
        $this->as('eg1@park.example')->post("/ai-jobs/{$job['id']}/project/0")->assertForbidden();
        // JSON でない答えは「失敗」
        $this->as('admin@park.example')->post("/interviews/$ivId/ai");
        $job = $this->postJson('/ai/worker/next', [], $h)->json('job');
        $this->postJson("/ai/worker/{$job['id']}/result", ['output' => 'すみません', 'engine' => 'test'], $h);
        $this->assertDatabaseHas('ai_jobs', ['id' => $job['id'], 'status' => 'failed']);
        $this->get('/admin/ai')->assertOk()->assertSee('失敗');
    }

    public function test_status_command(): void
    {
        $this->artisan('park:status')->expectsOutputToContain('エンジニア：本人の確認がまだ')->assertExitCode(0);
        $this->artisan('park:status --json')->assertExitCode(0);
    }

    public function test_manuals(): void
    {
        $this->get('/manual')->assertOk()->assertSee('SES 企業さま向けマニュアル')->assertDontSee('運営マニュアル');
        $this->get('/manual/ses')->assertOk()->assertSee('参加のお申し込み')->assertSee('<table>', false);
        $this->get('/manual/engineer')->assertOk()->assertSee('経験票');
        $this->get('/manual/admin')->assertForbidden();
        $this->get('/manual/xxx')->assertNotFound();
        $this->as('admin@park.example')->get('/manual/admin')->assertOk()->assertSee('マッチングと配信');
        $this->as('eg1@park.example')->get('/manual')->assertRedirect(url('/manual/engineer'));
    }
}
