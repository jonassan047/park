<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// 郵便番号・住所、管理者の一覧→詳細→編集、直属の入口の削除（PLAN 2026-10-20 画面の統一（2）：V1〜V6）
class PostalAddressTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function admin(): static
    {
        return $this->as('admin@park.example');
    }

    private function rows(string $t): int { return DB::table($t)->count(); }

    // 使用中（パスワード設定済み）の別の管理者
    private function otherAdmin(array $extra = []): int
    {
        $id = W::createUser('admin2@park.example', '別 管理者', 'admin', 'park1234');
        $this->completeProfile($id, ['company_name' => '別企業'] + $extra);
        return $id;
    }

    // 招待中の管理者（管理者として登録する）
    private function pendingAdmin(string $email = 'inv-a@park.example'): int
    {
        $this->admin()->post('/admin/admins', ['name_sei' => '招待', 'name_mei' => '一郎', 'company_name' => 'テスト企業', 'email' => $email])->assertSessionHas('flash.type', 'ok');
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    // 今いる直属のエンジニア（画面からは新しく作れないので、直接作る）
    private function directEngineer(): int
    {
        return W::createEngineer(W::directSesId(), ['name' => '直属 一郎', 'email' => 'pa-direct@park.example'], User::where('email', 'admin@park.example')->first());
    }

    // ---------- マイグレーション・関数 ----------

    public function test_columns_exist(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['postal_code', 'address']));
        $this->assertTrue(Schema::hasColumn('ses_companies', 'postal_code'));
    }

    public function test_v4_1_postal_norm(): void
    {
        foreach (['1234567', '123-4567', '〒123-4567', '１２３ー４５６７', ' 123 4567 '] as $in) $this->assertSame('123-4567', postal_norm($in), $in);
        $this->assertNull(postal_norm(''));
        $this->assertNull(postal_norm(null));
        foreach (['123456', '12345678', '12-34567', 'abc-defg'] as $in) $this->assertFalse(postal_norm($in), $in);
    }

    public function test_v4_3_postal_address(): void
    {
        $this->assertSame('〒123-4567 東京都', postal_address('123-4567', '東京都'));
        $this->assertSame('〒146-0082', postal_address('1460082', null));
        $this->assertSame('東京都', postal_address(null, '東京都'));
        $this->assertSame('—', postal_address(null, null));
        $this->assertSame('〒abc', postal_address('abc', null));
    }

    // ---------- V1 直属 ----------

    public function test_v1_direct_entrance_is_gone(): void
    {
        $this->admin()->get('/engineers/new?ses=direct')->assertRedirect(url('/admin/ses'))->assertSessionHas('flash.type', 'warn')
            ->assertSessionHas('flash.msg', 'エンジニアは、SES の画面で SES を選んでから登録します。');
        $e0 = $this->rows('engineers'); $u0 = $this->rows('users'); $m0 = $this->rows('mail_outbox');
        $this->post('/engineers', ['ses_id' => 'direct', 'name_sei' => '直属', 'name_mei' => '太郎', 'email' => 'pa-d1@park.example'])
            ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', '所属と氏名を入れてください。');
        $this->assertSame([$e0, $u0, $m0], [$this->rows('engineers'), $this->rows('users'), $this->rows('mail_outbox')]);
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count());
    }

    public function test_v1_ses_list_has_no_direct_row(): void
    {
        $this->admin()->get('/admin/ses')->assertOk()->assertDontSee('管理者の直属')->assertDontSee('ses=direct');
        $this->directEngineer();
        $this->get('/admin/ses')->assertOk()->assertDontSee('管理者の直属')->assertDontSee('ses=direct');
    }

    public function test_v1_existing_direct_engineer_still_works(): void
    {
        $eid = $this->directEngineer();
        // 今いる直属の人は本登録が済んでいる形にする（仮登録のままだと、上位は技術・住所などを直せない。台帳 K18）
        $uid = (int) DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->value('id');
        DB::table('users')->where('id', $uid)->update(['password' => bcrypt('park1234')]);
        $this->completeProfile($uid);
        $this->admin();
        $this->get('/admin/engineers')->assertOk()->assertSee('直属</span>', false)->assertSee('直属だけ');
        $this->get('/admin/engineers?ses=direct')->assertOk()->assertSee('直属 一郎')->assertDontSee('山田 太郎');
        $this->get("/engineers/$eid")->assertOk();
        $this->get("/engineers/$eid/edit")->assertOk();
        $this->post("/engineers/$eid", ['name_sei' => '直属', 'name_mei' => '二郎'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('直属 二郎', DB::table('engineers')->where('id', $eid)->value('name'));
    }

    // ---------- V2 管理者の一覧 → 詳細 → 編集 ----------

    public function test_v2_list_links_to_detail(): void
    {
        $other = $this->otherAdmin();
        $html = $this->admin()->get('/admin/admins')->assertOk()->assertSee('href="'.url("/admin/admins/$other").'">詳細</a>', false)->getContent();
        $this->assertStringNotContainsString('>修正<', $html);
        $this->assertStringNotContainsString('>直す<', $html);
    }

    public function test_v2_detail_screen_and_roles(): void
    {
        $other = $this->otherAdmin(['postal_code' => '100-0001', 'address' => '東京都千代田区', 'position' => '営業部 部長', 'phone' => '03-0000-0000']);
        $self = (int) DB::table('users')->where('email', 'admin@park.example')->value('id');
        $this->admin()->get("/admin/admins/$other")->assertOk()->assertSee('別 管理者')->assertSee('管理者情報')
            ->assertSee('href="'.url("/admin/admins/$other/edit").'"', false)->assertSee('〒100-0001 東京都千代田区')->assertSee('営業部 部長')->assertSee('参加中')
            ->assertDontSee('送り直し')->assertDontSee('取り消し');
        $this->get("/admin/admins/$self")->assertOk();
        $this->get('/admin/admins/99999')->assertNotFound();
        foreach (['ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get("/admin/admins/$other")->assertForbidden();
            $this->get("/admin/admins/$other/edit")->assertForbidden();
        }
        // 管理者でない人の id は 404
        $ses = (int) DB::table('users')->where('email', 'ses1@park.example')->value('id');
        $this->admin()->get("/admin/admins/$ses")->assertNotFound();
    }

    public function test_v2_detail_pending_shows_resend_and_returns_to_detail(): void
    {
        $id = $this->pendingAdmin();
        $this->admin()->get("/admin/admins/$id")->assertOk()->assertSee('仮登録')->assertSee('送り直し')->assertSee('取り消し');
        $m0 = DB::table('mail_outbox')->where('kind', 'invite')->count();
        $this->from("/admin/admins/$id")->post("/people/$id/resend")->assertRedirect(url("/admin/admins/$id"));
        $this->from('/admin/admins')->post("/people/$id/resend")->assertRedirect(url('/admin/admins'));
        $this->assertSame($m0 + 2, DB::table('mail_outbox')->where('kind', 'invite')->count());
    }

    public function test_v2_edit_screen_and_save(): void
    {
        $other = $this->otherAdmin();
        $this->admin()->get("/admin/admins/$other/edit")->assertOk()->assertSee('管理者の編集：別 管理者')
            ->assertSee('name="position"', false)->assertSee('name="phone"', false)->assertSee('name="postal_code"', false)->assertSee('name="address"', false)
            ->assertSee('href="'.url("/admin/admins/$other").'"', false);
        $this->from("/admin/admins/$other/edit")->post("/admin/admins/$other", ['name_sei' => '別', 'name_mei' => '管理者', 'company_name' => '別企業', 'email' => 'admin2@park.example',
            'position' => '総務部', 'phone' => '03-1111-2222', 'postal_code' => '１５００００１', 'address' => '東京都渋谷区'])
            ->assertRedirect(url("/admin/admins/$other"))->assertSessionHas('flash.type', 'ok');
        $r = DB::table('users')->find($other);
        $this->assertSame(['総務部', '03-1111-2222', '150-0001', '東京都渋谷区'], [$r->position, $r->phone, $r->postal_code, $r->address]);
    }

    public function test_v2_bad_save_returns_to_edit_and_keeps_input(): void
    {
        $other = $this->otherAdmin();
        $this->admin()->from("/admin/admins/$other/edit")->post("/admin/admins/$other", ['name_sei' => '', 'name_mei' => '管理者', 'company_name' => '別企業', 'email' => 'admin2@park.example'])
            ->assertRedirect(url("/admin/admins/$other/edit"))->assertSessionHas('flash.type', 'bad')->assertSessionHasInput('company_name', '別企業');
    }

    public function test_v2_no_change_has_no_audit_and_audit_has_no_contents(): void
    {
        $other = $this->otherAdmin();
        $this->admin();
        $base = ['name_sei' => '別', 'name_mei' => '管理者', 'company_name' => '別企業', 'email' => 'admin2@park.example'];
        // 電話は必須（本登録のあと）なので、今の値のまま送る。任意の欄は空のまま
        $this->post("/admin/admins/$other", $base + ['position' => '', 'phone' => '03-0000-0009', 'postal_code' => '', 'address' => ''])->assertSessionHas('flash.msg', '保存しました。');
        $this->assertSame(0, DB::table('audit_log')->where('action', 'admin-edit')->count());
        $this->post("/admin/admins/$other", $base + ['phone' => '03-9999-8888', 'postal_code' => '1000001', 'address' => '秘密の住所ビル'])->assertSessionHas('flash.type', 'ok');
        $d = (string) DB::table('audit_log')->where('action', 'admin-edit')->value('detail');
        $this->assertStringContainsString('電話番号', $d);
        $this->assertStringContainsString('住所', $d);
        foreach (['03-9999-8888', '100-0001', '秘密の住所ビル'] as $secret) $this->assertStringNotContainsString($secret, $d);
    }

    public function test_v2_no_fix_wording_in_views(): void
    {
        $dir = resource_path('views');
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir));
        foreach ($it as $f) {
            if (!$f->isFile() || !str_ends_with($f->getFilename(), '.blade.php')) continue;
            $t = file_get_contents($f->getPathname());
            $this->assertStringNotContainsString('>修正<', $t, $f->getFilename());
            $this->assertStringNotContainsString('の修正', $t, $f->getFilename());
        }
    }

    // ---------- V3 管理者の項目 ----------

    // 管理者の仮登録は 企業名・氏名・メールの4つだけ。住所などを混ぜても入らない（ご本人が本登録で入れる。S3-01）
    public function test_v3_invite_ignores_extra_fields(): void
    {
        $this->admin()->post('/admin/admins', ['name_sei' => '追加', 'name_mei' => '花子', 'company_name' => '追加企業', 'email' => 'pa-new@park.example',
            'position' => '営業部', 'phone' => '03-1234-5678', 'postal_code' => '１２３４５６７', 'address' => '東京都港区'])->assertSessionHas('flash.type', 'ok');
        $r = DB::table('users')->where('email', 'pa-new@park.example')->first();
        $this->assertSame([null, null, null, null], [$r->position, $r->phone, $r->postal_code, $r->address]);
        // V3-5：メールの本文に住所・郵便番号は書かない
        $body = (string) DB::table('mail_outbox')->where('to_email', 'pa-new@park.example')->value('body');
        $this->assertStringNotContainsString('東京都港区', $body);
        $this->assertStringNotContainsString('123-4567', $body);
    }

    public function test_v3_bad_fields_save_nothing(): void
    {
        $u0 = $this->rows('users'); $m0 = $this->rows('mail_outbox');
        $ok = ['name_sei' => '不正', 'name_mei' => '太郎', 'company_name' => '企業', 'email' => 'pa-bad@park.example'];
        $this->admin();
        foreach ([['company_name' => str_repeat('あ', 101)], ['name_sei' => str_repeat('あ', 51)], ['name_mei' => ''], ['email' => 'abc']] as $over) {
            $this->post('/admin/admins', $over + $ok)->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame([$u0, $m0], [$this->rows('users'), $this->rows('mail_outbox')]);
    }

    public function test_v3_edit_bad_fields_save_nothing(): void
    {
        $other = $this->otherAdmin();
        $before = (array) DB::table('users')->find($other);
        $base = ['name_sei' => '変更姓', 'name_mei' => '管理者', 'company_name' => '別企業', 'email' => 'admin2@park.example'];
        $this->admin();
        foreach ([['postal_code' => '12345'], ['phone' => 'abc'], ['address' => str_repeat('あ', 201)]] as $over) {
            $this->post("/admin/admins/$other", $over + $base)->assertSessionHas('flash.type', 'bad');
            $this->assertEquals($before, (array) DB::table('users')->find($other));
        }
    }

    public function test_v3_welcome_account_and_profile_for_admin(): void
    {
        // 管理者の郵便番号・住所は任意なので、足りない項目の画面には出ない。アカウントの画面で入れる（全角でも「100-0001」にそろう）
        $this->admin()->get('/account')->assertOk()->assertSee('name="postal_code"', false)->assertSee('name="address"', false);
        $this->post('/account', ['name_sei' => '管理', 'name_mei' => '花子', 'company_name' => '社', 'postal_code' => '１００－０００１', 'address' => '東京都千代田区'])->assertRedirect(url('/account'));
        $r = DB::table('users')->where('email', 'admin@park.example')->first();
        $this->assertSame(['100-0001', '東京都千代田区'], [$r->postal_code, $r->address]);
        $this->admin()->get('/account')->assertOk()->assertSee('name="postal_code"', false)->assertSee('value="100-0001"', false);
        $this->get('/account/profile')->assertOk()->assertSee('住所')->assertSee('〒100-0001 東京都千代田区');
        $this->from('/account')->post('/account', ['postal_code' => '12345'])->assertSessionHasErrors('postal_code');
        $this->assertSame('100-0001', DB::table('users')->where('email', 'admin@park.example')->value('postal_code'));
        // SES・エンジニアの画面には住所の欄を出さない
        foreach (['ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/account')->assertOk()->assertDontSee('name="postal_code"', false);
            $this->get('/account/profile')->assertOk()->assertDontSee('住所');
        }
    }

    // ---------- V4 SES・エンジニア ----------

    public function test_v4_5_ses_register_update_company_apply(): void
    {
        $this->admin();
        // 仮登録の画面には、郵便番号・住所の欄はない（本登録で SES が入れる。台帳 K02）
        $this->get('/admin/ses/new')->assertOk()->assertDontSee('name="postal_code"', false);
        $this->post('/admin/ses', ['name' => '郵便商事', 'rep_sei' => '担当', 'rep_mei' => '花', 'email' => 'pa-ses@park.example', 'postal_code' => '1000001', 'address' => '東京都千代田区'])
            ->assertSessionHas('flash.type', 'ok');
        $sid = (int) DB::table('ses_companies')->where('name', '郵便商事')->value('id');
        $this->assertNull(DB::table('ses_companies')->where('id', $sid)->value('postal_code')); // 送られても入らない
        // 詳細の編集（本登録のあとの会社。必須の欄はそろえて送る）
        $sid = 1;
        $base = ['name' => 'みどり情報システム', 'rep_sei' => '緑川', 'rep_mei' => '一郎', 'phone' => '03-0000-0002', 'address' => '東京都千代田区'];
        $this->post("/admin/ses/$sid", $base + ['postal_code' => '99'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame('100-0001', DB::table('ses_companies')->where('id', $sid)->value('postal_code'));
        $this->post("/admin/ses/$sid", ['postal_code' => '1500002', 'address' => str_repeat('あ', 201)] + $base)
            ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', '所在地は200文字までにしてください。');
        $this->post("/admin/ses/$sid", ['postal_code' => '1500002', 'address' => '東京都渋谷区'] + $base)->assertSessionHas('flash.type', 'ok');
        $this->assertSame('150-0002', DB::table('ses_companies')->where('id', $sid)->value('postal_code'));
        // SES の会社情報
        $this->as('ses1@park.example')->get('/ses/company/edit')->assertOk()->assertSee('name="postal_code"', false);
        $this->post('/ses/company', $base + ['postal_code' => '99'])->assertSessionHas('flash.type', 'bad');
        $this->post('/ses/company', ['postal_code' => '1000001', 'address' => '自社ビル'] + $base)->assertSessionHas('flash.type', 'ok');
        $this->assertSame('100-0001', DB::table('ses_companies')->where('id', 1)->value('postal_code'));
        // 公開の申し込み
        auth()->logout();
        $this->flushSession();
        $this->get('/apply')->assertOk()->assertSee('name="postal_code"', false);
        $a0 = $this->rows('ses_companies');
        $this->post('/apply', ['name' => '申込商事', 'contact_name' => '申込 太郎', 'email' => 'pa-apply@park.example', 'agree' => '1', 'postal_code' => '99'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($a0, $this->rows('ses_companies'));
        $this->post('/apply', ['name' => '申込商事', 'contact_name' => '申込 太郎', 'email' => 'pa-apply@park.example', 'agree' => '1', 'postal_code' => '1000001'])->assertOk();
        $this->assertSame('100-0001', DB::table('ses_companies')->where('name', '申込商事')->value('postal_code'));
    }

    public function test_v4_6_engineer_register_and_profile(): void
    {
        $this->admin();
        // 仮登録の画面に、郵便番号・住所は無い（ご本人が本登録で入れる）。送られても入らない
        $this->get('/engineers/new?ses=1')->assertOk()->assertDontSee('name="postal_code"', false)->assertDontSee('name="address"', false);
        $this->post('/engineers', ['ses_id' => '1', 'name_sei' => '郵便', 'name_mei' => '太郎', 'email' => 'pa-eg@park.example', 'postal_code' => '1500001', 'address' => '東京都渋谷区'])
            ->assertSessionHas('flash.type', 'ok');
        $e = DB::table('engineers')->where('name', '郵便 太郎')->first();
        $this->assertSame([null, null], [$e->postal_code, $e->address]);
        // 本登録の画面で入れる：不正な郵便番号・長すぎる住所は断る
        $token = DB::table('users')->where('email', 'pa-eg@park.example')->value('token');
        $reg = ['name_sei' => '郵便', 'name_mei' => '太郎', 'kana_sei' => 'ゆうびん', 'kana_mei' => 'たろう', 'phone' => '090-0000-0003', 'consent' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
        auth()->logout();
        $this->flushSession();
        $this->post('/invite/'.$token, $reg + ['postal_code' => '1-2', 'address' => '東京都渋谷区'])->assertSessionHasErrors('postal_code');
        $this->post('/invite/'.$token, $reg + ['postal_code' => '1500001', 'address' => str_repeat('あ', 201)])->assertSessionHasErrors('address');
        $this->assertNull(DB::table('engineers')->find($e->id)->postal_code);
        $this->post('/invite/'.$token, $reg + ['postal_code' => '1500001', 'address' => '東京都渋谷区'])->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
        $e = DB::table('engineers')->find($e->id);
        $this->assertSame(['150-0001', '東京都渋谷区'], [$e->postal_code, $e->address]);
        $this->admin()->get("/engineers/{$e->id}")->assertOk()->assertSee('〒150-0001 東京都渋谷区');
        // SES の担当者も、仮登録できる（郵便番号は取らない）
        $this->as('ses1@park.example')->post('/engineers', ['name_sei' => '自社', 'name_mei' => '次郎', 'email' => 'pa-eg3@park.example', 'postal_code' => '1000001'])->assertSessionHas('flash.type', 'ok');
        $this->assertNull(DB::table('engineers')->where('name', '自社 次郎')->value('postal_code'));
        $this->admin();
        // 詳しいプロフィール
        $this->admin()->get('/engineers/1/profile')->assertOk()->assertSee('placeholder="123-4567"', false);
        $this->post('/engineers/1/profile', ['postal_code' => '1-2', 'address' => '変わらない'])->assertSessionHas('flash.type', 'bad');
        $this->assertNotSame('変わらない', DB::table('engineers')->where('id', 1)->value('address'));
        $this->post('/engineers/1/profile', ['postal_code' => '1234567', 'address' => '東京都'])->assertRedirect(url('/engineers/1'));
        $this->assertSame('123-4567', DB::table('engineers')->where('id', 1)->value('postal_code'));
        // 基本情報の編集には足さない
        $this->get('/engineers/1/edit')->assertOk()->assertDontSee('name="postal_code"', false);
    }

    // ---------- V5 見える範囲 ----------

    public function test_v5_1_admin_address_only_in_admin_screens(): void
    {
        $other = $this->otherAdmin(['postal_code' => '100-0001', 'address' => '管理者専用ビル']);
        foreach (['ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get("/admin/admins/$other")->assertForbidden();
            $this->get("/admin/admins/$other/edit")->assertForbidden();
        }
        $this->admin();
        $this->get('/admin/admins')->assertOk()->assertDontSee('管理者専用ビル');
        $this->get('/admin')->assertOk()->assertDontSee('管理者専用ビル');
        $this->get("/admin/admins/$other")->assertOk()->assertSee('管理者専用ビル');
    }

    public function test_v5_2_ses_address_only_own_company(): void
    {
        DB::table('ses_companies')->where('id', 1)->update(['postal_code' => '100-0001', 'address' => '自社ビル']);
        DB::table('ses_companies')->where('id', 2)->update(['postal_code' => '200-0002', 'address' => '他社ビル']);
        $this->as('ses1@park.example');
        $this->get('/ses/company')->assertOk()->assertSee('自社ビル');
        foreach (['/ses', '/ses/company', '/ses/engineers'] as $url) {
            $this->get($url)->assertOk()->assertDontSee('他社ビル');
        }
        $this->admin()->get('/admin/ses')->assertOk()->assertDontSee('自社ビル')->assertDontSee('他社ビル');
        $this->get('/admin/ses/2')->assertOk()->assertSee('〒200-0002 他社ビル');
    }

    public function test_v5_3_engineer_address_visibility(): void
    {
        DB::table('engineers')->where('id', 1)->update(['postal_code' => '150-0001', 'address' => '山田の住所ビル']);
        // 管理者・所属の SES・本人は見られる
        foreach (['admin@park.example', 'ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/engineers/1')->assertOk()->assertSee('〒150-0001 山田の住所ビル');
            $this->get('/engineers/1/profile')->assertOk();
        }
        // ほかの SES・ほかのエンジニアは 403
        foreach (['ses2@park.example', 'eg2@park.example'] as $email) {
            $this->as($email)->get('/engineers/1')->assertForbidden();
            $this->get('/engineers/1/profile')->assertForbidden();
        }
        // 一覧・名前を伏せたスキルシートには出ない
        $this->admin()->get('/admin/engineers')->assertOk()->assertDontSee('山田の住所ビル');
        $this->get('/engineers/1/sheet?anon=1')->assertOk()->assertDontSee('山田の住所ビル');
        $this->as('ses1@park.example')->get('/ses/engineers')->assertOk()->assertDontSee('山田の住所ビル');
    }

    public function test_v5_4_no_address_in_audit_notifications_or_mail(): void
    {
        $this->admin();
        $this->post('/admin/admins', ['name_sei' => '記録', 'name_mei' => '太郎', 'company_name' => '記録企業', 'email' => 'pa-log@park.example',
            'postal_code' => '1234567', 'address' => '記録されない住所ビル'])->assertSessionHas('flash.type', 'ok');
        $id = (int) DB::table('users')->where('email', 'pa-log@park.example')->value('id');
        $this->post("/admin/admins/$id", ['name_sei' => '記録', 'name_mei' => '太郎', 'company_name' => '記録企業', 'email' => 'pa-log@park.example', 'address' => '変えた住所ビル']);
        $this->post('/engineers', ['ses_id' => '1', 'name_sei' => '記録', 'name_mei' => '二郎', 'email' => 'pa-log2@park.example', 'postal_code' => '9876543', 'address' => 'エンジニア住所ビル']);
        foreach (['audit_log', 'app_notifications', 'mail_outbox'] as $t) {
            $all = json_encode(DB::table($t)->get(), JSON_UNESCAPED_UNICODE);
            foreach (['記録されない住所ビル', '変えた住所ビル', 'エンジニア住所ビル', '123-4567', '987-6543'] as $secret) {
                $this->assertStringNotContainsString($secret, $all, "$t に $secret");
            }
        }
    }

    // ---------- V6 文言 ----------

    public function test_v6_wording(): void
    {
        $this->admin();
        $this->assertStringNotContainsString('利用中', $this->get('/engineers/1')->getContent());
        $this->as('ses1@park.example');
        $this->assertStringNotContainsString('利用中', $this->get('/ses/engineers')->getContent());
        $this->get('/ses/engineers')->assertSee('参加中');
    }
}
