<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// 氏名・ふりがなを姓・名に分ける（PLAN 2026-10-19 の M）
class NameSplitTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    // 氏名・企業名が足りない（足りない項目の画面が出る）状態にする
    private function unfinish(string ...$emails): void
    {
        DB::table('users')->whereIn('email', $emails)->update(['profile_completed_at' => null, 'name_sei' => null, 'name_mei' => null, 'company_name' => null]);
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    public function test_m1_columns(): void
    {
        $this->assertTrue(Schema::hasColumns('users', ['name', 'kana', 'name_sei', 'name_mei', 'kana_sei', 'kana_mei']));
        $this->assertTrue(Schema::hasColumns('engineers', ['name', 'kana', 'name_sei', 'name_mei', 'kana_sei', 'kana_mei']));
    }

    public function test_m2_split_name_and_join_name(): void
    {
        $this->assertSame(['山田', '太郎'], split_name('山田 太郎'));
        $this->assertSame(['山田', '太郎'], split_name('山田　太郎'));
        $this->assertSame(['山田', '太郎'], split_name('山田  太郎'));
        $this->assertSame(['山田', '太郎'], split_name('山田 　 太郎'));
        $this->assertSame(['管理者', null], split_name('管理者'));
        $this->assertSame(['Mary', 'Ann Smith'], split_name('Mary Ann Smith'));
        $this->assertSame([null, null], split_name(null));
        $this->assertSame([null, null], split_name(''));
        $this->assertSame([null, null], split_name('  　 '));
        $this->assertSame('山田 太郎', join_name('山田', '太郎'));
        $this->assertSame('山田', join_name('山田', null));
        $this->assertSame('山田', join_name('山田', ''));
        $this->assertSame('太郎', join_name(null, '太郎'));
        $this->assertNull(join_name(null, null));
        $this->assertNull(join_name('', ' '));
    }

    public function test_m2_existing_rows_are_split_without_touching_name(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['name' => '管理者　太郎', 'kana' => 'かんり　たろう', 'name_sei' => null, 'name_mei' => null, 'kana_sei' => null, 'kana_mei' => null]);
        DB::table('users')->where('email', 'ses1@park.example')->update(['name' => '単独', 'kana' => null, 'name_sei' => null, 'name_mei' => null]);
        DB::table('engineers')->where('id', 1)->update(['name' => 'Mary Ann Smith', 'kana' => null, 'name_sei' => null, 'name_mei' => null]);
        W::splitExistingNames();
        $a = $this->user('admin@park.example');
        $this->assertSame(['管理者', '太郎', 'かんり', 'たろう'], [$a->name_sei, $a->name_mei, $a->kana_sei, $a->kana_mei]);
        $this->assertSame('管理者　太郎', $a->name); // 書き換えない
        $this->assertSame('かんり　たろう', $a->kana);
        $s = $this->user('ses1@park.example');
        $this->assertSame('単独', $s->name_sei);
        $this->assertNull($s->name_mei);
        $this->assertNull($s->kana_sei);
        $e = DB::table('engineers')->find(1);
        $this->assertSame(['Mary', 'Ann Smith'], [$e->name_sei, $e->name_mei]);
        $this->assertSame('Mary Ann Smith', $e->name);
    }

    public function test_m2_seeded_rows_are_split(): void
    {
        $e = DB::table('engineers')->where('name', '山田 太郎')->first();
        $this->assertSame(['山田', '太郎'], [$e->name_sei, $e->name_mei]);
        $this->assertSame(0, DB::table('users')->whereNull('name_sei')->count());
    }

    // 最初の情報登録の画面（/welcome）は、足りない項目の画面（/register/complete）に替わった。足りない項目だけを入れる
    public function test_m3_complete_saves_joined_name(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->post('/register/complete', ['name_sei' => ' 山田　', 'name_mei' => '太郎', 'company_name' => '見本運営株式会社'])->assertRedirect(url('/admin'));
        $u = $this->user('admin@park.example');
        $this->assertSame('山田 太郎', $u->name);
        $this->assertSame(['山田', '太郎'], [$u->name_sei, $u->name_mei]);
        $this->assertSame('見本運営株式会社', $u->company_name);
    }

    public function test_m4_complete_screen_order_of_fields(): void
    {
        $this->unfinish('admin@park.example');
        DB::table('users')->where('email', 'admin@park.example')->update(['phone' => null]);
        $html = $this->as('admin@park.example')->get('/register/complete')->assertOk()
            ->assertSeeInOrder(['企業名', '氏名（姓）', '氏名（名）', '電話番号'])->getContent();
        $this->assertStringContainsString('class="pair"', $html);
        $this->assertStringContainsString('name="name_sei"', $html);
        $this->assertStringNotContainsString('name="name"', $html);
        $this->assertStringNotContainsString('name="kana"', $html);
    }

    public function test_m4_required_both_name_parts(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->from('/register/complete')->post('/register/complete', ['name_sei' => '姓だけ', 'name_mei' => '', 'company_name' => '社'])->assertSessionHasErrors('name_mei');
        $this->assertNull($this->user('admin@park.example')->profile_completed_at);
        $this->post('/register/complete', ['name_sei' => '', 'name_mei' => '名だけ', 'company_name' => '社'])->assertSessionHasErrors('name_sei');
        $this->assertNull($this->user('admin@park.example')->profile_completed_at);
        // ふりがなは任意（足りない項目の画面には出ない）
        $this->post('/register/complete', ['name_sei' => '姓', 'name_mei' => '名', 'company_name' => '社'])->assertRedirect(url('/admin'));
        $u = $this->user('admin@park.example');
        $this->assertNotNull($u->profile_completed_at);
        $this->assertEmpty($u->kana);
    }

    public function test_m5_account_keeps_name_when_both_empty(): void
    {
        $before = $this->user('admin@park.example');
        $this->as('admin@park.example')->post('/account', ['phone' => '03-1111-2222'])->assertRedirect(url('/account'));
        $a = $this->user('admin@park.example');
        $this->assertSame($before->name, $a->name);
        $this->assertSame($before->name_sei, $a->name_sei);
        $this->assertSame('03-1111-2222', $a->phone);
        $this->post('/account', ['name_sei' => '', 'name_mei' => ''])->assertRedirect(url('/account'));
        $this->assertSame($before->name, $this->user('admin@park.example')->name);
    }

    public function test_m5_account_mei_only_is_error_sei_only_is_ok(): void
    {
        $before = $this->user('admin@park.example')->name;
        $this->as('admin@park.example')->from('/account')->post('/account', ['name_mei' => '名だけ'])->assertSessionHasErrors('name_sei');
        $this->assertSame($before, $this->user('admin@park.example')->name);
        // 名はどこでも必須になったので（台帳 K19・K20）、姓だけの保存は断る（以前は、名を空にできた）
        $this->post('/account', ['name_sei' => '管理者'])->assertSessionHas('flash.type', 'bad');
        $a = $this->user('admin@park.example');
        $this->assertSame($before, $a->name);
        $this->assertNotNull($a->name_mei);
    }

    public function test_m5_account_shows_sei_mei_labels(): void
    {
        $this->as('ses1@park.example')->get('/account')->assertOk()
            ->assertSeeInOrder(['氏名（姓）', '氏名（名）', 'ふりがな（姓）', 'ふりがな（名）', 'SES名', '所属・役職', '電話'])->assertDontSee('お名前');
    }

    // 本登録（メールのリンク → 確認コード）を、この人として通す。ふりがななどの入力を渡す
    private function finishRegistration(string $email, array $payload): void
    {
        $token = $this->user($email)->token;
        \Illuminate\Support\Facades\Auth::logout();
        $this->flushSession();
        $this->post('/invite/'.$token, $payload + ['password' => 'password12', 'password_confirmation' => 'password12'])->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
    }

    public function test_m6_register_engineer_with_parts(): void
    {
        $this->as('admin@park.example');
        // 仮登録で入れるのは 姓・名・メール の3つだけ。ふりがなは送られても入れない（ご本人が本登録で入れる）
        $this->post('/engineers', ['ses_id' => '1', 'name_sei' => '直属', 'name_mei' => '一郎', 'kana_sei' => 'ちょくぞく', 'kana_mei' => 'いちろう', 'email' => 'm6-direct@park.example'])->assertRedirect();
        $e = DB::table('engineers')->where('name', '直属 一郎')->first();
        $this->assertNotNull($e);
        $this->assertNull($e->kana);
        $this->assertSame(['直属', '一郎'], [$e->name_sei, $e->name_mei]);
        $this->assertSame(1, (int) $e->ses_id);
        // ご本人が本登録でふりがなを入れると、イニシャルもできる
        $this->finishRegistration('m6-direct@park.example', ['name_sei' => '直属', 'name_mei' => '一郎', 'kana_sei' => 'ちょくぞく', 'kana_mei' => 'いちろう', 'phone' => '090-0000-0001',
            'postal_code' => '100-0001', 'address' => '東京都千代田区1-1', 'consent' => '1']);
        $e = DB::table('engineers')->find($e->id);
        $this->assertSame('ちょくぞく いちろう', $e->kana);
        $this->assertSame('I.C', $e->initials);
    }

    public function test_m6_register_requires_both_parts(): void
    {
        $this->as('admin@park.example');
        $n = DB::table('engineers')->count();
        $this->post('/engineers', ['ses_id' => '1', 'name_sei' => '姓だけ'])->assertSessionHas('flash.msg', '所属と氏名を入れてください。');
        $this->post('/engineers', ['ses_id' => '1', 'name_sei' => '姓だけ', 'name_mei' => '  　'])->assertSessionHas('flash.type', 'bad');
        $this->post('/engineers', ['ses_id' => '1', 'name_mei' => '名だけ'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($n, DB::table('engineers')->count());
        $this->assertSame(0, DB::table('ses_companies')->where('kind', 'direct')->count());
    }

    public function test_m6_form_order_and_hint(): void
    {
        // 仮登録の画面は 姓・名・メール の3つだけ（ふりがなは本登録で入れる）
        $this->as('admin@park.example')->get('/engineers/new?ses=1')->assertOk()
            ->assertSeeInOrder(['所属：', '氏名（姓）', '氏名（名）', 'メールアドレス'])->assertDontSee('ふりがな（姓）')->assertDontSee('メールで呼ぶ名前');
        $this->get('/engineers/1/edit')->assertOk()->assertSeeInOrder(['氏名（姓）', '氏名（名）', 'ふりがな（姓）', 'ふりがな（名）', 'スキルシートのイニシャル（T.Y）に使います', 'メールで呼ぶ名前']);
    }

    public function test_m6_edit_only_sei_keeps_mei(): void
    {
        $this->as('admin@park.example');
        $this->post('/engineers/1', ['name_sei' => '佐藤'])->assertRedirect();
        $e = DB::table('engineers')->find(1);
        $this->assertSame('佐藤 太郎', $e->name);
        $this->assertSame('太郎', $e->name_mei);
        // 姓も名も、空にするとエラー（台帳 K20。以前は、名を空にできた）
        $this->post('/engineers/1', ['name_sei' => '', 'name_mei' => '太郎'])->assertSessionHas('flash.msg', '氏名（姓）は空にできません。');
        $this->assertSame('佐藤 太郎', DB::table('engineers')->find(1)->name);
        $this->post('/engineers/1', ['name_sei' => '佐藤', 'name_mei' => ''])->assertSessionHas('flash.msg', '氏名（名）は空にできません。');
        $e = DB::table('engineers')->find(1);
        $this->assertSame('佐藤 太郎', $e->name);
        $this->assertSame('太郎', $e->name_mei);
    }

    public function test_m6_detailed_profile_save_keeps_names(): void
    {
        $before = DB::table('engineers')->find(1);
        $this->as('admin@park.example')->post('/engineers/1/profile', ['appeal' => '自己PRだけ変える', 'wishes' => '希望'])->assertRedirect();
        $e = DB::table('engineers')->find(1);
        $this->assertSame('自己PRだけ変える', $e->appeal);
        foreach (['name', 'name_sei', 'name_mei', 'kana', 'kana_sei', 'kana_mei'] as $k) $this->assertSame($before->$k, $e->$k, $k);
        $this->assertNotNull($e->name_sei);
    }

    public function test_m7_length_boundary(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->from('/register/complete');
        foreach (['name_sei', 'name_mei'] as $k) {
            $in = ['name_sei' => '姓', 'name_mei' => '名', 'company_name' => '社', $k => str_repeat('あ', 51)];
            $this->post('/register/complete', $in)->assertSessionHasErrors($k);
            $this->assertNull($this->user('admin@park.example')->profile_completed_at);
        }
        $this->post('/register/complete', ['name_sei' => str_repeat('あ', 50), 'name_mei' => str_repeat('い', 50), 'company_name' => '社'])->assertRedirect(url('/admin'));
        $u = $this->user('admin@park.example');
        $this->assertSame(str_repeat('あ', 50), $u->name_sei);
        $this->assertSame(str_repeat('い', 50), $u->name_mei);
        // ふりがなの51文字は、アカウントの画面で断る（足りない項目の画面には、ふりがなは出ない）
        foreach (['kana_sei', 'kana_mei'] as $k) $this->post('/account', ['name_sei' => '姓', 'name_mei' => '名', $k => str_repeat('あ', 51)])->assertSessionHasErrors($k);
        // エンジニアの登録・編集も 51 文字はだめ
        $this->as('admin@park.example')->post('/engineers', ['ses_id' => '1', 'name_sei' => str_repeat('あ', 51), 'name_mei' => '名'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame(0, DB::table('engineers')->where('name_sei', 'like', str_repeat('あ', 51).'%')->count());
        $this->post('/engineers/1', ['name_sei' => str_repeat('あ', 51)])->assertSessionHas('flash.type', 'bad');
        $this->assertSame('山田', DB::table('engineers')->find(1)->name_sei);
        $this->post('/engineers/1', ['name_sei' => str_repeat('あ', 50)])->assertRedirect(url('/engineers/1'));
        $this->assertSame(str_repeat('あ', 50), DB::table('engineers')->find(1)->name_sei);
    }

    public function test_m8_initials_from_joined_kana(): void
    {
        $this->as('admin@park.example')->post('/engineers', ['ses_id' => '1', 'name_sei' => '山田', 'name_mei' => '次郎', 'email' => 'm8-jiro@park.example'])->assertRedirect();
        $this->assertNull(DB::table('engineers')->where('name', '山田 次郎')->value('initials')); // ふりがなは、ご本人が本登録で入れる
        $this->finishRegistration('m8-jiro@park.example', ['name_sei' => '山田', 'name_mei' => '次郎', 'kana_sei' => 'やまだ', 'kana_mei' => 'じろう', 'phone' => '090-0000-0002',
            'postal_code' => '100-0001', 'address' => '東京都千代田区1-1', 'consent' => '1']);
        $this->assertSame('J.Y', DB::table('engineers')->where('name', '山田 次郎')->value('initials'));
    }

    public function test_m9_appearance_is_unchanged(): void
    {
        $eid = (int) User::where('email', 'eg1@park.example')->value('engineer_id');
        $this->assertSame('山田 太郎', DB::table('engineers')->where('id', $eid)->value('name'));
        $this->as('eg1@park.example')->get("/engineers/$eid/sheet")->assertOk()->assertSee('山田 太郎');
        $this->get("/engineers/$eid/sheet?anon=1")->assertOk()->assertDontSee('山田');
        $this->as('admin@park.example')->get('/admin')->assertSee(e(user_label(User::where('email', 'admin@park.example')->first())), false);
        $this->get('/admin/engineers')->assertSee('山田 太郎');
        $this->get("/engineers/$eid")->assertSee('山田 太郎');
        // 保存後もつないだ形が同じ
        $this->as('eg1@park.example');
        $this->post('/account', ['name_sei' => '山田', 'name_mei' => '太郎'])->assertRedirect();
        $this->assertSame('山田 太郎', $this->user('eg1@park.example')->name);
    }

    public function test_m10_single_field_entrances_split_on_save(): void
    {
        $this->as('admin@park.example');
        $this->post('/admin/admins', ['name' => '見本 花子', 'company_name' => 'テスト企業', 'email' => 'new-admin@park.example'])->assertRedirect();
        $u = $this->user('new-admin@park.example');
        $this->assertSame(['見本', '花子', '見本 花子'], [$u->name_sei, $u->name_mei, $u->name]);

        $this->post('/admin/ses/1/users', ['name' => '追加　担当', 'email' => 'ses1b@park.example'])->assertRedirect();
        $u = $this->user('ses1b@park.example');
        $this->assertSame(['追加', '担当'], [$u->name_sei, $u->name_mei]);

        $this->post('/admin/ses', ['name' => '登録 SES', 'rep_sei' => '連絡', 'rep_mei' => '太郎', 'email' => 'reg-ses@park.example'])->assertRedirect();
        $u = $this->user('reg-ses@park.example');
        $this->assertSame(['連絡', '太郎'], [$u->name_sei, $u->name_mei]);

        $id = W::createUser('one@park.example', '一語', 'ses', 'x', ['ses_id' => 1]);
        $u = DB::table('users')->find($id);
        $this->assertSame(['一語', null], [$u->name_sei, $u->name_mei]);
    }

    public function test_m10_setup_and_invite_admin_command(): void
    {
        DB::table('users')->delete();
        $this->post('/setup', ['company_name' => '最初企業', 'name_sei' => '最初', 'name_mei' => '管理', 'phone' => '03-0000-0001', 'email' => 'first@park.example', 'password' => 'abcd1234'])->assertRedirect(url('/admin'));
        $u = $this->user('first@park.example');
        $this->assertSame(['最初', '管理'], [$u->name_sei, $u->name_mei]);
        $this->artisan('park:invite-admin', ['email' => 'cmd-admin@park.example', 'sei' => 'コマンド', 'mei' => '一郎'])->assertExitCode(0);
        $u = $this->user('cmd-admin@park.example');
        $this->assertNotNull($u);
        $this->assertSame(['コマンド', '一郎'], [$u->name_sei, $u->name_mei]);
    }

    public function test_m11_legacy_name_post_is_split(): void
    {
        $this->unfinish('admin@park.example');
        $this->as('admin@park.example')->post('/register/complete', ['name' => '佐藤 美咲', 'company_name' => '社'])->assertRedirect(url('/admin'));
        $u = $this->user('admin@park.example');
        $this->assertSame(['佐藤', '美咲', '佐藤 美咲'], [$u->name_sei, $u->name_mei, $u->name]);
        // 空白の無い古い送り方は名が足りず、足りない項目の画面ではエラー
        $this->unfinish('admin@park.example');
        $this->from('/register/complete')->post('/register/complete', ['name' => '一語', 'company_name' => '社'])->assertSessionHasErrors('name_mei');
        // 姓・名の欄があれば、古い name は無視される
        $this->post('/register/complete', ['name' => '無視 される', 'name_sei' => '本当', 'name_mei' => '姓名', 'company_name' => '社'])->assertRedirect(url('/admin'));
        $this->assertSame('本当 姓名', $this->user('admin@park.example')->name);
    }

    public function test_m11_legacy_engineer_post(): void
    {
        $this->as('admin@park.example')->post('/engineers', ['ses_id' => '1', 'name' => '古い 画面', 'kana' => 'ふるい がめん', 'email' => 'm11-old@park.example'])->assertRedirect();
        // 古い送り方（name の1欄）でも、姓・名に分けて入る。ふりがなは仮登録では取らない（本登録で入れる）
        $e = DB::table('engineers')->where('name', '古い 画面')->first();
        $this->assertSame(['古い', '画面', null, null], [$e->name_sei, $e->name_mei, $e->kana_sei, $e->kana_mei]);
    }

    public function test_m11_person_names_use_sei_mei_fields(): void
    {
        $this->as('admin@park.example');
        $this->post('/admin/admins', ['name_sei' => '招待', 'name_mei' => '次郎', 'company_name' => '企業', 'email' => 'm11a@park.example'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(['招待', '次郎', '招待 次郎'], [($u = $this->user('m11a@park.example'))->name_sei, $u->name_mei, $u->name]);

        // SES の登録は、企業正式名称・SES の姓・名・メールの4つだけ（SES がそのまま登録される人）。担当者の欄は送られても使わない
        $this->post('/admin/ses', ['name' => '分割 SES', 'rep_sei' => '代表', 'rep_mei' => '一', 'contact_sei' => '担当', 'contact_mei' => '二', 'email' => 'm11b@park.example'])->assertSessionHas('flash.type', 'ok');
        $s = DB::table('ses_companies')->where('name', '分割 SES')->first();
        $this->assertSame(['代表 一', '代表', '一'], [$s->rep_name, $s->rep_sei, $s->rep_mei]);
        $this->assertNull($s->contact_sei);
        $u = $this->user('m11b@park.example');
        $this->assertSame(['代表', '一'], [$u->name_sei, $u->name_mei]);

        // 担当者を足せるのは参加中の会社だけ
        DB::table('ses_companies')->where('id', $s->id)->update(['status' => 'active']);
        $this->post('/admin/ses/'.$s->id.'/users', ['name_sei' => '追加', 'name_mei' => '三', 'email' => 'm11c@park.example'])->assertRedirect();
        $this->assertSame(['追加', '三'], [($u = $this->user('m11c@park.example'))->name_sei, $u->name_mei]);

        // SES の姓・名のどちらが欠けても登録できない
        $this->post('/admin/ses', ['name' => '宛名なし SES', 'rep_mei' => '名だけ', 'email' => 'm11d@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->post('/admin/ses', ['name' => '宛名なし SES2', 'rep_sei' => '姓だけ', 'email' => 'm11e@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame(0, DB::table('ses_companies')->where('name', 'like', '宛名なし%')->count());
    }
}
