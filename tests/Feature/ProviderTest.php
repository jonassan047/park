<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// プロバイダー（案件を SES に提供してくれる上位者）：管理者だけ。エンジニアの隣のタブ。必須はメール・企業名・氏名（姓）
class ProviderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function in(array $over = []): array
    {
        return $over + ['name' => '架空プロバイダー株式会社', 'sei' => '架空', 'mei' => '太郎', 'email' => 'Prov1@Example.test', 'phone' => '03-0000-0000',
            'postal_code' => '１２３-４５６７', 'address' => '東京都架空区1-1', 'employees' => '30', 'strengths' => 'Java', 'notes' => 'メモ'];
    }

    public function test_tab_sits_next_to_engineers_for_admin(): void
    {
        $this->as('admin@park.example')->get('/admin/providers')->assertOk()
            ->assertSeeInOrder(['href="'.url('/admin/engineers').'"', 'href="'.url('/admin/providers').'"', 'href="'.url('/admin/cases').'"'], false);
    }

    public function test_admin_can_register_and_edit_and_delete(): void
    {
        $this->as('admin@park.example')->post('/admin/providers', $this->in())->assertSessionHas('flash.type', 'ok');
        $p = DB::table('providers')->first();
        $this->assertSame('prov1@example.test', $p->email);
        $this->assertSame('123-4567', $p->postal_code);
        $this->as('admin@park.example')->get('/admin/providers')->assertSee('架空プロバイダー株式会社');
        $this->as('admin@park.example')->get("/admin/providers/{$p->id}")->assertOk();
        $this->as('admin@park.example')->get("/admin/providers/{$p->id}/edit")->assertOk();
        $this->as('admin@park.example')->post("/admin/providers/{$p->id}", $this->in(['name' => '改名株式会社']))->assertSessionHas('flash.type', 'ok');
        $this->assertSame('改名株式会社', DB::table('providers')->where('id', $p->id)->value('name'));
        $this->as('admin@park.example')->post("/admin/providers/{$p->id}/delete")->assertSessionHas('flash.type', 'ok');
        $this->assertSame(0, DB::table('providers')->count());
    }

    public function test_required_fields(): void
    {
        foreach (['email', 'name', 'sei'] as $k) {
            $this->as('admin@park.example')->post('/admin/providers', $this->in([$k => '']))->assertSessionHas('flash.type', 'bad');
        }
        $this->as('admin@park.example')->post('/admin/providers', $this->in(['email' => 'not-mail']))->assertSessionHas('flash.type', 'bad');
        $this->assertSame(0, DB::table('providers')->count());
        // 名・電話・住所などは空でよい
        $this->as('admin@park.example')->post('/admin/providers', ['name' => '最小', 'sei' => '最小', 'email' => 'min@example.test'])->assertSessionHas('flash.type', 'ok');
    }

    public function test_ses_and_engineer_cannot_see_it(): void
    {
        $id = DB::table('providers')->insertGetId(['name' => 'P', 'sei' => 'P', 'email' => 'p@example.test', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/admin/providers')->assertForbidden();
            $this->as($email)->get("/admin/providers/$id")->assertForbidden();
            $this->as($email)->post('/admin/providers', $this->in())->assertForbidden();
            $this->as($email)->post("/admin/providers/$id/delete")->assertForbidden();
        }
        $this->assertSame(1, DB::table('providers')->count());
        $this->as('eg1@park.example')->get('/eg')->assertDontSee('プロバイダー');
        $this->as('ses1@park.example')->get('/ses')->assertDontSee('プロバイダー');
    }
}
