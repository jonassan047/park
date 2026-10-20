<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 管理者・SES 担当者の登録と編集に、ふりがな（姓・名）の欄がある
class KanaFieldsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): static
    {
        $u = User::where('email', 'admin@park.example')->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    public function test_admin_new_and_edit_have_kana(): void
    {
        $this->admin()->get('/admin/admins/new')->assertOk()->assertSee('ふりがな（姓）')->assertSee('ふりがな（名）');
        $this->post('/admin/admins', ['name_sei' => '山田', 'name_mei' => '太郎', 'kana_sei' => 'やまだ', 'kana_mei' => 'たろう', 'company_name' => 'テスト社', 'email' => 'kana-admin@park.example']);
        $u = DB::table('users')->where('email', 'kana-admin@park.example')->first();
        $this->assertSame(['やまだ', 'たろう', 'やまだ たろう'], [$u->kana_sei, $u->kana_mei, $u->kana]);
        $this->get("/admin/admins/{$u->id}/edit")->assertOk()->assertSee('value="やまだ"', false);
        $this->post("/admin/admins/{$u->id}", ['name_sei' => '山田', 'name_mei' => '太郎', 'kana_sei' => 'やまた', 'kana_mei' => 'たろう', 'company_name' => 'テスト社']);
        $this->assertSame('やまた たろう', DB::table('users')->where('id', $u->id)->value('kana'));
        $this->get("/admin/admins/{$u->id}")->assertSee('やまた たろう');
    }

    public function test_ses_contact_new_has_kana(): void
    {
        $sid = (int) DB::table('ses_companies')->where('kind', 'ses')->value('id');
        $this->admin()->get("/admin/ses/$sid/users/new")->assertOk()->assertSee('ふりがな（姓）');
        $this->post("/admin/ses/$sid/users", ['name_sei' => '佐藤', 'name_mei' => '花子', 'kana_sei' => 'さとう', 'kana_mei' => 'はなこ', 'email' => 'kana-ses@park.example']);
        $this->assertSame('さとう はなこ', DB::table('users')->where('email', 'kana-ses@park.example')->value('kana'));
    }
}
