<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 登録情報を見るだけの画面（PLAN 2026-10-19 の N）
class AccountProfileTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    // 入力欄が無い（ログアウトの form の hidden だけは layout にあるので除く）
    private function assertNoFormFields(string $html): void
    {
        $this->assertDoesNotMatchRegularExpression('/<input(?![^>]*type="hidden")/u', $html);
        $this->assertStringNotContainsString('<textarea', $html);
        $this->assertStringNotContainsString('<select', $html);
        $this->assertStringNotContainsString('name="name_sei"', $html);
    }

    public function test_n1_n2_each_role_sees_own_info_without_inputs(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['company_name' => '見本運営株式会社', 'name_sei' => '管理', 'name_mei' => '花子', 'kana_sei' => 'かんり', 'kana_mei' => 'はなこ', 'phone' => '03-0000-0001']);
        $r = $this->as('admin@park.example')->get('/account/profile')->assertOk();
        $r->assertSee('登録情報')->assertSee('管理')->assertSee('花子')->assertSee('かんり')->assertSee('見本運営株式会社')->assertSee('admin@park.example')->assertSee('管理者')->assertSee('03-0000-0001');
        $r->assertSeeInOrder(['氏名', '管理 花子', 'ふりがな', 'かんり はなこ', '企業名', '所属・役職', '電話', 'メールアドレス', '立場', 'メールでの通知', '登録した日', '最後のログイン']);
        $this->assertNoFormFields($r->getContent());
        $r->assertDontSee('SES名');

        $r = $this->as('ses1@park.example')->get('/account/profile')->assertOk();
        $r->assertSee('SES名')->assertSee('みどり情報システム')->assertSee('ses1@park.example')->assertDontSee('企業名')->assertSee('所属・役職');
        $this->assertNoFormFields($r->getContent());

        $r = $this->as('eg1@park.example')->get('/account/profile')->assertOk();
        $r->assertSee('eg1@park.example')->assertSee('エンジニア')->assertDontSee('所属・役職')->assertDontSee('企業名')->assertDontSee('SES名');
        $this->assertNoFormFields($r->getContent());
    }

    public function test_n2_empty_fields_show_dash_and_notice_setting(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['phone' => null, 'position' => null, 'company_name' => null, 'last_login' => null, 'mail_opt_in' => 0]);
        $html = $this->as('admin@park.example')->get('/account/profile')->assertOk()->assertSee('受け取らない')->getContent();
        $this->assertGreaterThanOrEqual(3, substr_count($html, '<dd>—</dd>'));
        DB::table('users')->where('email', 'admin@park.example')->update(['mail_opt_in' => 1]);
        $this->as('admin@park.example')->get('/account/profile')->assertSee('受け取る');
    }

    public function test_n2_one_word_name_shows_dash_for_mei(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['name' => '管理者', 'name_sei' => '管理者', 'name_mei' => null]);
        $html = $this->as('admin@park.example')->get('/account/profile')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('#氏名（名）</dt><dd>—</dd>#u', $html);
    }

    public function test_n3_role_links(): void
    {
        $this->as('ses1@park.example')->get('/account/profile')->assertSee('会社情報を見る')->assertSee('href="'.url('/ses/company').'"', false);
        $eid = User::where('email', 'eg1@park.example')->value('engineer_id');
        $this->as('eg1@park.example')->get('/account/profile')->assertSee('プロフィール・経験票を見る')->assertSee('href="'.url("/engineers/$eid").'"', false);
        $this->as('admin@park.example')->get('/account/profile')->assertDontSee('会社情報を見る')->assertDontSee('プロフィール・経験票を見る');
    }

    public function test_n4_links_to_edit_and_back(): void
    {
        foreach (['admin@park.example', 'ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/account/profile')->assertSee('編集する')->assertSee('href="'.url('/account').'"', false);
            $this->get('/account')->assertOk()->assertSee('登録情報を見る')->assertSee('href="'.url('/account/profile').'"', false);
        }
        // 「登録情報を見る」は「基本」の枠の先頭（入力欄の上）
        $this->as('admin@park.example')->get('/account')->assertSeeInOrder(['基本', '登録情報を見る', '氏名（姓）']);
    }

    public function test_n5_guest_goes_to_login(): void
    {
        $this->get('/account/profile')->assertRedirect(url('/login'));
    }

    public function test_n5_no_id_in_url_other_ids_are_not_routes(): void
    {
        // 自分の分だけ。URL に id を付けても他人の画面にはならない
        $this->as('admin@park.example')->get('/account/profile/2')->assertNotFound();
        $this->get('/account/profile?id=2')->assertOk()->assertSee('admin@park.example')->assertDontSee('ses1@park.example');
    }

    public function test_n5_unregistered_person_sees_notice(): void
    {
        DB::table('users')->whereIn('email', ['admin@park.example', 'ses1@park.example', 'eg1@park.example'])->update(['profile_completed_at' => null]);
        foreach (['admin@park.example', 'ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/account/profile')->assertOk()->assertSee('まだ登録していません')->assertSee('href="'.url('/welcome').'"', false)->assertDontSee($email);
        }
    }

    public function test_n_registered_date_is_shown(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['profile_completed_at' => '2026-03-04 05:06:07']);
        $this->as('admin@park.example')->get('/account/profile')->assertSee('2026-03-04', false);
    }
}
