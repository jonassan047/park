<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// ブラウザにログインの記録（自動ログイン）だけが残っているときの動き
class RememberedLoginTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    public function test_remembered_user_not_confirmed_goes_to_login(): void
    {
        $u = User::where('email', 'eg1@park.example')->firstOrFail();
        $this->actingAs($u)->get('/eg')->assertRedirect(url('/login'));
        $this->assertGuest();
    }

    public function test_remembered_user_confirmed_goes_straight_in(): void
    {
        $u = User::where('email', 'eg1@park.example')->firstOrFail();
        DB::table('users')->where('id', $u->id)->update(['two_factor_confirmed_at' => now()]);
        $this->actingAs($u->fresh())->get('/eg')->assertOk();
    }
}
