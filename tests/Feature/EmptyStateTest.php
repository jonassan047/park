<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 見本データなしで始める（PLAN 2026-10-19 の P）。$seed を使わない＝テーブルは空
class EmptyStateTest extends TestCase
{
    use RefreshDatabase;

    // 見本なしで始める。メモリ DB は最初のテストの準備を全体で使い回すため（他のテストの見本が入った状態から始まる）、
    // $seed を false にするだけでは空にならない。各テストの最初に、見本の行を（このテストの中だけ）全部消す。
    // TestCase の $seeder が入っていると $seed = false でも見本が入るので、ここでは外しておく
    protected $seeder = false;
    protected $seed = false;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['application_events', 'interviews', 'follow_ups', 'contracts', 'invoices', 'applications', 'case_offers', 'case_mails', 'messages', 'ai_jobs',
            'engineer_skills', 'engineer_projects', 'engineer_histories', 'engineer_certs', 'engineer_files', 'engineers', 'cases',
            'mail_outbox', 'app_notifications', 'audit_log', 'sessions', 'users', 'ses_companies'] as $t) {
            DB::table($t)->delete();
        }
    }

    private function admin(): User
    {
        $id = W::createUser('first@example.com', '最初 管理者', 'admin', 'abcd1234');
        DB::table('users')->where('id', $id)->update(['profile_completed_at' => now_str(), 'company_name' => '最初の会社']);
        return User::find($id);
    }

    private function as(User|string $u): static
    {
        $u = is_string($u) ? User::where('email', $u)->firstOrFail() : $u;
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    public function test_nothing_is_seeded(): void
    {
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('ses_companies')->count());
        $this->assertSame(0, DB::table('engineers')->count());
        $this->assertSame(0, DB::table('cases')->count());
    }

    public function test_p1_start_card_with_four_steps(): void
    {
        $a = $this->admin();
        $r = $this->as($a)->get('/admin')->assertOk();
        $r->assertSee('PARK をはじめましょう');
        $r->assertSeeInOrder(['href="'.url('/admin/settings').'"', 'href="'.url('/admin/ses/new').'"', 'href="'.url('/engineers/new').'"', 'href="'.url('/cases/new').'"'], false);
        $r->assertSee('ほかの管理者を招待する')->assertSee('SES を登録して、ご担当者を招待する')->assertSee('エンジニアを登録する')->assertSee('案件を登録する');
        $r->assertDontSee('<span class="badge ok">済み</span>', false);
    }

    public function test_p1_step_one_is_marked_done_with_second_admin(): void
    {
        $a = $this->admin();
        W::createUser('second@example.com', '二人目 管理', 'admin', 'abcd1234');
        $this->as($a)->get('/admin')->assertSee('PARK をはじめましょう')->assertSee('<span class="badge ok">済み</span>', false);
    }

    public function test_p2_stats_are_zero(): void
    {
        $a = $this->admin();
        $r = $this->as($a)->get('/admin')->assertOk();
        $r->assertSee('SES（参加中）')->assertSee('申込 0・招待中 0')->assertSee('待機 0')->assertSee('全体 0')->assertSee('0件')->assertSee('発行済み・入金待ち')
            ->assertSee('招待中')->assertSee('0人');
        $c = $r->viewData('counts');
        foreach (['ses', 'sesPending', 'sesInvited', 'eng', 'engAvail', 'cases', 'casesAll', 'invUnpaid', 'invited', 'apps', 'contracts'] as $k) $this->assertSame(0, $c[$k], $k);
    }

    public function test_p3_invite_status_card_with_only_me(): void
    {
        $a = $this->admin();
        $r = $this->as($a)->get('/admin')->assertOk();
        $r->assertSee('招待の状況')->assertSee('まだ誰も招待していません。上の手順から始めます。')->assertSee('最初 管理者')->assertSee('最初の会社')->assertSee('使用中');
        $r->assertSeeInOrder(['立場', '名前', '所属', '状態', '最後のログイン']);
    }

    public function test_p3_states_pending_expired_stopped_and_unregistered_ses(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/admin/settings/admins', ['name' => '招待 一郎', 'company_name' => 'テスト企業', 'email' => 'inv1@example.com'])->assertRedirect();
        $exp = W::createUser('exp@example.com', '期限 切れ', 'admin', 'x');
        DB::table('users')->where('id', $exp)->update(['password' => null, 'token' => 'abc', 'token_expires' => now()->subDay()->format('Y-m-d H:i:s')]);
        $stop = W::createUser('stop@example.com', '停止 中', 'admin', 'x');
        DB::table('users')->where('id', $stop)->update(['active' => 0]);
        DB::table('ses_companies')->insert(['name' => '担当者なし SES', 'kind' => 'ses', 'status' => 'active', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $r = $this->get('/admin')->assertOk();
        $r->assertSee('招待中')->assertSee('期限切れ')->assertSee('停止')->assertSee('担当者が未登録')->assertSee('担当者なし SES')->assertSee('招待を送り直す')->assertSee('まで');
        $r->assertDontSee('まだ誰も招待していません');
        $sesId = DB::table('ses_companies')->where('name', '担当者なし SES')->value('id');
        $r->assertSee('href="'.url("/admin/ses/$sesId").'"', false);
        // 招待中の人は「招待中」、期限切れの人は「期限切れ」の行
        $html = $r->getContent();
        $this->assertMatchesRegularExpression('#招待 一郎.*?招待中#us', $html);
        $this->assertMatchesRegularExpression('#期限 切れ.*?期限切れ#us', $html);
        // 送り直しの操作は招待中・期限切れの管理者だけ（自分の行・停止の人には出ない）
        $this->assertSame(2, substr_count($html, '/reinvite'));
    }

    public function test_p4_empty_texts(): void
    {
        $a = $this->admin();
        $r = $this->as($a)->get('/admin')->assertOk();
        $r->assertSee('募集中の案件はありません。「案件を登録」から入れます。')->assertSee('まだ応募はありません。')->assertSee('ありません。')
            ->assertDontSee('参加の申し込み')->assertDontSee('1か月以内に終わる契約')->assertDontSee('未読のメッセージ');
    }

    public function test_p7_admin_tabs_open_with_empty_text(): void
    {
        $a = $this->admin();
        $this->as($a);
        $tabs = ['/admin/ses', '/admin/engineers', '/admin/cases', '/admin/applications', '/admin/contracts', '/admin/invoices', '/admin/messages',
            '/admin/mail', '/admin/reports', '/admin/ai', '/admin/settings', '/admin/audit', '/admin/skills', '/manual', '/notifications', '/account', '/account/profile',
            '/engineers/new', '/cases/new', '/admin/ses/new'];
        foreach ($tabs as $t) $this->get($t)->assertOk();
        foreach (['/admin/ses', '/admin/engineers', '/admin/cases', '/admin/contracts', '/admin/invoices', '/admin/messages'] as $t) {
            $this->assertMatchesRegularExpression('/ありません|まだ|ない|0件/u', $this->get($t)->getContent(), $t);
        }
        // ボードは各列 0 と「終わったもの：まだありません。」
        $this->get('/admin/applications')->assertSee('終わったもの')->assertSee('まだありません');
    }

    public function test_p1_disappears_after_registering_ses_and_p3_shows_invite(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/admin/ses', ['name' => '新しい SES', 'contact_name' => '担当 者', 'email' => 'ses-new@example.com'])->assertRedirect();
        $this->assertSame(1, DB::table('mail_outbox')->where('kind', 'invite')->count());
        $r = $this->get('/admin')->assertOk();
        $r->assertDontSee('PARK をはじめましょう');
        $r->assertSee('招待中')->assertSee('新しい SES');
        $this->assertSame(1, $r->viewData('counts')['ses']);
        $this->assertSame(1, $r->viewData('counts')['sesInvited']);
        $this->assertSame(1, $r->viewData('counts')['invited']);
    }

    public function test_p1_disappears_with_only_a_case_or_only_an_engineer(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/engineers', ['ses_id' => 'direct', 'name_sei' => '直属', 'name_mei' => '一郎'])->assertRedirect();
        $this->get('/admin')->assertDontSee('PARK をはじめましょう');
        DB::table('engineers')->delete();
        $this->get('/admin')->assertSee('PARK をはじめましょう');
        $this->post('/cases', ['title' => '最初の案件', 'price' => '60', 'status' => 'open'])->assertRedirect();
        $this->get('/admin')->assertDontSee('PARK をはじめましょう');
    }

    // P8：見本なしの状態から、SES の登録 → 担当者のログイン → エンジニア → 案件 → 配信 → 応募
    public function test_p8_real_data_flow_from_empty(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/admin/ses', ['name' => '実データ SES', 'contact_name' => '担当 花子', 'email' => 'contact@example.com'])->assertRedirect();
        $sesId = (int) DB::table('ses_companies')->where('name', '実データ SES')->value('id');
        $contact = DB::table('users')->where('email', 'contact@example.com')->first();
        $this->assertSame('ses', $contact->role);
        $this->assertNull($contact->password);

        // 担当者が招待のリンクでパスワードを決め、最初の情報を登録する
        auth()->logout();
        $this->flushSession();
        $this->get('/invite/'.$contact->token)->assertOk();
        $this->post('/invite/'.$contact->token, ['password' => 'abcd1234', 'password_confirmation' => 'abcd1234'])->assertRedirect();
        $this->assertNotNull(DB::table('users')->where('id', $contact->id)->value('password'));
        auth()->logout();
        $this->flushSession();
        DB::table('users')->where('id', $contact->id)->update(['profile_completed_at' => null]);
        $this->as('contact@example.com')->get('/ses')->assertRedirect(url('/welcome'));
        $this->post('/welcome', ['name_sei' => '担当', 'name_mei' => '花子', 'ses_name' => '実データ SES'])->assertRedirect();
        DB::table('ses_companies')->where('id', $sesId)->update(['self_confirmed_at' => now_str()]);

        // SES のタブは空でも全部開く。ダッシュボードにはエンジニアの登録の案内
        $this->as('contact@example.com');
        $this->get('/ses')->assertOk()->assertSee('エンジニアを登録しましょう')->assertSee('貴社のエンジニアを登録すると、合う案件が届くようになります。')
            ->assertSee('まだ案件はありません。案件が入ると、ここに出ます。')->assertSee('成約率 —');
        foreach (['/ses/engineers', '/ses/cases', '/ses/applications', '/ses/contracts', '/ses/invoices', '/ses/company', '/engineers/new', '/cases/new'] as $t) {
            $this->get($t)->assertOk();
        }

        // SES がエンジニアを登録する（姓・名）
        $this->post('/engineers', ['name_sei' => '実在', 'name_mei' => '太郎', 'kana_sei' => 'じつざい', 'kana_mei' => 'たろう', 'email' => 'eg-real@example.com'])->assertRedirect();
        $e = DB::table('engineers')->where('name', '実在 太郎')->first();
        $this->assertNotNull($e);
        $this->assertSame($sesId, (int) $e->ses_id);
        $this->get('/ses')->assertDontSee('エンジニアを登録しましょう')->assertSee('経験票がまだのエンジニア');

        // 管理者が案件を登録して配信する
        $this->as($a)->post('/cases', ['title' => '実データの案件', 'price' => '70', 'required_skills' => 'PHP', 'status' => 'open'])->assertRedirect();
        $caseId = (int) DB::table('cases')->where('title', '実データの案件')->value('id');
        $this->get('/admin')->assertDontSee('PARK をはじめましょう');
        $this->post("/cases/$caseId/distribute", ['engineer_id' => [$e->id], 'message' => 'どうぞ'])->assertRedirect();
        $this->assertSame(1, DB::table('case_offers')->where('case_id', $caseId)->where('engineer_id', $e->id)->count());

        // エンジニア本人が招待を受け、ホームで案件を見て応募する
        $eu = DB::table('users')->where('email', 'eg-real@example.com')->first();
        $this->assertNotNull($eu);
        DB::table('users')->where('id', $eu->id)->update(['password' => bcrypt('abcd1234'), 'profile_completed_at' => now_str(), 'token' => null]);
        DB::table('engineers')->where('id', $e->id)->update(['self_confirmed_at' => now_str(), 'consent_at' => now_str()]);
        $r = $this->as('eg-real@example.com')->get('/eg')->assertOk();
        $r->assertSee('経験票を入れましょう')->assertSee('経験票（これまでの案件）を入れると、合う案件が届きやすくなります。')
            ->assertSee('href="'.url("/engineers/{$e->id}/projects/new").'"', false)->assertSee('実データの案件');
        foreach (['/eg/offers', '/eg/applications'] as $t) $this->get($t)->assertOk();
        foreach (['/eg/profile', '/eg/sheet', '/eg/messages'] as $t) $this->get($t)->assertRedirect();
        $this->get("/engineers/{$e->id}")->assertOk();
        $this->get("/engineers/{$e->id}/sheet")->assertOk();
        $this->get("/engineers/{$e->id}/messages")->assertOk();
        $this->get('/account/profile')->assertOk()->assertSee('eg-real@example.com')->assertSee('プロフィール・経験票を見る');
        $this->post("/cases/$caseId/apply", ['engineer_id' => $e->id, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $this->assertSame(1, DB::table('applications')->where('case_id', $caseId)->where('engineer_id', $e->id)->count());

        // 応募のあと、管理者のダッシュボードに数が出る
        $d = $this->as($a)->get('/admin')->assertOk();
        $this->assertSame(1, $d->viewData('counts')['eng']);
        $this->assertSame(1, $d->viewData('counts')['apps']);
        $d->assertDontSee('まだ応募はありません。');
        $this->get('/admin/applications')->assertOk();
    }

    public function test_p6_engineer_with_project_has_no_guide_card(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/engineers', ['ses_id' => 'direct', 'name_sei' => '経験', 'name_mei' => '有り', 'email' => 'exp@example.com'])->assertRedirect();
        $e = DB::table('engineers')->where('name', '経験 有り')->first();
        $uid = DB::table('users')->where('email', 'exp@example.com')->value('id');
        DB::table('users')->where('id', $uid)->update(['password' => bcrypt('abcd1234'), 'profile_completed_at' => now_str()]);
        DB::table('engineers')->where('id', $e->id)->update(['self_confirmed_at' => now_str()]);
        $this->as('exp@example.com')->get('/eg')->assertOk()->assertSee('経験票を入れましょう')->assertSee('いまは届いていません。');
        DB::table('engineer_projects')->insert(['engineer_id' => $e->id, 'title' => '過去の案件']);
        $this->get('/eg')->assertOk()->assertDontSee('経験票を入れましょう');
    }

    public function test_p5_ses_dashboard_with_engineer_has_no_guide(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/admin/ses', ['name' => 'SES-X', 'contact_name' => '担当 者', 'email' => 'sx@example.com'])->assertRedirect();
        $sid = (int) DB::table('ses_companies')->where('name', 'SES-X')->value('id');
        $uid = DB::table('users')->where('email', 'sx@example.com')->value('id');
        DB::table('users')->where('id', $uid)->update(['password' => bcrypt('abcd1234'), 'profile_completed_at' => now_str()]);
        DB::table('ses_companies')->where('id', $sid)->update(['self_confirmed_at' => now_str()]);
        $this->as('sx@example.com')->get('/ses')->assertSee('エンジニアを登録しましょう');
        $this->post('/engineers', ['name_sei' => '所属', 'name_mei' => '一人'])->assertRedirect();
        $this->get('/ses')->assertDontSee('エンジニアを登録しましょう');
    }

    public function test_other_roles_cannot_use_admin_screens(): void
    {
        $a = $this->admin();
        $this->as($a)->post('/admin/ses', ['name' => 'SES-Y', 'contact_name' => '担当 者', 'email' => 'sy@example.com'])->assertRedirect();
        $uid = DB::table('users')->where('email', 'sy@example.com')->value('id');
        DB::table('users')->where('id', $uid)->update(['password' => bcrypt('abcd1234'), 'profile_completed_at' => now_str()]);
        $this->as('sy@example.com')->get('/admin')->assertForbidden();
        $this->get('/admin/settings')->assertForbidden();
        $this->get('/eg')->assertForbidden();
        $this->get('/account/profile')->assertOk();
    }
}
