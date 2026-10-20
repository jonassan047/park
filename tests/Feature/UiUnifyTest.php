<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 画面の統一（PLAN 2026-10-20 画面の統一：U1〜U6）
// 開始カードの並び（U1-5・U5-2）は、見本なしで始める EmptyStateTest で確かめている
class UiUnifyTest extends TestCase
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

    // 使用中（パスワード設定済み）の別の管理者
    private function otherAdmin(): int
    {
        $id = W::createUser('admin2@park.example', '別 管理者', 'admin', 'park1234');
        DB::table('users')->where('id', $id)->update(['company_name' => '別企業', 'profile_completed_at' => now()]);
        return $id;
    }

    private function rows(string $t): int { return DB::table($t)->count(); }

    // 「（姓）」の欄の直後に「（名）」の欄があり、両方が同じ .pair の中にあること
    private function assertPair(string $html, string $base, string $where): void
    {
        $re = '#<div class="pair">(?:(?!</div>).)*?name="'.$base.'_sei"(?:(?!</div>).)*?name="'.$base.'_mei"#s';
        $this->assertMatchesRegularExpression($re, $html, "$where の {$base} が同じ行（.pair）にありません");
    }

    // ---------- U1・U2 管理者の一覧・登録・修正 ----------

    public function test_u1_list_has_register_button_and_no_form(): void
    {
        $other = $this->otherAdmin();
        $r = $this->admin()->get('/admin/admins')->assertOk();
        $r->assertSee('管理者を登録')->assertSee('href="'.url('/admin/admins/new').'"', false);
        $r->assertDontSee('登録すると')->assertDontSee('登録して招待メールを送る');
        // V2-1：行末は文字のリンク「詳細」（一覧 → 詳細 → 編集）
        $r->assertSee('href="'.url("/admin/admins/$other").'">詳細</a>', false);
        $this->assertStringNotContainsString('>直す<', $r->getContent());
        $this->assertStringNotContainsString('>修正<', $r->getContent());
    }

    public function test_u1_new_screen_and_roles(): void
    {
        $this->admin()->get('/admin/admins/new')->assertOk()->assertSee('管理者の登録')->assertSee('登録して招待メールを送る')->assertSee('必須')
            ->assertSee('登録すると、担当の方に招待メール（7日間有効のリンク）が届きます。');
        foreach (['ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/admin/admins/new')->assertForbidden();
        }
    }

    public function test_u1_register_returns_to_new_on_error_and_to_list_on_success(): void
    {
        $u0 = $this->rows('users');
        $this->admin()->from('/admin/admins/new')->post('/admin/admins', ['name_sei' => '山', 'name_mei' => '本', 'company_name' => '', 'email' => 'ui-a@park.example'])
            ->assertRedirect(url('/admin/admins/new'))->assertSessionHas('flash.type', 'bad');
        $this->assertSame($u0, $this->rows('users'));
        $m0 = DB::table('mail_outbox')->where('kind', 'invite')->count();
        $this->from('/admin/admins/new')->post('/admin/admins', ['name_sei' => '山', 'name_mei' => '本', 'company_name' => '企業', 'email' => 'ui-a@park.example'])
            ->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'ok');
        $this->assertSame($m0 + 1, DB::table('mail_outbox')->where('kind', 'invite')->count());
    }

    public function test_u2_edit_screen_title(): void
    {
        $other = $this->otherAdmin();
        $this->admin()->get("/admin/admins/$other/edit")->assertOk()->assertSee('管理者の編集：別 管理者')->assertDontSee('直す')->assertDontSee('の修正');
    }

    // ---------- U3 登録のときのメールは必須 ----------

    public function test_u3_ses_new_requires_email(): void
    {
        $html = $this->admin()->get('/admin/ses/new')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/name="email"[^>]*required/', $html);
        $this->assertMatchesRegularExpression('/name="contact_sei"[^>]*required/', $html);
        $s0 = $this->rows('ses_companies'); $u0 = $this->rows('users'); $m0 = $this->rows('mail_outbox');
        $this->post('/admin/ses', ['name' => '必須商事', 'contact_name' => '担当 者'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame([$s0, $u0, $m0], [$this->rows('ses_companies'), $this->rows('users'), $this->rows('mail_outbox')]);
    }

    public function test_u3_ses_edit_keeps_email_optional(): void
    {
        $html = $this->admin()->get('/admin/ses/1/edit')->assertOk()->getContent();
        preg_match('#action="[^"]*/admin/ses/1">.*?</form>#s', $html, $m);
        $this->assertNotEmpty($m);
        $this->assertDoesNotMatchRegularExpression('/name="email"[^>]*required/', $m[0]);
        $this->assertDoesNotMatchRegularExpression('/name="contact_sei"[^>]*required/', $m[0]);
        $this->post('/admin/ses/1', ['name' => '会社名そのまま', 'email' => ''])->assertSessionHas('flash.type', 'ok');
    }

    public function test_u3_engineer_new_requires_email_for_admin_and_ses(): void
    {
        $this->admin();
        $html = $this->get('/engineers/new?ses=1')->assertOk()->assertSee('招待メールは所属の SES が送ります')->getContent();
        $this->assertMatchesRegularExpression('/name="email"[^>]*required/', $html);
        foreach ([
            fn () => $this->admin()->post('/engineers', ['ses_id' => '1', 'name_sei' => '無', 'name_mei' => '一郎', 'email' => '']),
            fn () => $this->admin()->post('/engineers', ['ses_id' => '1', 'name_sei' => '無', 'name_mei' => '二郎', 'email' => 'abc']),
            fn () => $this->as('ses1@park.example')->post('/engineers', ['name_sei' => '無', 'name_mei' => '三郎', 'email' => '']),
            fn () => $this->as('ses1@park.example')->post('/engineers', ['name_sei' => '無', 'name_mei' => '四郎', 'email' => 'abc']),
        ] as $post) {
            $e0 = $this->rows('engineers'); $u0 = $this->rows('users'); $m0 = $this->rows('mail_outbox');
            $post()->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'メールアドレスを正しく入れてください。');
            $this->assertSame([$e0, $u0, $m0], [$this->rows('engineers'), $this->rows('users'), $this->rows('mail_outbox')]);
        }
    }

    public function test_u3_engineer_register_with_email(): void
    {
        foreach ([
            ['admin@park.example', ['ses_id' => '1'], 'ui-eg1@park.example'],
            ['ses1@park.example', [], 'ui-eg2@park.example'],
        ] as [$who, $extra, $email]) {
            $e0 = $this->rows('engineers'); $m0 = DB::table('mail_outbox')->where('kind', 'invite')->count();
            $this->as($who)->post('/engineers', $extra + ['name_sei' => '登録', 'name_mei' => '太郎', 'email' => $email])
                ->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', $who === 'admin@park.example' ? '登録しました。招待メールは、所属の SES から送ってもらいます。' : '登録し、ご本人に招待メールを送りました。');
            $this->assertSame($e0 + 1, $this->rows('engineers'));
            // 招待メールを送れるのは SES だけ（管理者の登録では送らない）
            $sent = $who === 'admin@park.example' ? 0 : 1;
            $this->assertSame($m0 + $sent, DB::table('mail_outbox')->where('kind', 'invite')->count());
            $this->assertSame($sent ? 'eg' : null, DB::table('users')->where('email', $email)->value('role'));
        }
    }

    public function test_u3_engineer_edit_keeps_email_optional(): void
    {
        $this->admin()->post('/engineers/1', ['name_sei' => '山田', 'name_mei' => '太郎', 'email' => ''])->assertSessionHas('flash.type', 'ok');
    }

    // ---------- U4 姓・名は同じ行 ----------

    public function test_u4_pairs_on_every_screen(): void
    {
        $other = $this->otherAdmin();
        $this->admin();
        $this->assertPair($this->get('/admin/admins/new')->getContent(), 'name', '管理者の登録');
        $this->assertPair($this->get("/admin/admins/$other/edit")->getContent(), 'name', '管理者の編集');
        $new = $this->get('/admin/ses/new')->getContent();
        $this->assertPair($new, 'contact', 'SES の登録'); $this->assertPair($new, 'rep', 'SES の登録');
        $show = $this->get('/admin/ses/1/edit')->getContent();
        $this->assertPair($show, 'contact', 'SES の編集'); $this->assertPair($show, 'rep', 'SES の編集');
        $this->assertPair($this->get('/admin/ses/1/users/new')->getContent(), 'name', 'SES の担当者追加');
        $eg = $this->get('/engineers/new?ses=1')->getContent();
        $this->assertPair($eg, 'name', 'エンジニアの登録'); $this->assertPair($eg, 'kana', 'エンジニアの登録');
        $edit = $this->get('/engineers/1/edit')->getContent();
        $this->assertPair($edit, 'name', 'プロフィールの編集'); $this->assertPair($edit, 'kana', 'プロフィールの編集');
        $acc = $this->get('/account')->getContent();
        $this->assertPair($acc, 'name', 'アカウント'); $this->assertPair($acc, 'kana', 'アカウント');

        DB::table('users')->where('email', 'eg1@park.example')->update(['profile_completed_at' => null]);
        $w = $this->as('eg1@park.example')->get('/welcome')->assertOk()->getContent();
        $this->assertPair($w, 'name', 'あなたの情報'); $this->assertPair($w, 'kana', 'あなたの情報');

        $co = $this->as('ses1@park.example')->get('/ses/company/edit')->assertOk()->getContent();
        $this->assertPair($co, 'contact', '会社情報'); $this->assertPair($co, 'rep', '会社情報');
    }

    public function test_u4_pairs_on_public_screens(): void
    {
        $ap = $this->get('/apply')->assertOk()->getContent();
        $this->assertPair($ap, 'contact', '参加のお申し込み'); $this->assertPair($ap, 'rep', '参加のお申し込み');
        DB::table('users')->delete();
        $this->assertPair($this->get('/setup')->assertOk()->getContent(), 'name', 'はじめの設定');
    }

    public function test_u4_profile_shows_name_on_one_line(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['name_sei' => '管理', 'name_mei' => '花子', 'kana_sei' => 'かんり', 'kana_mei' => 'はなこ', 'profile_completed_at' => now()]);
        $this->admin()->get('/account/profile')->assertOk()->assertSee('管理 花子')->assertSee('かんり はなこ')
            ->assertDontSee('氏名（姓）')->assertDontSee('氏名（名）');
    }

    // ---------- U5 管理者は SES を選んでからエンジニアを登録する ----------

    public function test_u5_admin_engineer_list_has_no_register_button(): void
    {
        $this->assertStringNotContainsString('href="'.url('/engineers/new').'"', $this->admin()->get('/admin/engineers')->assertOk()->getContent());
    }

    public function test_u5_new_without_valid_ses_goes_back_to_ses_list(): void
    {
        DB::table('ses_companies')->where('id', 2)->update(['status' => 'suspended']);
        $this->admin();
        foreach (['/engineers/new', '/engineers/new?ses=99999', '/engineers/new?ses=2', '/engineers/new?ses=4', '/engineers/new?ses=abc'] as $url) {
            $this->get($url)->assertRedirect(url('/admin/ses'))->assertSessionHas('flash.type', 'warn')
                ->assertSessionHas('flash.msg', 'エンジニアは、SES の画面で SES を選んでから登録します。');
        }
    }

    public function test_u5_new_with_ses_shows_fixed_affiliation(): void
    {
        $name = DB::table('ses_companies')->where('id', 1)->value('name');
        $html = $this->admin()->get('/engineers/new?ses=1')->assertOk()->assertSee('所属：'.$name)->getContent();
        $this->assertStringContainsString('<input type="hidden" name="ses_id" value="1">', $html);
        $this->assertStringNotContainsString('<select name="ses_id"', $html);
        $this->get('/engineers/new?ses=direct')->assertRedirect(url('/admin/ses')); // 直属は新しく登録できない（V1-1）
    }

    public function test_u5_admin_post_with_bad_ses_is_refused(): void
    {
        DB::table('ses_companies')->where('id', 2)->update(['status' => 'suspended']);
        $e0 = $this->rows('engineers');
        $this->admin();
        foreach ([[], ['ses_id' => '2'], ['ses_id' => '4']] as $extra) {
            $this->post('/engineers', $extra + ['name_sei' => '無効', 'name_mei' => '太郎', 'email' => 'ui-bad@park.example'])->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame($e0, $this->rows('engineers'));
    }

    public function test_u5_ses_detail_button_only_for_active(): void
    {
        DB::table('ses_companies')->where('id', 2)->update(['status' => 'suspended']);
        $this->admin();
        $this->get('/admin/ses/1')->assertOk()->assertSee('href="'.url('/engineers/new?ses=1').'"', false);
        $this->get('/admin/ses/2')->assertOk()->assertDontSee('/engineers/new?ses=2');
        $this->get('/admin/ses/4')->assertOk()->assertDontSee('/engineers/new?ses=4');
    }

    public function test_u5_ses_list_has_no_direct_row(): void
    {
        // V1-3：SES の一覧に「管理者の直属」の行は出ない。今いる直属の人がいても同じ
        $this->admin()->get('/admin/ses')->assertOk()->assertDontSee('管理者の直属')->assertDontSee('ses=direct');
        W::createEngineer(W::directSesId(), ['name' => '直属 一郎', 'email' => 'ui-direct@park.example'], User::where('email', 'admin@park.example')->first());
        $before = $this->get('/admin')->viewData('counts')['ses'];
        $this->get('/admin/ses')->assertOk()->assertDontSee('管理者の直属')->assertDontSee('ses=direct');
        $this->assertSame($before, $this->get('/admin')->viewData('counts')['ses']);
    }

    public function test_u5_ses_user_register_screen_unchanged(): void
    {
        $this->as('ses1@park.example')->get('/engineers/new')->assertOk()->assertDontSee('所属：')->assertDontSee('name="ses_id"', false);
    }

    // ---------- U6 言葉 ----------

    public function test_u6_wording(): void
    {
        $this->admin();
        $show = $this->get('/admin/ses/1')->assertOk()->assertSee('使用中'); $this->get('/admin/ses/1/edit')->assertOk()->assertSee('メールアドレス');
        $this->assertStringNotContainsString('<span>メール</span>', $show->getContent());
        $this->assertStringNotContainsString('利用中', $show->getContent());
        $this->assertStringNotContainsString('利用中', $this->get('/engineers/1')->getContent());
        $this->as('ses1@park.example');
        $this->assertStringNotContainsString('利用中', $this->get('/ses/engineers')->getContent());
        $this->as('eg1@park.example')->get('/account')->assertOk()->assertSee('保存する')->assertSee('変更する');
    }

    // ---------- 詳細画面のボタン（2026-10-20） ----------

    public function test_detail_buttons(): void
    {
        $this->admin();
        $show = $this->get('/admin/ses/1')->assertOk()->assertSee('会社情報')->assertSee('担当者を登録')->assertSee('SES の一覧に戻る')->getContent();
        $this->assertStringContainsString(url('/admin/ses/1/edit'), $show);
        $this->get('/admin/ses/1/edit')->assertOk()->assertSee('戻る');
        $this->get('/admin/ses/1/users/new')->assertOk()->assertSee('戻る');
        $this->get('/engineers/1')->assertOk()->assertSee('エンジニアの一覧に戻る')->assertSee('>退会<', false);
        $this->get('/cases/1')->assertOk()->assertSee('案件の一覧に戻る');
    }

    public function test_ses_and_engineer_leave(): void
    {
        $this->admin();
        $egs = \DB::table('engineers')->where('ses_id', 1)->pluck('id');
        \DB::table('contracts')->where('ses_id', 1)->update(['status' => 'ended']);
        $this->post('/admin/ses/1/delete')->assertSessionHas('flash.type', 'ok');
        $this->assertDatabaseHas('ses_companies', ['id' => 1, 'status' => 'left']);
        foreach ($egs as $i) $this->assertDatabaseHas('engineers', ['id' => $i, 'status' => 'left']);
        $this->assertSame(0, \DB::table('users')->where('ses_id', 1)->where('active', 1)->count());
        $this->get('/admin/ses/1')->assertOk()->assertSee('退会済');
    }

    public function test_leave_refused_while_contract_active(): void
    {
        $this->admin();
        $k = \DB::table('contracts')->where('status', 'active')->first();
        if (!$k) { $this->markTestSkipped('稼働中の契約がない'); }
        $this->post("/admin/ses/{$k->ses_id}/delete")->assertSessionHas('flash.type', 'bad');
        $this->post("/engineers/{$k->engineer_id}/delete")->assertSessionHas('flash.type', 'bad');
    }
}
