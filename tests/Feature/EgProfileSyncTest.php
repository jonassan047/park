<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// エンジニアの氏名・ふりがな・電話を、アカウント（users）と技術登録（engineers）でそろえる（PLAN 2026-10-28 の B1。v0.2.0 の S2-25・S2-26）。
// 見本の eg1@park.example は SES 1（緑川）のエンジニア 1。「最初の情報登録（welcome）」は、足りない項目の画面（/register/complete）と本登録（/invite）に替わっている。
class EgProfileSyncTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function egUser(string $email = 'eg1@park.example'): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function eng(int $id): object
    {
        return DB::table('engineers')->find($id);
    }

    // users の同期対象の列（氏名・ふりがな・電話）
    private function userCols(int $uid): array
    {
        $u = DB::table('users')->find($uid);
        return [$u->name, $u->name_sei, $u->name_mei, $u->kana, $u->kana_sei, $u->kana_mei, $u->phone];
    }

    private function engCols(int $eid): array
    {
        $e = $this->eng($eid);
        return [$e->name, $e->name_sei, $e->name_mei, $e->kana, $e->kana_sei, $e->kana_mei, $e->phone];
    }

    // エンジニア本人の users と engineers の同期対象の列を、同じ値にそろえる（見本のままだと食い違っていることがあるため）
    private function align(): void
    {
        foreach (DB::table('users')->where('role', 'eg')->whereNotNull('engineer_id')->get() as $u) {
            $e = $this->eng((int) $u->engineer_id);
            DB::table('users')->where('id', $u->id)->update(['name' => $e->name, 'name_sei' => $e->name_sei, 'name_mei' => $e->name_mei,
                'kana' => $e->kana, 'kana_sei' => $e->kana_sei, 'kana_mei' => $e->kana_mei, 'phone' => $e->phone]);
        }
    }

    // ---------- T1（AC1）：足りない項目の画面（以前の welcome）で入れたふりがな・電話が、技術登録にも入る ----------
    public function test_t1_complete_screen_values_reach_engineer(): void
    {
        $u = $this->egUser(); $eid = (int) $u->engineer_id;
        // ふりがな・電話が、アカウントにも技術登録にも無い状態（本人はまだ登録を済ませていない）
        DB::table('engineers')->where('id', $eid)->update(['kana' => null, 'kana_sei' => null, 'kana_mei' => null, 'phone' => null, 'initials' => null]);
        DB::table('users')->where('id', $u->id)->update(['kana' => null, 'kana_sei' => null, 'kana_mei' => null, 'phone' => null, 'profile_completed_at' => null]);
        $this->as('eg1@park.example')->get('/eg')->assertRedirect(url('/register/complete'));
        $this->post('/register/complete', ['kana_sei' => 'やまだ', 'kana_mei' => 'たろう', 'phone' => '090-1234-5678'])->assertRedirect();
        $e = $this->eng($eid);
        $this->assertSame(['やまだ', 'たろう', 'やまだ たろう', '090-1234-5678'], [$e->kana_sei, $e->kana_mei, $e->kana, $e->phone]);
        $this->assertSame('T.Y', $e->initials);
        $this->assertSame(['やまだ', 'たろう', '090-1234-5678'], [DB::table('users')->find($u->id)->kana_sei, DB::table('users')->find($u->id)->kana_mei, DB::table('users')->find($u->id)->phone]);
        $this->get("/engineers/$eid/edit")->assertOk()->assertSee('value="やまだ"', false)->assertSee('value="たろう"', false);
        $this->get('/welcome')->assertRedirect(url('/register/complete')); // 古い入口は転送される
    }

    // ---------- T1（AC1・AC7）：仮登録のエンジニアの本登録。技術登録の氏名・ふりがなが入力欄に入っており、通すと両方に入る ----------
    public function test_t1_registration_from_invite_syncs_both_tables(): void
    {
        $ses = User::where('email', 'ses1@park.example')->first();
        $eid = W::createEngineer(1, ['name_sei' => '招待', 'name_mei' => '花子', 'kana_sei' => 'やまだ', 'kana_mei' => 'はなこ', 'phone' => '03-1111-2222', 'email' => 'sync-new@example.com'], $ses);
        $u = DB::table('users')->where('email', 'sync-new@example.com')->first();
        $this->assertSame(['やまだ', 'はなこ', '03-1111-2222'], [$u->kana_sei, $u->kana_mei, $u->phone]); // 仮登録のアカウントに、技術登録の値が写っている（T8）
        $this->guest();
        $this->get('/invite/'.$u->token)->assertOk()->assertSee('value="やまだ"', false)->assertSee('value="はなこ"', false);
        $this->post('/invite/'.$u->token, ['name_sei' => '招待', 'name_mei' => '花子', 'kana_sei' => 'やまだ', 'kana_mei' => 'はなこ', 'phone' => '090-0000-1111', 'postal_code' => '100-0001',
            'address' => '東京都千代田区1-1', 'consent' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'])->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
        $this->assertSame($this->userCols((int) $u->id), $this->engCols($eid));
        $this->assertSame('090-0000-1111', $this->eng($eid)->phone);
    }

    // ---------- T2（AC2）：アカウントの画面で変えた氏名・ふりがな・電話が、技術登録にも入る ----------
    public function test_t2_account_change_reaches_engineer(): void
    {
        $this->align();
        $u = $this->egUser(); $eid = (int) $u->engineer_id;
        $this->as('eg1@park.example')->post('/account', ['name_sei' => '新山', 'name_mei' => '次郎', 'kana_sei' => 'しんやま', 'kana_mei' => 'じろう', 'phone' => '080-1111-2222'])->assertRedirect(url('/account'));
        $this->assertSame(['新山 次郎', '新山', '次郎', 'しんやま じろう', 'しんやま', 'じろう', '080-1111-2222'], $this->engCols($eid));
        $this->assertSame($this->userCols((int) $u->id), $this->engCols($eid));
    }

    // ---------- T3（AC3）：技術登録で変えた氏名・ふりがなが、本人のアカウントにも入る（本人・SES・管理者のどれが変えても） ----------
    public function test_t3_engineer_edit_reaches_account_by_self_ses_and_admin(): void
    {
        $this->align();
        $u = $this->egUser(); $eid = (int) $u->engineer_id;
        $cases = [['eg1@park.example', 'いち', 'ほんにん', false], ['ses1@park.example', 'に', 'えすいーえす', true], ['admin@park.example', 'さん', 'かんりしゃ', true]];
        foreach ($cases as [$who, $sei, $mei, $clears]) {
            DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now_str()]);
            $this->as($who)->post("/engineers/$eid", ['name_sei' => '山'.$sei, 'name_mei' => '太郎', 'kana_sei' => $sei, 'kana_mei' => $mei])->assertSessionHas('flash.type', 'ok');
            $row = DB::table('users')->find($u->id);
            $this->assertSame(['山'.$sei, '太郎', $sei, $mei, $sei.' '.$mei], [$row->name_sei, $row->name_mei, $row->kana_sei, $row->kana_mei, $row->kana], $who);
            // 管理者・SES が直したときは、本人の「確認しました」を外す（今の動き）。本人が直したときは外さない（T10）
            $this->assertSame($clears, $this->eng($eid)->self_confirmed_at === null, $who);
        }
    }

    // ---------- T4（AC3）：詳しいプロフィールで変えた電話が、本人のアカウントにも入る ----------
    public function test_t4_profile_phone_reaches_account(): void
    {
        $this->align();
        $u = $this->egUser(); $eid = (int) $u->engineer_id;
        $this->as('eg1@park.example')->post("/engineers/$eid/profile", ['phone' => '070-9999-0000'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('070-9999-0000', DB::table('users')->find($u->id)->phone);
        $this->as('ses1@park.example')->post("/engineers/$eid/profile", ['phone' => '070-8888-0000'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('070-8888-0000', DB::table('users')->find($u->id)->phone);
    }

    // 担当者と、エンジニアを兼ねる人（SES 1 の担当者）を作る。兼任の人の担当者としての電話は会社のもの
    private function dualStaff(): array
    {
        $uid = W::createUser('dual-sync@example.com', '兼任 太郎', 'ses', 'park1234', ['ses_id' => 1]);
        $this->completeProfile($uid, ['phone' => '03-5555-0000', 'kana_sei' => 'かいしゃ', 'kana_mei' => 'ふう', 'kana' => 'かいしゃ ふう']);
        $admin = User::where('email', 'admin@park.example')->first();
        $eid = W::createEngineer(1, ['name_sei' => '兼任', 'name_mei' => '太郎', 'kana_sei' => 'けんにん', 'kana_mei' => 'たろう', 'phone' => '090-4444-0000', 'email' => 'dual-sync@example.com'], $admin, false);
        $this->assertSame($eid, (int) DB::table('users')->find($uid)->engineer_id); // 兼任になった
        return [$uid, $eid];
    }

    // ---------- T5（AC4）：兼任の人は同期の対象外 ----------
    public function test_t5_dual_role_is_not_synced_either_way(): void
    {
        [$uid, $eid] = $this->dualStaff();
        $before = $this->userCols($uid);
        // 技術登録を SES が直しても、兼任の人のアカウントは変わらない
        $this->as('ses1@park.example')->post("/engineers/$eid", ['name_sei' => '改名', 'name_mei' => '次郎', 'kana_sei' => 'かいめい', 'kana_mei' => 'じろう'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame($before, $this->userCols($uid));
        $this->assertSame('改名 次郎', $this->eng($eid)->name);
        // 兼任の人が「エンジニア」として入ってアカウントを保存しても、技術登録は変わらない
        DB::table('engineers')->where('id', $eid)->update(['postal_code' => '100-0001', 'address' => '東京都', 'consent_at' => now_str(), 'registered_at' => now_str(), 'self_confirmed_at' => now_str()]);
        $engBefore = $this->engCols($eid);
        $this->as('dual-sync@example.com')->withSession(['acting_role' => 'eg', TwoFactor::SESSION_KEY => $uid])
            ->post('/account', ['name_sei' => '会社側', 'name_mei' => '氏名', 'kana_sei' => 'かいしゃがわ', 'kana_mei' => 'しめい', 'phone' => '03-6666-0000'])->assertRedirect();
        $this->assertSame($engBefore, $this->engCols($eid));
    }

    // ---------- T6（AC5）：別のエンジニアのアカウントや、engineer_id が違うアカウントは変わらない ----------
    public function test_t6_other_accounts_are_untouched(): void
    {
        $this->align();
        $others = DB::table('users')->where('email', '!=', 'eg1@park.example')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
        $eid = (int) $this->egUser()->engineer_id;
        $this->as('ses1@park.example')->post("/engineers/$eid", ['name_sei' => '一人', 'name_mei' => '分だけ', 'kana_sei' => 'ひとり', 'kana_mei' => 'ぶんだけ'])->assertSessionHas('flash.type', 'ok');
        $this->as('eg1@park.example')->post('/account', ['kana_sei' => 'ほんにん', 'kana_mei' => 'だけ'])->assertRedirect();
        $this->assertEquals($others, DB::table('users')->where('email', '!=', 'eg1@park.example')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all());
    }

    // ---------- T7（AC6）：アカウントの欄が空なら、技術登録の値を欄に出す ----------
    public function test_t7_empty_account_fields_show_engineer_values(): void
    {
        $u = $this->egUser(); $eid = (int) $u->engineer_id;
        DB::table('engineers')->where('id', $eid)->update(['kana_sei' => 'ぎじゅつ', 'kana_mei' => 'とうろく', 'kana' => 'ぎじゅつ とうろく', 'phone' => '090-7777-6666']);
        DB::table('users')->where('id', $u->id)->update(['kana_sei' => null, 'kana_mei' => null, 'kana' => null, 'phone' => null]);
        $this->as('eg1@park.example')->get('/account')->assertOk()->assertSee('value="ぎじゅつ"', false)->assertSee('value="とうろく"', false)->assertSee('value="090-7777-6666"', false);
        $this->get('/account/profile')->assertOk()->assertSee('ぎじゅつ とうろく')->assertSee('090-7777-6666');
        // 空欄のまま保存しても、技術登録の値は消えない（欄に出ていた値がそのまま送られる）
        $this->post('/account', ['kana_sei' => 'ぎじゅつ', 'kana_mei' => 'とうろく', 'phone' => '090-7777-6666'])->assertRedirect();
        $this->assertSame(['ぎじゅつ', 'とうろく', '090-7777-6666'], [$this->eng($eid)->kana_sei, $this->eng($eid)->kana_mei, $this->eng($eid)->phone]);
    }

    // ---------- T8（AC7）：エンジニアを登録して、アカウントを作るとき、ふりがな・電話を写す ----------
    public function test_t8_new_account_copies_engineer_values(): void
    {
        $ses = User::where('email', 'ses1@park.example')->first();
        $eid = W::createEngineer(1, ['name_sei' => '写し', 'name_mei' => '太郎', 'kana_sei' => 'うつし', 'kana_mei' => 'たろう', 'phone' => '03-3333-4444', 'email' => 'copy-t8@example.com'], $ses);
        $u = DB::table('users')->where('email', 'copy-t8@example.com')->first();
        $this->assertSame([$eid, 'eg'], [(int) $u->engineer_id, $u->role]);
        $this->assertSame(['うつし', 'たろう', '03-3333-4444', null], [$u->kana_sei, $u->kana_mei, $u->phone, $u->registered_at]);
        // ログインの無いエンジニア（メールを後から入れた）の「送り直し」でも同じ
        $eid2 = W::createEngineer(1, ['name_sei' => '後', 'name_mei' => '入れ', 'kana_sei' => 'あと', 'kana_mei' => 'いれ', 'phone' => '03-5555-6666'], $ses);
        DB::table('engineers')->where('id', $eid2)->update(['email' => 'copy-t8b@example.com']);
        $this->as('ses1@park.example')->post("/engineers/$eid2/invite")->assertSessionHas('flash.type', 'ok');
        $u2 = DB::table('users')->where('email', 'copy-t8b@example.com')->first();
        $this->assertSame(['あと', 'いれ', '03-5555-6666'], [$u2->kana_sei, $u2->kana_mei, $u2->phone]);
    }

    // ---------- T9（AC8・S2-26）：食い違いをそろえるコマンド。既定は見るだけ、--apply で書き換える ----------
    public function test_t9_sync_command_dry_run_then_apply(): void
    {
        $this->align();
        $u1 = $this->egUser('eg1@park.example'); $u2 = $this->egUser('eg2@park.example');
        $e1 = (int) $u1->engineer_id; $e2 = (int) $u2->engineer_id;
        // a. 片方だけ値がある：アカウントのふりがなが空、技術登録にはある
        DB::table('engineers')->where('id', $e1)->update(['kana_sei' => 'かたがわ', 'kana_mei' => 'だけ', 'kana' => 'かたがわ だけ']);
        DB::table('users')->where('id', $u1->id)->update(['kana_sei' => null, 'kana_mei' => null, 'kana' => null]);
        // b. 両方に値があって違う：本人が登録を済ませていれば users、まだなら engineers が正
        DB::table('users')->where('id', $u2->id)->update(['name_sei' => 'ユーザーがわ', 'profile_completed_at' => now_str()]);
        DB::table('engineers')->where('id', $e2)->update(['name_sei' => 'ぎじゅつがわ']);
        $admin = User::where('email', 'admin@park.example')->first();
        $e3 = W::createEngineer(1, ['name_sei' => 'ぎじゅつ', 'name_mei' => '三郎', 'phone' => '03-0000-3333'], $admin, false);
        $u3 = W::createUser('eg3-t9@example.com', '三郎 ゆーざー', 'eg', 'park1234', ['ses_id' => 1, 'engineer_id' => $e3]);
        DB::table('users')->where('id', $u3)->update(['name_sei' => 'ゆーざー', 'name_mei' => '三郎', 'phone' => '03-0000-3333', 'profile_completed_at' => null]);
        [$dualUid, $dualEid] = $this->dualStaff();
        $dualBefore = [$this->userCols($dualUid), $this->engCols($dualEid)];

        // 引数なし：何も変えず、件数と id だけを出す（氏名・ふりがな・電話の中身は出さない）
        $usersBefore = DB::table('users')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
        $engBefore = DB::table('engineers')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
        $this->assertSame(0, Artisan::call('park:sync-eg-names'));
        $out = Artisan::output();
        $this->assertEquals($usersBefore, DB::table('users')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all());
        $this->assertEquals($engBefore, DB::table('engineers')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all());
        foreach (["{$u1->id}／{$e1}", "{$u2->id}／{$e2}", "{$u3}／{$e3}"] as $pair) $this->assertStringContainsString($pair, $out);
        foreach (['かたがわ', 'ユーザーがわ', 'ぎじゅつがわ', 'ゆーざー', '03-0000-3333'] as $secret) $this->assertStringNotContainsString($secret, $out);
        $this->assertStringContainsString('--apply', $out);

        // --apply：書き換える
        $this->assertSame(0, Artisan::call('park:sync-eg-names', ['--apply' => true]));
        $this->assertSame(['かたがわ', 'だけ', 'かたがわ だけ'], [DB::table('users')->find($u1->id)->kana_sei, DB::table('users')->find($u1->id)->kana_mei, DB::table('users')->find($u1->id)->kana]);
        $this->assertSame(['ユーザーがわ', 'ユーザーがわ'], [DB::table('users')->find($u2->id)->name_sei, $this->eng($e2)->name_sei]); // 登録済みなら users が正
        $this->assertSame(['ぎじゅつ', 'ぎじゅつ'], [DB::table('users')->find($u3)->name_sei, $this->eng($e3)->name_sei]); // まだなら engineers が正
        $this->assertSame('ぎじゅつ 三郎', DB::table('users')->find($u3)->name);
        // 兼任の人は触らない
        $this->assertSame($dualBefore, [$this->userCols($dualUid), $this->engCols($dualEid)]);
    }

    // ---------- T10（AC9）：本人が保存しても、本人の「確認しました」は消えない ----------
    public function test_t10_self_save_keeps_self_confirmed(): void
    {
        $u = $this->egUser(); $eid = (int) $u->engineer_id;
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => '2026-10-01 10:00:00']);
        $this->as('eg1@park.example')->post('/account', ['kana_sei' => 'かくにん', 'kana_mei' => 'ずみ', 'phone' => '090-0101-0101'])->assertRedirect();
        $this->assertSame('2026-10-01 10:00:00', $this->eng($eid)->self_confirmed_at);
        // 足りない項目の画面で入れても消えない（ふりがなが空だったとき）
        DB::table('engineers')->where('id', $eid)->update(['kana_sei' => null, 'kana_mei' => null, 'kana' => null]);
        DB::table('users')->where('id', $u->id)->update(['kana_sei' => null, 'kana_mei' => null, 'kana' => null]);
        $this->post('/register/complete', ['kana_sei' => 'もういちど', 'kana_mei' => 'ためす'])->assertRedirect();
        $this->assertNotNull($this->eng($eid)->self_confirmed_at);
    }
}
