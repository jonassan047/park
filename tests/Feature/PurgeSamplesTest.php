<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\SampleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 見本データを消すコマンド（PLAN 2026-10-19 の Q）
class PurgeSamplesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        // このテストで作ったバックアップは消す
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    // 実在の役：使える管理者と、管理者の直属のエンジニア
    private function real(): array
    {
        $aid = W::createUser('real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        DB::table('users')->where('id', $aid)->update(['profile_completed_at' => now_str(), 'company_name' => '実在運営']);
        $admin = User::find($aid);
        $eid = W::createEngineer(W::directSesId(), ['name' => '実在 直属', 'email' => 'real-eg@example.com'], $admin);
        $uid = DB::table('users')->where('email', 'real-eg@example.com')->value('id');
        DB::table('users')->where('id', $uid)->update(['password' => bcrypt('abcd1234'), 'profile_completed_at' => now_str()]);
        DB::table('engineers')->where('id', $eid)->update(['consent_at' => now_str()]); // 同意済み（未同意だと /eg/consent へ送られる）
        return [$admin, $eid];
    }

    private function counts(): array
    {
        $out = [];
        foreach (array_keys(\App\Services\SamplePurge::ORDER) as $t) $out[$t] = DB::table($t)->count();
        return $out;
    }

    private function sampleUsers(): int
    {
        return DB::table('users')->where('email', 'like', '%@park.example')->count();
    }

    private function parkStatus(): array
    {
        Artisan::call('park:status', ['--json' => true]);
        return json_decode(Artisan::output(), true);
    }

    private function backups(): array
    {
        return array_values(array_diff(glob(storage_path('app/backups/purge-samples-*.json')) ?: [], $this->backupsBefore));
    }

    public function test_q6_view_only_changes_nothing_and_hides_names(): void
    {
        $this->real();
        $before = $this->counts();
        $this->assertGreaterThan(0, $this->sampleUsers());
        $code = Artisan::call('park:purge-samples');
        $out = Artisan::output();
        $this->assertSame(0, $code);
        $this->assertSame($before, $this->counts());
        $this->assertSame([], $this->backups());
        $this->assertStringNotContainsString('@park.example', $out);
        $this->assertStringNotContainsString('@example.com', $out);
        $this->assertStringNotContainsString('山田', $out);
        $this->assertStringNotContainsString('みどり情報システム', $out);
        $this->assertStringContainsString('見るだけ', $out);
        foreach (['users', 'ses_companies', 'engineers', 'cases', 'applications', 'contracts', 'invoices', 'mail_outbox', 'app_notifications', 'audit_log'] as $t) {
            $this->assertStringContainsString($t, $out);
        }
    }

    public function test_q5_1_no_other_usable_admin_stops_everything(): void
    {
        [$admin] = $this->real();
        DB::table('users')->where('id', $admin->id)->delete();
        $before = $this->counts();
        $this->assertSame(1, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertSame($before, $this->counts());
        $this->assertSame([], $this->backups());
        // 停止中・パスワード無しの管理者は「使える管理者」に入らない
        $a = W::createUser('stopped-admin@example.com', '停止 管理', 'admin', 'abcd1234');
        DB::table('users')->where('id', $a)->update(['active' => 0]);
        $b = W::createUser('nopass-admin@example.com', '無し 管理', 'admin', 'abcd1234');
        DB::table('users')->where('id', $b)->update(['password' => null]);
        $this->assertSame(1, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertSame($before['users'] + 2, $this->counts()['users']);
        $this->assertGreaterThan(0, $this->sampleUsers());
    }

    public function test_q5_2_real_engineer_in_sample_ses_stops_unless_move_option(): void
    {
        [$admin, $directEng] = $this->real();
        $sesId = (int) User::where('email', 'ses1@park.example')->value('ses_id');
        $moved = W::createEngineer($sesId, ['name' => '実在 移動', 'email' => 'real-eg2@example.com'], $admin);
        $before = $this->counts();
        $this->assertSame(1, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertSame($before, $this->counts());
        $this->assertSame($sesId, (int) DB::table('engineers')->find($moved)->ses_id);
        $this->assertSame([], $this->backups());
        $this->assertGreaterThan(0, $this->sampleUsers());

        // 見るだけでも、止まる理由が出る（名前・メールは出さない）
        $this->assertSame(1, Artisan::call('park:purge-samples'));
        $this->assertStringNotContainsString('real-eg2@example.com', Artisan::output());

        // 移すオプションを付けたときだけ、直属へ移して続ける
        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true, '--move-kept-to-direct' => true]));
        $e = DB::table('engineers')->find($moved);
        $this->assertNotNull($e);
        $this->assertSame(W::directSesId(), (int) $e->ses_id);
        $this->assertSame(W::directSesId(), (int) DB::table('users')->where('email', 'real-eg2@example.com')->value('ses_id'));
        $this->assertSame(0, $this->sampleUsers());
        $this->assertNotNull(DB::table('engineers')->find($directEng));
    }

    public function test_q5_2_real_ses_contact_in_sample_ses_is_never_moved(): void
    {
        $this->real();
        $sesId = (int) User::where('email', 'ses1@park.example')->value('ses_id');
        W::createUser('real-contact@example.com', '実在 担当', 'ses', 'abcd1234', ['ses_id' => $sesId]);
        $before = $this->counts();
        $this->assertSame(1, Artisan::call('park:purge-samples', ['--yes' => true, '--move-kept-to-direct' => true]));
        $this->assertSame($before, $this->counts());
        $this->assertSame($sesId, (int) DB::table('users')->where('email', 'real-contact@example.com')->value('ses_id'));
    }

    public function test_q5_3_real_case_with_sample_application_stops(): void
    {
        [$admin] = $this->real();
        $caseId = W::saveCase(['title' => '実在の案件', 'price' => 70, 'status' => 'open'], $admin);
        $eg1 = (int) User::where('email', 'eg1@park.example')->value('engineer_id');
        DB::table('engineers')->where('id', $eg1)->update(['self_confirmed_at' => now_str()]);
        W::apply($caseId, $eg1, ['start_ok' => 1], $admin);
        $before = $this->counts();
        $this->assertSame(1, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertSame($before, $this->counts());
        $this->assertSame([], $this->backups());
        $this->assertNotNull(DB::table('cases')->find($caseId));
        // --move-kept-to-direct でも、この守りは外れない
        $this->assertSame(1, Artisan::call('park:purge-samples', ['--yes' => true, '--move-kept-to-direct' => true]));
        $this->assertSame($before, $this->counts());
    }

    public function test_q7_q8_purge_everything_sample_and_keep_real(): void
    {
        [$admin, $directEng] = $this->real();
        $realCase = W::saveCase(['title' => '実在の案件', 'price' => 70, 'status' => 'open'], $admin);
        $realUsers = DB::table('users')->where('email', 'like', '%@example.com')->count();
        $this->assertSame(0, DB::table('audit_log')->where('action', 'purge-samples')->count());

        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));
        $out = Artisan::output();
        $this->assertStringContainsString('バックアップ', $out);
        $this->assertStringNotContainsString('@park.example', $out);

        $this->assertSame(0, $this->sampleUsers());
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'ses')->count());
        $this->assertSame(1, DB::table('ses_companies')->where('kind', 'direct')->count());
        $this->assertSame(0, DB::table('engineers')->where('email', 'like', '%@park.example')->count());
        $this->assertSame(1, DB::table('engineers')->count()); // 直属の実在の人だけ
        $this->assertNotNull(DB::table('engineers')->find($directEng));
        $this->assertSame(1, DB::table('cases')->count());
        $this->assertNotNull(DB::table('cases')->find($realCase));
        foreach (['applications', 'contracts', 'invoices', 'case_offers', 'interviews', 'follow_ups', 'messages', 'engineer_skills', 'engineer_projects'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), $t);
        }
        $this->assertSame($realUsers, DB::table('users')->where('email', 'like', '%@example.com')->count());
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'like', '%@park.example')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'purge-samples')->count());
        $detail = (string) DB::table('audit_log')->where('action', 'purge-samples')->value('detail');
        $this->assertStringNotContainsString('@park.example', $detail);
        $this->assertStringNotContainsString('山田', $detail);

        // バックアップの JSON
        $files = $this->backups();
        $this->assertCount(1, $files);
        $json = json_decode(file_get_contents($files[0]), true);
        $this->assertIsArray($json);
        $this->assertNotEmpty($json['表']['users']);
        $this->assertArrayHasKey('email', $json['表']['users'][0]);
        $this->assertNotEmpty($json['表']['engineers']);

        // 見本の行が 0、残った管理者でダッシュボードが開く（直属のエンジニアがいるので「はじめましょう」は出ない）
        $this->assertSame(0, $this->parkStatus()['見本データ（@park.example）']);
        $this->as('real-admin@example.com')->get('/admin')->assertOk()->assertDontSee('PARK をはじめましょう')->assertSee('招待の状況');
        foreach (['/admin/ses', '/admin/engineers', '/admin/cases', '/admin/applications', '/admin/contracts', '/admin/invoices', '/admin/mail', '/admin/reports', '/admin/settings'] as $t) {
            $this->get($t)->assertOk();
        }
        // 実在の直属エンジニアのログインはそのまま
        $this->assertNotNull(DB::table('users')->where('email', 'real-eg@example.com')->value('password'));
        $this->as('real-eg@example.com')->get('/eg')->assertOk();
    }

    public function test_q7_notifications_pointing_to_deleted_things_are_removed(): void
    {
        [$admin] = $this->real();
        $eg1 = (int) User::where('email', 'eg1@park.example')->value('engineer_id');
        $case = (int) DB::table('cases')->orderBy('id')->value('id');
        N_insert($admin->id, "/engineers/$eg1");
        N_insert($admin->id, "/cases/$case");
        N_insert($admin->id, '/notifications');
        $keep = N_insert($admin->id, '/engineers/'.W::directSesId() . '9999');
        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $admin->id)->where('link', "/engineers/$eg1")->count());
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $admin->id)->where('link', "/cases/$case")->count());
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $admin->id)->where('link', '/notifications')->count());
        $this->assertNotNull(DB::table('app_notifications')->find($keep));
    }

    public function test_q9_second_run_does_nothing(): void
    {
        $this->real();
        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));
        $counts = $this->counts();
        $files = $this->backups();
        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertStringContainsString('0件', Artisan::output());
        $this->assertSame($counts, $this->counts()); // 0件のときは記録も増やさない
        $this->assertSame(1, DB::table('audit_log')->where('action', 'purge-samples')->count());
        $this->assertSame($files, $this->backups());
    }

    public function test_q4_direct_row_and_non_sample_ses_are_not_touched(): void
    {
        $this->real();
        $other = DB::table('ses_companies')->insertGetId(['name' => '実在 SES', 'kind' => 'ses', 'status' => 'active', 'email' => 'real-ses@example.com', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertNotNull(DB::table('ses_companies')->find($other));
        $this->assertSame(1, DB::table('ses_companies')->where('kind', 'direct')->count());
        $this->assertSame(1, DB::table('ses_companies')->where('kind', 'ses')->count());
    }

    public function test_q1_sample_seeder_does_not_run_in_production(): void
    {
        $users = DB::table('users')->count();
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue(app()->isProduction());
        (new SampleSeeder())->run();
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame($users, DB::table('users')->count());
    }

    public function test_q1_database_seeder_only_syncs_skill_master(): void
    {
        DB::table('mail_outbox')->delete();
        DB::table('app_notifications')->delete();
        DB::table('audit_log')->delete();
        foreach (['application_events', 'interviews', 'follow_ups', 'contracts', 'invoices', 'applications', 'case_offers', 'case_mails', 'messages', 'ai_jobs', 'engineer_skills', 'engineer_projects',
            'engineer_histories', 'engineer_certs', 'engineer_files', 'engineers', 'cases'] as $t) DB::table($t)->delete();
        DB::table('users')->delete();
        DB::table('ses_companies')->delete();
        DB::table('skill_terms')->delete();
        $this->seed(DatabaseSeeder::class);
        $this->assertSame(0, DB::table('users')->count());
        $this->assertSame(0, DB::table('ses_companies')->count());
        $this->assertSame(0, DB::table('engineers')->count());
        $this->assertSame(0, DB::table('cases')->count());
        $this->assertGreaterThan(0, DB::table('skill_terms')->count());
    }

    public function test_q2_tests_still_use_sample_data(): void
    {
        $this->assertGreaterThan(0, $this->sampleUsers());
    }
}

function N_insert(int $userId, string $link): int
{
    return (int) DB::table('app_notifications')->insertGetId(['user_id' => $userId, 'title' => 'テスト通知', 'body' => '本文', 'link' => $link, 'created_at' => now_str()]);
}
