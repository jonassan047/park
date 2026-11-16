<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// park:move-ses（SES の付け替えと退会。2026-11-16）。人・会社は見本データだけ
class MoveSesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private array $before = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->before = glob(storage_path('app/backups/move-ses-*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/move-ses-*.json')) ?: [] as $f) if (!in_array($f, $this->before, true)) @unlink($f);
        parent::tearDown();
    }

    private function ids(): array
    {
        $ids = DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('id')->pluck('id')->all();
        $this->assertGreaterThanOrEqual(2, count($ids));
        return [(int) $ids[0], (int) $ids[1]];
    }

    public function test_dry_run_changes_nothing(): void
    {
        [$a, $b] = $this->ids();
        $n = DB::table('engineers')->where('ses_id', $a)->count();
        $this->artisan('park:move-ses', ['from' => $a, 'to' => $b])->assertSuccessful();
        $this->assertSame($n, DB::table('engineers')->where('ses_id', $a)->count());
        $this->assertSame('active', DB::table('ses_companies')->find($a)->status);
    }

    public function test_move_then_restore(): void
    {
        [$a, $b] = $this->ids();
        $engA = DB::table('engineers')->where('ses_id', $a)->count();
        $engB = DB::table('engineers')->where('ses_id', $b)->count();
        $usersA = DB::table('users')->where('ses_id', $a)->count();
        $this->assertGreaterThan(0, $engA + $usersA);
        $this->artisan('park:move-ses', ['from' => $a, 'to' => $b, '--yes' => true])->assertSuccessful();
        $this->assertSame(0, DB::table('engineers')->where('ses_id', $a)->count());
        $this->assertSame(0, DB::table('users')->where('ses_id', $a)->count());
        $this->assertSame($engA + $engB, DB::table('engineers')->where('ses_id', $b)->count());
        $this->assertSame('left', DB::table('ses_companies')->find($a)->status);
        $this->artisan('park:move-ses', ['from' => $a, 'to' => $b, '--restore' => true])->assertSuccessful();
        $this->assertSame($engA, DB::table('engineers')->where('ses_id', $a)->count());
        $this->assertSame($usersA, DB::table('users')->where('ses_id', $a)->count());
        $this->assertSame('active', DB::table('ses_companies')->find($a)->status);
    }

    public function test_refuses_same_or_inactive_target(): void
    {
        [$a, $b] = $this->ids();
        $this->artisan('park:move-ses', ['from' => $a, 'to' => $a, '--yes' => true])->assertFailed();
        DB::table('ses_companies')->where('id', $b)->update(['status' => 'suspended']);
        $this->artisan('park:move-ses', ['from' => $a, 'to' => $b, '--yes' => true])->assertFailed();
        $this->assertSame('active', DB::table('ses_companies')->find($a)->status);
    }

    public function test_refuses_when_active_contract(): void
    {
        [$a, $b] = $this->ids();
        $c = DB::table('contracts')->first();
        if (!$c) $this->markTestSkipped('見本に契約が無い');
        DB::table('contracts')->where('id', $c->id)->update(['ses_id' => $a, 'status' => 'active']);
        $this->artisan('park:move-ses', ['from' => $a, 'to' => $b, '--yes' => true])->assertFailed();
        $this->assertSame('active', DB::table('ses_companies')->find($a)->status);
    }
}
