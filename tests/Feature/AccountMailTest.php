<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// アカウントの一覧・詳細に出すメールアドレスと、仮登録の有効期限（account_mail。2026-11-04 tezzo）
class AccountMailTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // 期限切れの仮登録の人が、管理者の一覧で「有効期限切れ」と出る（画面まで通す）
    public function test_admin_list_shows_expired_provisional_engineer(): void
    {
        $eid = (int) DB::table('users')->where('email', 'eg1@park.example')->value('engineer_id');
        DB::table('users')->where('email', 'eg1@park.example')->update([
            'password' => null, 'registered_at' => null, 'token' => 'expiredtoken', 'token_expires' => '2020-01-01 10:00:00',
        ]);
        $admin = User::where('email', 'admin@park.example')->firstOrFail();
        $html = $this->actingAs($admin->fresh())->withSession([TwoFactor::SESSION_KEY => $admin->id])
            ->get('/admin/engineers')->assertOk()->getContent();
        $this->assertStringContainsString('eg1@park.example', $html);
        $this->assertStringContainsString('有効期限切れ（2020-01-01 10:00 まで）', $html);
        $this->assertGreaterThan(0, $eid);
    }

    private function user(array $over = []): object
    {
        return (object) array_merge([
            'email' => 'eg@example.com', 'name' => 'テスト', 'role' => 'eg', 'active' => 1, 'left_at' => null,
            'password' => 'x', 'registered_at' => now_str(), 'token' => null, 'token_expires' => null,
            'reg_declined_at' => null, 'last_login' => null,
        ], $over);
    }

    public function test_no_user_shows_dash(): void
    {
        $this->assertSame('—', account_mail(null));
    }

    public function test_active_user_shows_email_only(): void
    {
        $this->assertSame('eg@example.com', account_mail($this->user()));
    }

    public function test_provisional_with_valid_link_shows_expiry(): void
    {
        $u = $this->user([
            'password' => null, 'registered_at' => null,
            'token' => 'abc', 'token_expires' => date('Y-m-d H:i:s', time() + 3 * 86400),
        ]);
        $s = account_mail($u);
        $this->assertStringContainsString('eg@example.com', $s);
        $this->assertStringContainsString('有効期限：', $s);
        $this->assertStringNotContainsString('有効期限切れ', $s);
    }

    public function test_provisional_with_expired_link_says_expired(): void
    {
        $u = $this->user([
            'password' => null, 'registered_at' => null,
            'token' => 'abc', 'token_expires' => '2020-01-01 10:00:00',
        ]);
        $this->assertSame('eg@example.com／有効期限切れ（2020-01-01 10:00 まで）', account_mail($u));
    }

    public function test_provisional_declined_says_link_is_void(): void
    {
        $u = $this->user([
            'password' => null, 'registered_at' => null, 'token' => null, 'token_expires' => null,
            'reg_declined_at' => now_str(),
        ]);
        $this->assertStringContainsString('リンクは無効', account_mail($u));
    }

    public function test_provisional_without_expiry_record_says_expired(): void
    {
        $u = $this->user(['password' => null, 'registered_at' => null, 'token' => null, 'token_expires' => null]);
        $this->assertStringContainsString('有効期限切れ（期限の記録なし）', account_mail($u));
    }
}
