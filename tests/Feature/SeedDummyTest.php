<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// テスト用のにぎやかしダミーデータ park:seed-dummy（PLAN 2026-10-30 の T1〜T11 と、レビュー指摘の回帰）
class SeedDummyTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $backupsBefore = [];
    private int $adminId;

    private const SMALL = ['--ses' => 5, '--staff' => 8, '--eg' => 12, '--direct' => 5, '--seed' => 1];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
        Mail::fake();
        // 本物の管理者（@park.example ではない）
        $this->adminId = W::createUser('real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        DB::table('users')->where('id', $this->adminId)->update(['profile_completed_at' => now_str(), 'company_name' => '実在運営']);
        SkillCatalog::sync(); // 技術マスタは先に入れておく（増えないことを確かめるため）
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    private function go(array $opt = self::SMALL, bool $yes = true): array
    {
        $code = Artisan::call('park:seed-dummy', $opt + ($yes ? ['--yes' => true] : []));
        return [$code, Artisan::output()];
    }

    private function counts(): array
    {
        $o = [];
        foreach (['users', 'ses_companies', 'engineers', 'engineer_skills', 'app_notifications', 'audit_log', 'mail_outbox', 'skill_terms'] as $t) $o[$t] = DB::table($t)->count();
        $o['direct'] = DB::table('ses_companies')->where('kind', 'direct')->count();
        return $o;
    }

    private function dummyCounts(): array
    {
        return [
            'ses' => DB::table('ses_companies')->where('email', 'like', 'ses-d%@park.example')->count(),
            'staff' => DB::table('users')->where('role', 'ses')->where('email', 'like', 'ses-d%@park.example')->count(),
            'eg' => DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->count(),
            'egu' => DB::table('users')->where('role', 'eg')->where('email', 'like', 'eg-d%@park.example')->count(),
        ];
    }

    private function snapshot(): array
    {
        $u = DB::table('users')->where('email', 'like', '%-d%@park.example')->orderBy('email')->get(['email', 'name', 'kana', 'active', 'profile_completed_at', 'two_factor_confirmed_at'])
            ->map(fn ($r) => [$r->email, $r->name, $r->kana, $r->active, $r->profile_completed_at !== null, $r->two_factor_confirmed_at !== null])->all();
        $eg = DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->orderBy('email')->get(['email', 'name', 'status', 'years_exp', 'nearest_station'])->map(fn ($r) => (array) $r)->all();
        $ses = DB::table('ses_companies')->where('email', 'like', 'ses-d%@park.example')->orderBy('email')->get(['email', 'name', 'status'])->map(fn ($r) => (array) $r)->all();
        return [$u, $eg, $ses];
    }

    public function test_t1_dry_run_changes_nothing(): void
    {
        $before = $this->counts();
        [$code, $out] = $this->go(self::SMALL, false);
        $this->assertSame(0, $code);
        $this->assertStringContainsString('見るだけ', $out);
        $this->assertSame($before, $this->counts());
        [$code] = $this->go([], false); // 既定の件数
        $this->assertSame(0, $code);
        $this->assertSame($before, $this->counts());
    }

    public function test_t2_default_counts(): void
    {
        [$code, $out] = $this->go(['--ses' => 8, '--staff' => 15, '--eg' => 80, '--seed' => 1]);
        $this->assertSame(0, $code, $out);
        // 担当者は、指定の15人に、仮登録の会社の代表者（1社に1人）を足した数
        $this->assertSame(1, DB::table('ses_companies')->where('email', 'like', 'ses-d%@park.example')->where('status', 'provisional')->count());
        $this->assertSame(['ses' => 8, 'staff' => 15 + 1, 'eg' => 80, 'egu' => 80], $this->dummyCounts());
        $direct = DB::table('ses_companies')->where('kind', 'direct')->value('id');
        $this->assertSame(8, DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->where('ses_id', $direct)->count());
        foreach (DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->get() as $e) {
            $this->assertSame(1, DB::table('users')->where('role', 'eg')->where('email', $e->email)->where('engineer_id', $e->id)->count());
            $this->assertGreaterThanOrEqual(3, DB::table('engineer_skills')->where('engineer_id', $e->id)->count(), "skills {$e->id}");
        }
    }

    public function test_t3_states_mix(): void
    {
        $this->go(['--ses' => 8, '--staff' => 15, '--eg' => 80, '--seed' => 1]);
        $st = DB::table('ses_companies')->where('email', 'like', 'ses-d%@park.example')->pluck('status')->all();
        foreach (['pending', 'active', 'suspended'] as $s) $this->assertContains($s, $st);
        foreach (DB::table('ses_companies')->where('status', 'pending')->where('email', 'like', 'ses-d%@park.example')->get() as $c) {
            $this->assertSame(0, DB::table('users')->where('ses_id', $c->id)->count());
        }
        foreach (DB::table('ses_companies')->where('email', 'like', 'ses-d%@park.example')->where('status', '!=', 'pending')->get() as $c) {
            $u = DB::table('users')->where('email', $c->email)->first();
            $this->assertNotNull($u, '1人目の担当者がいる');
            $this->assertSame(1, (int) $u->active, '1人目は停止中にしない');
        }
        $now = now_str();
        foreach (['ses', 'eg'] as $role) {
            $has = ['A' => false, 'B' => false, 'C' => false, 'D' => false, 'E' => false];
            foreach (DB::table('users')->where('role', $role)->where('email', 'like', '%-d%@park.example')->get() as $u) {
                $k = null;
                if ($u->password !== null && $u->active && $u->profile_completed_at && $u->two_factor_confirmed_at) $k = 'A';
                elseif ($u->password !== null && $u->active && !$u->profile_completed_at) $k = 'B';
                elseif ($u->password === null && $u->token && $u->token_expires && $u->token_expires > $now) $k = 'C';
                elseif ($u->password === null && $u->token && $u->token_expires && $u->token_expires < $now) $k = 'D';
                elseif ($u->password !== null && !$u->active && $u->profile_completed_at) $k = 'E';
                $this->assertNotNull($k, "未分類 {$u->email}");
                $has[$k] = true;
            }
            foreach ($has as $k => $v) $this->assertTrue($v, "{$role} に状態 {$k} が無い");
        }
        foreach (DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->get() as $e) {
            $u = DB::table('users')->where('email', $e->email)->first();
            if ($e->suspended_at) {
                $this->assertSame(0, (int) $u->active);
                $this->assertNotNull($e->consent_at);
                $this->assertNotNull($e->self_confirmed_at);
            }
            if (!$u->active) $this->assertNotNull($e->suspended_at);
        }
        $this->assertGreaterThan(0, DB::table('engineers')->whereNotNull('suspended_at')->count());
    }

    public function test_t4_emails_names_skillterms(): void
    {
        $terms = DB::table('skill_terms')->count();
        $this->go();
        foreach (DB::table('users')->where('email', 'like', '%-d%@park.example')->get() as $u) {
            $this->assertMatchesRegularExpression('/^(ses-d\d{2,}(-\d+)?|eg-d\d{3,})@park\.example$/', $u->email);
            foreach (['name_sei', 'name_mei', 'kana_sei', 'kana_mei'] as $c) $this->assertNotEmpty($u->$c, "{$u->email} {$c}");
            $this->assertSame($u->name_sei.' '.$u->name_mei, $u->name);
        }
        foreach (DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->get() as $e) {
            foreach (['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'initials'] as $c) $this->assertNotEmpty($e->$c, "{$e->email} {$c}");
            $this->assertSame($e->name_sei.' '.$e->name_mei, $e->name);
            $this->assertMatchesRegularExpression('/^eg-d\d{3,}@park\.example$/', $e->email);
        }
        foreach (DB::table('ses_companies')->where('email', 'like', 'ses-d%@park.example')->get() as $c) {
            $this->assertMatchesRegularExpression('/^ses-d\d{2,}@park\.example$/', $c->email);
            $this->assertStringEndsWith('（ダミー）', $c->name);
        }
        $this->assertSame($terms, DB::table('skill_terms')->count());
    }

    public function test_t5_no_mail_no_notice(): void
    {
        $bell = DB::table('app_notifications')->count();
        $mail = DB::table('mail_outbox')->count();
        $audit = (int) DB::table('audit_log')->max('id');
        $this->go();
        $this->assertSame($mail, DB::table('mail_outbox')->count());
        Mail::assertNothingOutgoing();
        $this->assertSame($bell, DB::table('app_notifications')->count());
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->adminId)->count());
        $new = DB::table('audit_log')->where('id', '>', $audit)->get();
        $this->assertCount(1, $new);
        $this->assertSame('seed-dummy', $new[0]->action);
        $this->assertStringNotContainsString('@', (string) $new[0]->detail);
    }

    public function test_t6_purge_removes_all(): void
    {
        $realUsers = DB::table('users')->where('email', 'not like', '%@park.example')->count();
        $this->go();
        $this->artisan('park:purge-samples', ['--yes' => true])->assertExitCode(0);
        foreach (['users', 'ses_companies', 'engineers'] as $t) $this->assertSame(0, DB::table($t)->where('email', 'like', '%@park.example')->count(), $t);
        $this->assertSame(0, DB::table('engineer_skills')->whereNotIn('engineer_id', DB::table('engineers')->select('id'))->count());
        $this->assertSame(0, DB::table('users')->where('email', 'like', '%-d%@park.example')->count());
        $this->assertSame(1, DB::table('users')->where('email', 'real-admin@example.com')->count());
        $this->assertSame($realUsers, DB::table('users')->count());
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'ses')->count());
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'like', '%@park.example')->count());
    }

    public function test_t7_production_env_works_and_sends_no_mail(): void
    {
        $this->app['env'] = 'production';
        $before = $this->counts();
        [$c] = $this->go(self::SMALL, false);
        $this->assertSame(0, $c);
        $this->assertSame($before, $this->counts());
        $mail = DB::table('mail_outbox')->count();
        [$c, $out] = $this->go();
        $this->assertSame(0, $c, $out);
        $this->assertStringContainsString('production', $out);
        $this->assertSame(12, DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->count());
        $this->assertSame($mail, DB::table('mail_outbox')->count());
        Mail::assertNothingOutgoing();
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_t8_second_run_continues_numbers(): void
    {
        $this->go();
        [$c, $out] = $this->go();
        $this->assertSame(0, $c, $out);
        $emails = DB::table('users')->where('email', 'like', '%-d%@park.example')->pluck('email')->all();
        $this->assertSame(count($emails), count(array_unique($emails)));
        // 担当者は、指定の8人×2回に、仮登録の会社の代表者（1回に1人）を足した数
        $this->assertSame(['ses' => 10, 'staff' => 16 + 2, 'eg' => 24, 'egu' => 24], $this->dummyCounts());
        foreach (['ses_companies' => ['ses-d06', 'ses-d10'], 'engineers' => ['eg-d013', 'eg-d024']] as $t => $list) {
            foreach ($list as $l) $this->assertSame(1, DB::table($t)->where('email', "$l@park.example")->count(), $l);
        }
    }

    public function test_t9_same_seed_same_content(): void
    {
        $this->go(['--seed' => 42] + self::SMALL);
        $a = $this->snapshot();
        $this->assertNotEmpty($a[0]);
        $this->artisan('park:purge-samples', ['--yes' => true])->assertExitCode(0);
        $this->go(['--seed' => 42] + self::SMALL);
        $this->assertSame($a, $this->snapshot());
    }

    public function test_t10_validation(): void
    {
        $before = $this->counts();
        foreach ([['--ses' => 0], ['--eg' => 501], ['--ses' => 51], ['--staff' => 201], ['--ses' => 8, '--staff' => 3], ['--eg' => 5, '--direct' => 6], ['--eg' => 'abc']] as $o) {
            foreach ([false, true] as $yes) {
                [$c] = $this->go($o, $yes);
                $this->assertSame(1, $c, json_encode($o).' yes='.(int) $yes);
                $this->assertSame($before, $this->counts(), json_encode($o));
            }
        }
    }

    public function test_t11_output_has_no_personal_info(): void
    {
        [$c, $out] = $this->go();
        $this->assertSame(0, $c);
        $this->assertStringNotContainsString('@', $out);
        $this->assertStringNotContainsString('park.example', $out);
        $sei = array_unique(array_merge(
            DB::table('users')->where('email', 'like', '%-d%@park.example')->pluck('name_sei')->all(),
            DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->pluck('name_sei')->all()));
        $this->assertNotEmpty($sei);
        foreach ($sei as $s) $this->assertStringNotContainsString($s, $out, $s);
        foreach (DB::table('ses_companies')->where('email', 'like', 'ses-d%')->pluck('name') as $n) $this->assertStringNotContainsString($n, $out);
        $this->assertStringNotContainsString('token', strtolower($out));
        [, $out2] = $this->go(['--seed' => 7] + self::SMALL, false);
        $this->assertStringNotContainsString('@', $out2);
    }

    public function test_regress_printed_seed_matches_audit_seed_when_omitted(): void
    {
        [$c, $out] = $this->go(['--ses' => 5, '--staff' => 8, '--eg' => 12, '--direct' => 5]);
        $this->assertSame(0, $c, $out);
        $this->assertSame(1, preg_match('/seed：(\d+)/u', $out, $m), $out);
        $row = DB::table('audit_log')->where('action', 'seed-dummy')->first();
        $this->assertNotNull($row);
        $this->assertSame((int) $m[1], (int) json_decode($row->detail, true)['seed']);
        $this->assertGreaterThan(0, (int) $m[1]);
    }

    public function test_regress_real_admin_engineer_create_audit_survives(): void
    {
        $admin = User::find($this->adminId);
        W::createEngineer(W::directSesId(), ['name_sei' => '実在', 'name_mei' => '太郎', 'kana_sei' => 'じつざい', 'kana_mei' => 'たろう', 'email' => 'real-eng@example.com'], $admin, false);
        $this->go();
        $sesId = DB::table('ses_companies')->where('email', 'ses-d01@park.example')->value('id');
        W::createEngineer($sesId, ['name_sei' => '実在', 'name_mei' => '花子', 'kana_sei' => 'じつざい', 'kana_mei' => 'はなこ', 'email' => 'real-eng2@example.com'], $admin, false);
        $this->assertSame(2, DB::table('audit_log')->where('action', 'engineer-create')->where('user_id', $this->adminId)->count());
        // 本物の記録がある状態で、もう一度ダミーを入れても（同じ直属の入れ物・同じ SES を使っても）消えない
        $this->go();
        $this->assertSame(2, DB::table('audit_log')->where('action', 'engineer-create')->where('user_id', $this->adminId)->count());
        // ダミーを入れたときの user_id が null の engineer-create は、ダミーの分は残っていない
        $dummyNames = DB::table('engineers')->where('email', 'like', 'eg-d%@park.example')->pluck('name')->all();
        foreach (DB::table('audit_log')->where('action', 'engineer-create')->whereNull('user_id')->pluck('detail') as $d) {
            foreach ($dummyNames as $n) $this->assertStringNotContainsString($n.'（', $d);
        }
    }

    public function test_failure_leaks_nothing_and_changes_nothing(): void
    {
        // 途中（エンジニアのスキル保存）で DB が失敗する状況を作る
        DB::unprepared("CREATE TRIGGER boom BEFORE INSERT ON engineer_skills BEGIN SELECT RAISE(ABORT, 'boom'); END");
        $before = $this->counts();
        [$c, $out] = $this->go();
        $this->assertSame(1, $c, $out);
        $this->assertSame($before, $this->counts());
        $this->assertSame(0, DB::table('users')->where('email', 'like', '%-d%@park.example')->count());
        $this->assertStringNotContainsString('@', $out);
        $this->assertStringNotContainsString('park.example', $out);
        $this->assertStringNotContainsString('boom', $out);
        $this->assertStringNotContainsString('INSERT', $out);
        foreach (['佐藤', '鈴木', '高橋', '田中', 'ダミー'] as $w) $this->assertStringNotContainsString($w, $out);
    }
}
