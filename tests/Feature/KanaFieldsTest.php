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

    // 管理者の仮登録は 企業名・氏名・メールの4つだけで、ふりがなの欄は無い（本登録のあとの編集で入れる）
    public function test_admin_new_has_no_kana_and_edit_has_kana(): void
    {
        $this->admin()->get('/admin/admins/new')->assertOk()->assertDontSee('ふりがな');
        $this->post('/admin/admins', ['name_sei' => '山田', 'name_mei' => '太郎', 'kana_sei' => 'やまだ', 'kana_mei' => 'たろう', 'company_name' => 'テスト社', 'email' => 'kana-admin@park.example']);
        $u = DB::table('users')->where('email', 'kana-admin@park.example')->first();
        $this->assertNull($u->kana_sei); // 送られても入れない
        $this->get("/admin/admins/{$u->id}/edit")->assertOk()->assertDontSee('ふりがな');
        // 本登録が済んだあとは、編集でふりがなを入れられる
        DB::table('users')->where('id', $u->id)->update(['password' => bcrypt('park1234'), 'registered_at' => now_str(), 'phone' => '03-0000-0001', 'kana_sei' => 'やまだ', 'kana_mei' => 'たろう', 'kana' => 'やまだ たろう']);
        $this->get("/admin/admins/{$u->id}/edit")->assertOk()->assertSee('value="やまだ"', false);
        $this->post("/admin/admins/{$u->id}", ['name_sei' => '山田', 'name_mei' => '太郎', 'kana_sei' => 'やまた', 'kana_mei' => 'たろう', 'company_name' => 'テスト社']);
        $this->assertSame('やまた たろう', DB::table('users')->where('id', $u->id)->value('kana'));
        $this->get("/admin/admins/{$u->id}")->assertSee('やまた たろう');
    }

    // SES の仮登録は4つの項目だけで、ふりがなの欄は無い（ふりがなは、代表者が本登録のときに任意で入れる）
    public function test_ses_provisional_form_has_no_kana(): void
    {
        $this->admin()->get('/admin/ses/new')->assertOk()->assertDontSee('ふりがな');
        $this->post('/admin/ses', ['name' => 'かな社', 'rep_sei' => '田中', 'rep_mei' => '一郎', 'contact_kana_sei' => 'たなか', 'contact_kana_mei' => 'いちろう', 'email' => 'kana-co@park.example'])
            ->assertSessionHas('flash.type', 'ok');
        $s = DB::table('ses_companies')->where('name', 'かな社')->first();
        $this->assertNull($s->contact_kana_sei); // 送られても入れない
        $this->assertNull(DB::table('users')->where('email', 'kana-co@park.example')->value('kana_sei'));
    }

    // 申込中の会社の編集（申し込みの内容）には、担当者のふりがなの欄が残っている
    public function test_ses_pending_company_form_keeps_contact_kana(): void
    {
        $sid = (int) DB::table('ses_companies')->where('status', 'pending')->value('id');
        $this->admin()->get("/admin/ses/$sid/edit")->assertOk()->assertSee('担当者のふりがな（姓）');
        $this->post("/admin/ses/$sid", ['name' => 'かな社', 'contact_sei' => '田中', 'contact_mei' => '一郎', 'contact_kana_sei' => 'たなか', 'contact_kana_mei' => 'いちろう', 'email' => 'kana-co@park.example']);
        $s = DB::table('ses_companies')->find($sid);
        $this->assertSame(['たなか', 'いちろう'], [$s->contact_kana_sei, $s->contact_kana_mei]);
        $this->get("/admin/ses/$sid/edit")->assertOk()->assertSee('value="たなか"', false);
    }

    // 担当者の仮登録は 姓・名・メール の3つだけ（台帳 K12・K15）。ふりがなは、担当者が本登録のときに任意で入れる
    public function test_ses_contact_provisional_form_has_no_kana(): void
    {
        $sid = (int) DB::table('ses_companies')->where('kind', 'ses')->value('id');
        $this->admin()->get("/admin/ses/$sid/users/new")->assertOk()->assertSee('氏名（姓）')->assertDontSee('ふりがな');
        $this->post("/admin/ses/$sid/users", ['name_sei' => '佐藤', 'name_mei' => '花子', 'kana_sei' => 'さとう', 'kana_mei' => 'はなこ', 'email' => 'kana-ses@park.example']);
        $u = DB::table('users')->where('email', 'kana-ses@park.example')->first();
        $this->assertSame('佐藤 花子', $u->name);
        $this->assertNull($u->kana_sei); // 送られても入れない
        $this->assertNull($u->kana);
        // 本登録の画面には、ふりがなの欄（任意）がある
        $this->flushSession();
        $this->get('/invite/'.$u->token)->assertOk()->assertSee('ふりがな（姓）');
    }
}
