<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 段階 1 のレビュー指摘（M1〜M4・L10）の回帰テスト。アドレスはすべて example.com
class Step1ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function admin(): User
    {
        return User::where('email', 'admin@park.example')->first();
    }

    // 見本の SES 1 の、本登録後の編集で必須の欄がそろった入力
    private function company(array $over = []): array
    {
        $s = DB::table('ses_companies')->find(1);
        return $over + ['name' => $s->name, 'rep_sei' => '緑川', 'rep_mei' => '一郎', 'phone' => $s->phone, 'postal_code' => $s->postal_code, 'address' => $s->address];
    }

    // M1：長さ・形がだめな入力は、代表者の画面でも管理者の画面でも断り、何も変えない
    public function test_m1_company_edit_checks_length_and_format(): void
    {
        $before = (array) DB::table('ses_companies')->find(1);
        $beforeUser = (array) DB::table('users')->where('email', 'ses1@park.example')->first();
        $bad = [['rep_sei' => str_repeat('あ', 51)], ['rep_mei' => str_repeat('あ', 51)], ['name' => str_repeat('あ', 101)], ['phone' => str_repeat('1', 31)], ['phone' => 'abc']];
        foreach ($bad as $over) {
            $this->as('ses1@park.example')->post('/ses/company', $this->company($over))->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
            $this->as('admin@park.example')->post('/admin/ses/1', $this->company($over))->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        }
        $this->assertEquals($before, (array) DB::table('ses_companies')->find(1));
        $this->assertEquals($beforeUser, (array) DB::table('users')->where('email', 'ses1@park.example')->first());
        // ちょうど上限は通る
        $this->as('ses1@park.example')->post('/ses/company', $this->company(['rep_sei' => str_repeat('あ', 50), 'phone' => '03-1111-2222']))->assertRedirect('/ses/company');
        $this->assertSame(str_repeat('あ', 50), DB::table('ses_companies')->find(1)->rep_sei);
    }

    // M2：担当者を登録できるのは参加中の SES だけ（画面のボタンも出さない）
    public function test_m2_staff_registration_only_for_active_companies(): void
    {
        $id = DB::table('ses_companies')->insertGetId(['name' => '仮登録確認商事', 'kind' => 'ses', 'status' => 'provisional', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->as('admin@park.example');
        $users = DB::table('users')->count();
        $this->get("/admin/ses/$id/users/new")->assertRedirect("/admin/ses/$id");
        $this->post("/admin/ses/$id/users", ['name_sei' => '追加', 'name_mei' => '太郎', 'email' => 'm2-staff@example.com'])->assertRedirect("/admin/ses/$id")
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        $this->assertSame($users, DB::table('users')->count());
        $this->get("/admin/ses/$id")->assertOk()->assertDontSee("/admin/ses/$id/users/new", false);
        $this->get('/admin/ses/1')->assertOk()->assertSee('/admin/ses/1/users/new', false);
        $this->get('/admin/ses/1/users/new')->assertOk();
        DB::table('ses_companies')->where('id', 1)->update(['status' => 'suspended']);
        $this->get('/admin/ses/1/users/new')->assertRedirect('/admin/ses/1');
    }

    // M3：直属のエンジニアの送り直し・取り消しは、404 になる SES の詳細ではなく、エンジニアの一覧へ戻る
    public function test_m3_direct_engineer_people_actions_return_to_engineer_list(): void
    {
        $admin = $this->admin();
        $direct = W::directSesId();
        W::createEngineer($direct, ['name' => '直属 仮一', 'email' => 'm3-a@example.com'], $admin);
        W::createEngineer($direct, ['name' => '直属 仮二', 'email' => 'm3-b@example.com'], $admin);
        $a = (int) DB::table('users')->where('email', 'm3-a@example.com')->value('id');
        $b = (int) DB::table('users')->where('email', 'm3-b@example.com')->value('id');
        $this->as('admin@park.example');
        $this->post("/people/$a/resend")->assertRedirect('/admin/engineers');
        $this->post("/people/$b/cancel")->assertRedirect('/admin/engineers');
        // 直属でない SES のエンジニアは、今までどおり SES の詳細へ
        W::createEngineer(1, ['name' => '自社 仮三', 'email' => 'm3-c@example.com'], $admin);
        $c = (int) DB::table('users')->where('email', 'm3-c@example.com')->value('id');
        $this->post("/people/$c/resend")->assertRedirect('/admin/ses/1');
    }

    // M4：アカウントの保存で、送られなかったふりがな・所属／役職を空で上書きしない
    public function test_m4_account_save_keeps_fields_not_sent(): void
    {
        DB::table('users')->where('email', 'ses1@park.example')->update(['kana_sei' => 'みどり', 'kana_mei' => 'いちろう', 'kana' => 'みどり いちろう', 'position' => '課長']);
        $this->as('ses1@park.example')->post('/account', ['name_sei' => '緑川', 'name_mei' => '一郎'])->assertRedirect('/account');
        $u = DB::table('users')->where('email', 'ses1@park.example')->first();
        $this->assertSame(['みどり', 'いちろう', '課長'], [$u->kana_sei, $u->kana_mei, $u->position]);
        // 送られて空のときは、空にできる（任意の項目）
        $this->post('/account', ['name_sei' => '緑川', 'name_mei' => '一郎', 'kana_sei' => '', 'kana_mei' => '', 'position' => ''])->assertRedirect('/account');
        $u = DB::table('users')->where('email', 'ses1@park.example')->first();
        $this->assertSame([null, null, null], [$u->kana_sei, $u->kana_mei, $u->position]);
    }

    // L10：配信は、所属の会社が参加中のエンジニアだけ。直属（入れ物は active）は今までどおり
    public function test_l10_distribute_requires_active_company_and_keeps_direct(): void
    {
        $admin = $this->admin();
        $caseId = (int) DB::table('cases')->where('status', 'open')->orderBy('id')->value('id');
        $eg = (int) User::where('email', 'eg2@park.example')->value('engineer_id'); // SES 2 のエンジニア
        DB::table('ses_companies')->where('id', 2)->update(['status' => 'suspended']);
        $this->assertSame(0, W::distribute($caseId, [$eg], $admin));
        $this->assertFalse(DB::table('case_offers')->where('case_id', $caseId)->where('engineer_id', $eg)->exists());
        DB::table('ses_companies')->where('id', 2)->update(['status' => 'active']);
        $this->assertSame(1, W::distribute($caseId, [$eg], $admin));
        $d = W::createEngineer(W::directSesId(), ['name' => '直属 配信', 'email' => null], $admin, false);
        DB::table('engineers')->where('id', $d)->update(['registered_at' => now_str()]);
        $this->assertSame(1, W::distribute($caseId, [$d], $admin));
    }
}
