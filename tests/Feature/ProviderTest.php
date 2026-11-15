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

    protected bool $autoProvider = false; // 必須の確かめをするので、勝手に足さない

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
        $this->assertSame('PV-000001', $p->code);
        $this->as('admin@park.example')->post('/admin/providers', $this->in(['email' => 'prov2@example.test']));
        $this->assertSame('PV-000002', DB::table('providers')->orderByDesc('id')->value('code'));
        DB::table('providers')->where('code', 'PV-000002')->delete();
        $this->as('admin@park.example')->get('/find?code=pv1')->assertRedirect(url('/admin/providers/'.$p->id));
        $this->as('ses1@park.example')->get('/find?code=PV-000001')->assertRedirect(); // 開けない（探しても見つからない）
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

    public function test_ses_can_view_and_register_but_not_edit_or_delete(): void
    {
        $id = DB::table('providers')->insertGetId(['name' => 'P', 'sei' => 'P', 'email' => 'p@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->as('ses1@park.example')->get('/ses/providers')->assertOk()->assertSee('P');
        $this->as('ses1@park.example')->get("/ses/providers/$id")->assertOk()->assertDontSee('メモ');
        $this->as('ses1@park.example')->post('/ses/providers', $this->in(['notes' => '秘密のメモ']))->assertSessionHas('flash.type', 'ok');
        $this->assertNull(DB::table('providers')->orderByDesc('id')->value('notes')); // メモは管理者だけ
        $this->as('ses1@park.example')->get('/ses')->assertSee('プロバイダー');
        // 管理者の画面・編集・削除は入れない
        $this->as('ses1@park.example')->get('/admin/providers')->assertForbidden();
        $this->as('ses1@park.example')->get("/admin/providers/$id/edit")->assertForbidden();
        $this->as('ses1@park.example')->post("/admin/providers/$id/delete")->assertForbidden();
        $this->assertSame(2, DB::table('providers')->count());
    }

    public function test_engineer_cannot_see_it(): void
    {
        $id = DB::table('providers')->insertGetId(['name' => 'P', 'sei' => 'P', 'email' => 'p@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->as('eg1@park.example')->get('/admin/providers')->assertForbidden();
        $this->as('eg1@park.example')->get('/ses/providers')->assertForbidden();
        $this->as('eg1@park.example')->post('/ses/providers', $this->in())->assertForbidden();
        $this->as('eg1@park.example')->get('/eg')->assertDontSee('プロバイダー');
    }

    public function test_case_form_requires_provider_on_create_and_edit(): void
    {
        $pid = DB::table('providers')->insertGetId(['name' => '提供元', 'sei' => 'P', 'email' => 'p@example.test', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['admin@park.example', 'ses1@park.example'] as $email) {
            $this->as($email)->get('/cases/new')->assertOk()->assertSeeInOrder(['プロバイダー', '案件名']);
            $this->as($email)->post('/cases', ['title' => '無しの案件'])->assertSessionHas('flash.type', 'bad');
            $this->as($email)->post('/cases', ['title' => '無効の案件', 'provider_id' => '999999'])->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame(0, DB::table('cases')->where('title', 'like', '%の案件')->count());
        $this->as('ses1@park.example')->post('/cases', ['title' => '提供元つき案件', 'provider_id' => $pid])->assertSessionHas('flash.type', 'ok');
        $c = DB::table('cases')->where('title', '提供元つき案件')->first();
        $this->assertSame($pid, (int) $c->provider_id);
        // 編集でも必須
        $this->as('ses1@park.example')->post("/cases/{$c->id}", ['title' => '提供元つき案件'])->assertSessionHas('flash.type', 'bad');
        $this->as('ses1@park.example')->post("/cases/{$c->id}", ['title' => '改題', 'provider_id' => $pid])->assertSessionHas('flash.type', 'ok');
    }
}
