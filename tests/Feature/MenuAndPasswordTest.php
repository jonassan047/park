<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MenuAndPasswordTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    public function test_password_button_and_menu_selection(): void
    {
        $this->as('ses1@park.example')->get('/ses/company')->assertOk()->assertSee('パスワード変更');
        $this->get('/account/profile')->assertOk()->assertSee('/account#password', false);
        $this->as('admin@park.example')->get('/engineers/1')->assertOk()->assertSee('<a href="'.url('/admin/engineers').'" class="on">', false);
    }
}
