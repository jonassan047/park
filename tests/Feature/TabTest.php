<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// タブの「選択中」（PLAN 2026-10-19 の R）
class TabTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    // 上のメニュー（最初の nav）だけを取り出す
    private function nav(string $html): string
    {
        preg_match('#<nav>.*?</nav>#us', $html, $m);
        return $m[0] ?? '';
    }

    private function on(string $path): string
    {
        return 'href="'.url($path).'" class="on"';
    }

    public function test_r1_admin_tabs(): void
    {
        $this->as('admin@park.example')->get('/admin/ses')->assertOk()
            ->assertSee($this->on('/admin/ses'), false)->assertDontSee($this->on('/admin'), false);
        $this->get('/admin')->assertOk()
            ->assertSee($this->on('/admin'), false)->assertDontSee($this->on('/admin/ses'), false);
        // 配下の画面（/admin/ses/1）でもダッシュボードは選択中にならず、SES が選択中
        $this->get('/admin/ses/1')->assertOk()->assertSee($this->on('/admin/ses'), false)->assertDontSee($this->on('/admin'), false);
    }

    public function test_r1_admin_only_one_tab_is_on(): void
    {
        $html = $this->as('admin@park.example')->get('/admin/cases')->assertOk()->getContent();
        $this->assertSame(1, substr_count($this->nav($html), 'class="on"'));
        $this->assertStringContainsString($this->on('/admin/cases'), $html);
    }

    public function test_r2_ses_tabs(): void
    {
        $this->as('ses1@park.example')->get('/ses/engineers')->assertOk()
            ->assertSee($this->on('/ses/engineers'), false)->assertDontSee($this->on('/ses'), false);
        $this->get('/ses')->assertOk()
            ->assertSee($this->on('/ses'), false)->assertDontSee($this->on('/ses/engineers'), false);
        $html = $this->get('/ses/engineers')->getContent();
        $this->assertSame(1, substr_count($this->nav($html), 'class="on"'));
    }

    public function test_r3_engineer_tabs(): void
    {
        $this->as('eg1@park.example')->get('/eg/offers')->assertOk()
            ->assertSee($this->on('/eg/offers'), false)->assertDontSee($this->on('/eg'), false);
        $this->get('/eg')->assertOk()
            ->assertSee($this->on('/eg'), false)->assertDontSee($this->on('/eg/offers'), false);
        $html = $this->get('/eg/offers')->getContent();
        $this->assertSame(1, substr_count($this->nav($html), 'class="on"'));
    }
}
