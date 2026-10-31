<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Matcher;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.2.0 段階 2：SES（代表者・担当者）→ 担当者・エンジニアの仮登録 → 本登録（PLAN 2026-10-31 の S2-01〜S2-04・S2-07〜S2-19・S2-27・S2-28・S2-30）。
// 見本の SES 1（みどり情報システム）の代表者は ses1@park.example。アドレスはすべて example.com か見本のもの
class SesToPeopleRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    // ログインしていない状態にする（メールのリンクを開く人）
    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function row(string $email): ?object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    // 会社 1 に、本登録が済んだ担当者を足す（代表者ではない）
    private function staff(string $email = 'mate@example.com', string $name = '同僚 一郎'): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => 1]);
        $this->completeProfile($id);
        return $id;
    }

    // 担当者を仮登録する（代表者・担当者・管理者のどれか）。users の行を返す
    private function provisionStaff(string $who, string $email, array $o = []): object
    {
        $url = $who === 'admin@park.example' ? '/admin/ses/1/users' : '/ses/company/users';
        $this->as($who)->post($url, $o + ['name_sei' => '新人', 'name_mei' => '担当', 'email' => $email])->assertSessionHas('flash.type', 'ok');
        return $this->row($email);
    }

    // エンジニアを仮登録する。users の行を返す
    private function provisionEg(string $who, string $email, array $o = []): object
    {
        $extra = $who === 'admin@park.example' ? ['ses_id' => '1'] : [];
        $this->as($who)->post('/engineers', $o + $extra + ['name_sei' => '新人', 'name_mei' => '技術', 'email' => $email])->assertSessionHas('flash.type', 'ok');
        return $this->row($email);
    }

    private function staffPayload(array $o = []): array
    {
        return $o + ['name_sei' => '新人', 'name_mei' => '担当', 'phone' => '03-1111-2222', 'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
    }

    private function egPayload(array $o = []): array
    {
        return $o + ['name_sei' => '新人', 'name_mei' => '技術', 'kana_sei' => 'ほんにん', 'kana_mei' => 'たろう', 'phone' => '090-1111-2222', 'postal_code' => '100-0001',
            'address' => '東京都千代田区1-1', 'consent' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
    }

    // 本登録を済ませる（確認コードまで）。済んだら、ログインしていない状態に戻る
    private function finish(string $token, array $payload): void
    {
        $this->guest();
        $this->post('/invite/'.$token, $payload)->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
        $this->guest();
    }

    // ---------- S2-01〜S2-04・S2-07：登録できる人・できない人 ----------

    public function test_s2_01_rep_registers_staff(): void
    {
        $u = $this->provisionStaff('ses1@park.example', 'newstaff@example.com');
        $this->assertSame('ses', $u->role);
        $this->assertSame(1, (int) $u->ses_id);
        $this->assertSame($this->uid('ses1@park.example'), (int) $u->registered_by);
        $this->assertNull($u->registered_at);
        $this->assertNull($u->password);
        $this->assertSame('register', $u->token_kind);
        $m = DB::table('mail_outbox')->where('to_email', 'newstaff@example.com')->get();
        $this->assertCount(1, $m);
        $this->assertStringContainsString('仮登録のご案内', $m[0]->subject);
        foreach (['/invite/'.$u->token, '7日以内', 'SES のご担当者'] as $part) $this->assertStringContainsString($part, $m[0]->body);
    }

    public function test_s2_02_rep_registers_eg(): void
    {
        $u = $this->provisionEg('ses1@park.example', 'neweg@example.com');
        $e = DB::table('engineers')->where('email', 'neweg@example.com')->first();
        $this->assertNotNull($e);
        $this->assertSame('eg', $u->role);
        $this->assertSame((int) $e->id, (int) $u->engineer_id);
        $this->assertSame([1, 1], [(int) $e->ses_id, (int) $u->ses_id]);
        $this->assertNull($e->registered_at);
        $this->assertNull($u->registered_at);
        $this->assertSame(1, DB::table('users')->where('email', 'neweg@example.com')->count());
        $this->assertSame(1, DB::table('engineers')->where('email', 'neweg@example.com')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'neweg@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
    }

    public function test_s2_03_staff_registers_eg(): void
    {
        $staff = $this->staff();
        $u = $this->provisionEg('mate@example.com', 'eg-by-staff@example.com');
        $this->assertSame($staff, (int) $u->registered_by);
        $this->assertSame('eg', $u->role);
    }

    public function test_s2_04_staff_cannot_register_staff(): void
    {
        $this->staff();
        $n = DB::table('users')->count();
        $this->as('mate@example.com')->get('/ses/company/users/new')->assertForbidden();
        $this->post('/ses/company/users', ['name_sei' => '不可', 'name_mei' => '登録', 'email' => 'nope@example.com'])->assertForbidden();
        $this->assertSame($n, DB::table('users')->count());
    }

    public function test_s2_07_ses_people_cannot_register_into_other_companies(): void
    {
        $this->staff();
        foreach (['ses1@park.example', 'mate@example.com'] as $who) {
            $this->as($who)->post('/engineers', ['ses_id' => '2', 'name_sei' => '他社', 'name_mei' => $who === 'mate@example.com' ? 'ニ' : 'イチ', 'email' => 'other-'.strtok($who, '@').'@example.com'])->assertSessionHas('flash.type', 'ok');
            $u = $this->row('other-'.strtok($who, '@').'@example.com');
            $this->assertSame(1, (int) $u->ses_id);
            $this->assertSame(1, (int) DB::table('engineers')->find($u->engineer_id)->ses_id);
        }
        // 担当者の登録は代表者だけ。会社の指定を混ぜても自社に入る
        $this->as('ses1@park.example')->post('/ses/company/users', ['ses_id' => '2', 'name_sei' => '他社', 'name_mei' => '担当', 'email' => 'other-staff@example.com'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, (int) $this->row('other-staff@example.com')->ses_id);
        // 管理者の入口は使えない
        $this->post('/admin/ses/2/users', ['name_sei' => '他社', 'name_mei' => '担当', 'email' => 'other-staff2@example.com'])->assertForbidden();
        $this->assertNull($this->row('other-staff2@example.com'));
    }

    // ---------- S2-08〜S2-10：本登録 ----------

    public function test_s2_08_staff_registration_needs_each_item(): void
    {
        $u = $this->provisionStaff('ses1@park.example', 'st8@example.com');
        $this->guest();
        $this->get('/invite/'.$u->token)->assertOk()->assertSee('守秘義務')->assertSee('みどり情報システム');
        foreach (['name_sei', 'name_mei', 'phone', 'agree', 'password'] as $k) {
            $this->post('/invite/'.$u->token, array_diff_key($this->staffPayload(), [$k => 1]))->assertSessionHasErrors($k);
        }
        $this->assertNull($this->row('st8@example.com')->registered_at);
        $company = DB::table('ses_companies')->where('id', 1)->value('name');
        // 企業名は見るだけ（送られても無視する）
        $this->finish($u->token, $this->staffPayload(['ses_name' => '乗っ取り商事', 'company_name' => '乗っ取り']));
        $r = $this->row('st8@example.com');
        $this->assertNotNull($r->registered_at);
        $this->assertNotNull($r->secrecy_agreed_at);
        $this->assertSame('03-1111-2222', $r->phone);
        $this->assertSame($company, DB::table('ses_companies')->where('id', 1)->value('name'));
    }

    public function test_s2_09_eg_registration_fills_both_tables_and_needs_each_item(): void
    {
        $u = $this->provisionEg('ses1@park.example', 'eg9@example.com');
        $eid = (int) $u->engineer_id;
        $this->guest();
        foreach (['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'phone', 'postal_code', 'address', 'consent', 'password'] as $k) {
            $this->post('/invite/'.$u->token, array_diff_key($this->egPayload(), [$k => 1]))->assertSessionHasErrors($k);
        }
        $this->assertNull(DB::table('engineers')->find($eid)->registered_at);
        $this->finish($u->token, $this->egPayload());
        $e = DB::table('engineers')->find($eid);
        $r = DB::table('users')->find($u->id);
        foreach ([$e, $r] as $x) {
            $this->assertSame(['ほんにん', 'たろう', '090-1111-2222'], [$x->kana_sei, $x->kana_mei, $x->phone]);
        }
        $this->assertSame(['100-0001', '東京都千代田区1-1'], [$e->postal_code, $e->address]);
        foreach (['consent_at', 'self_confirmed_at', 'registered_at'] as $c) $this->assertNotNull($e->$c, $c);
        // 「確認しました」を別に押さなくても、エンジニアのホームに入れる
        $this->as('eg9@example.com')->get('/eg')->assertOk();
        $this->as('ses1@park.example')->get("/engineers/$eid/edit")->assertOk()->assertSee('value="ほんにん"', false);
        $this->get("/engineers/$eid")->assertOk()->assertSee('東京都千代田区1-1');
    }

    public function test_s2_10_staff_and_eg_cannot_change_company_and_corrections_are_told(): void
    {
        $u = $this->provisionStaff('ses1@park.example', 'st10@example.com');
        $this->finish($u->token, $this->staffPayload(['name_sei' => '修正', 'ses_name' => '乗っ取り商事']));
        $this->assertSame('みどり情報システム', DB::table('ses_companies')->where('id', 1)->value('name'));
        $note = DB::table('app_notifications')->where('user_id', $this->uid('ses1@park.example'))->where('title', 'like', '本登録が完了しました%')->first();
        $this->assertNotNull($note);
        $this->assertStringContainsString('氏名：新人 担当 → 修正 担当', $note->body);

        $e = $this->provisionEg('ses1@park.example', 'eg10@example.com');
        $this->finish($e->token, $this->egPayload(['company_name' => '乗っ取り']));
        $this->assertSame('みどり情報システム', DB::table('ses_companies')->where('id', 1)->value('name'));
        $this->assertNull(DB::table('users')->where('id', $e->id)->value('company_name'));
    }

    // ---------- S2-11：上位への知らせ（5つの組み合わせ） ----------

    private function doneNotes(int $userId): int
    {
        return DB::table('app_notifications')->where('user_id', $userId)->where('title', 'like', '本登録が完了しました%')->count();
    }

    private function doneMails(string $email): int
    {
        return DB::table('mail_outbox')->where('to_email', $email)->where('subject', 'like', '%本登録が完了しました%')->count();
    }

    public function test_s2_11_a_staff_registers_eg_tells_staff_and_rep_once_each(): void
    {
        $staff = $this->staff();
        $rep = $this->uid('ses1@park.example');
        $u = $this->provisionEg('mate@example.com', 'eg11a@example.com');
        $this->finish($u->token, $this->egPayload());
        $this->assertSame([1, 1], [$this->doneNotes($staff), $this->doneNotes($rep)]);
        $this->assertSame([1, 1], [$this->doneMails('mate@example.com'), $this->doneMails('ses1@park.example')]);
        $this->assertSame(0, $this->doneNotes($this->uid('admin@park.example')));
    }

    public function test_s2_11_b_rep_registers_eg_tells_rep_once(): void
    {
        $staff = $this->staff();
        $rep = $this->uid('ses1@park.example');
        $u = $this->provisionEg('ses1@park.example', 'eg11b@example.com');
        $this->finish($u->token, $this->egPayload());
        $this->assertSame([1, 0], [$this->doneNotes($rep), $this->doneNotes($staff)]);
        $this->assertSame(1, $this->doneMails('ses1@park.example'));
    }

    public function test_s2_11_c_admin_registers_eg_tells_that_admin_and_rep_not_every_admin(): void
    {
        $second = W::createUser('admin2@example.com', '第二 管理', 'admin', 'park1234');
        $this->completeProfile($second);
        $rep = $this->uid('ses1@park.example');
        $u = $this->provisionEg('admin@park.example', 'eg11c@example.com');
        $this->finish($u->token, $this->egPayload());
        $this->assertSame([1, 1, 0], [$this->doneNotes($this->uid('admin@park.example')), $this->doneNotes($rep), $this->doneNotes($second)]);
    }

    public function test_s2_11_d_stopped_registrar_goes_to_the_rep(): void
    {
        $staff = $this->staff();
        $u = $this->provisionEg('mate@example.com', 'eg11d@example.com');
        DB::table('users')->where('id', $staff)->update(['active' => 0]);
        $this->finish($u->token, $this->egPayload());
        $this->assertSame([0, 1, 0], [$this->doneNotes($staff), $this->doneNotes($this->uid('ses1@park.example')), $this->doneNotes($this->uid('admin@park.example'))]);
    }

    public function test_s2_11_e_nobody_usable_goes_to_all_admins(): void
    {
        $second = W::createUser('admin2@example.com', '第二 管理', 'admin', 'park1234');
        $this->completeProfile($second);
        $staff = $this->staff();
        $u = $this->provisionEg('mate@example.com', 'eg11e@example.com');
        DB::table('users')->whereIn('id', [$staff, $this->uid('ses1@park.example')])->update(['active' => 0]);
        $this->finish($u->token, $this->egPayload());
        $this->assertSame([1, 1], [$this->doneNotes($this->uid('admin@park.example')), $this->doneNotes($second)]);
        $this->assertSame([0, 0], [$this->doneNotes($staff), $this->doneNotes($this->uid('ses1@park.example'))]);
    }

    // ---------- S2-12・S2-13 ----------

    public function test_s2_12_provisional_eg_is_not_a_candidate_until_registered(): void
    {
        $u = $this->provisionEg('ses1@park.example', 'eg12@example.com');
        $eid = (int) $u->engineer_id;
        $caseId = (int) DB::table('cases')->value('id');
        $ids = fn () => array_map(fn ($r) => $r['engineer']->id, Matcher::forCase($caseId, true));
        $this->assertNotContains($eid, $ids());
        $this->finish($u->token, $this->egPayload());
        $this->assertContains($eid, $ids());
    }

    public function test_s2_13_states_are_shown_and_empty_lists_open(): void
    {
        $p = $this->provisionStaff('ses1@park.example', 'st13@example.com');
        $this->provisionEg('ses1@park.example', 'eg13@example.com');
        foreach (['/ses/company', '/ses/engineers'] as $url) $this->as('ses1@park.example')->get($url)->assertOk()->assertSee('仮登録');
        $this->as('admin@park.example');
        foreach (['/admin/engineers', '/admin/ses/1', "/admin/ses/1/users/{$p->id}"] as $url) $this->get($url)->assertOk()->assertSee('仮登録');
        // 担当者の姓名は users から出す
        $this->get('/admin/ses/1')->assertSee('新人 担当');
        // エンジニアも担当者の記録も無い会社でも、画面が開く
        $sid = DB::table('ses_companies')->insertGetId(['name' => '空の会社', 'kind' => 'ses', 'status' => 'active', 'agreed_at' => now_str(), 'self_confirmed_at' => now_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
        $id = W::createUser('empty13@example.com', '空 担当', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($id);
        $this->as('empty13@example.com');
        foreach (['/ses', '/ses/engineers', '/ses/company'] as $url) $this->get($url)->assertOk();
    }

    // ---------- S2-14：送り直しの権限 ----------

    public function test_s2_14_resend_permissions(): void
    {
        $this->staff();
        $p = $this->provisionStaff('ses1@park.example', 'p14@example.com');
        $e = $this->provisionEg('ses1@park.example', 'e14@example.com');
        // [実行者, 対象の users.id, 結果（ok か状態コード）]
        $cases = [
            ['admin@park.example', $p->id, 'ok'], ['admin@park.example', $e->id, 'ok'],
            ['ses1@park.example', $p->id, 'ok'], ['ses1@park.example', $e->id, 'ok'],
            ['mate@example.com', $e->id, 'ok'], ['mate@example.com', $p->id, 403],
            ['ses2@park.example', $p->id, 404], ['ses2@park.example', $e->id, 404],
            ['eg1@park.example', $e->id, 403],
        ];
        foreach ($cases as [$who, $id, $want]) {
            $res = $this->as($who)->post("/people/$id/resend");
            if ($want === 'ok') {
                $res->assertRedirect();
                $this->assertSame('ok', session('flash')['type'] ?? null, "$who → $id");
            } else {
                $res->assertStatus($want);
            }
        }
    }

    // ---------- S2-15・S2-16：仮登録中の直しと守り ----------

    public function test_s2_15_editing_a_provisional_person_keeps_one_row(): void
    {
        $e = $this->provisionEg('ses1@park.example', 'e15@example.com');
        $old = $e->token;
        $this->as('ses1@park.example')->post("/people/{$e->id}", ['name_sei' => '直し', 'name_mei' => '技術', 'email' => 'e15b@example.com'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('users')->where('engineer_id', $e->engineer_id)->count());
        $this->assertSame('e15b@example.com', DB::table('users')->where('id', $e->id)->value('email'));
        $this->assertSame('e15b@example.com', DB::table('engineers')->where('id', $e->engineer_id)->value('email'));
        $this->assertSame('直し 技術', DB::table('engineers')->where('id', $e->engineer_id)->value('name'));
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'e15b@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
        $this->guest();
        $this->get('/invite/'.$old)->assertOk()->assertSee(T::LINK_UNUSABLE);

        // 代表者が所属を混ぜても変わらない
        $this->as('ses1@park.example')->post("/people/{$e->id}", ['name_sei' => '直し', 'name_mei' => '技術', 'email' => 'e15b@example.com', 'ses_id' => '2'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame([1, 1], [(int) DB::table('users')->find($e->id)->ses_id, (int) DB::table('engineers')->find($e->engineer_id)->ses_id]);
        // 管理者は参加中の SES に直せる（2か所がそろう）。参加中でない SES（申込中）は断る
        $this->as('admin@park.example')->post("/people/{$e->id}", ['name_sei' => '直し', 'name_mei' => '技術', 'email' => 'e15b@example.com', 'ses_id' => '2'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame([2, 2], [(int) DB::table('users')->find($e->id)->ses_id, (int) DB::table('engineers')->find($e->engineer_id)->ses_id]);
        $this->post("/people/{$e->id}", ['name_sei' => '直し', 'name_mei' => '技術', 'email' => 'e15b@example.com', 'ses_id' => '4'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame(2, (int) DB::table('users')->find($e->id)->ses_id);
    }

    public function test_s2_16_upper_roles_cannot_fill_technical_items_of_a_provisional_eg(): void
    {
        $u = $this->provisionEg('ses1@park.example', 'e16@example.com');
        $eid = (int) $u->engineer_id;
        $go = url("/people/{$u->id}/edit");
        foreach (['ses1@park.example', 'admin@park.example'] as $who) {
            $this->as($who);
            $this->get("/engineers/$eid/edit")->assertRedirect($go);
            $this->post("/engineers/$eid", ['name_sei' => '書換', 'name_mei' => '不可'])->assertRedirect($go);
            $this->post("/engineers/$eid/profile", ['phone' => '090-0000-0000', 'postal_code' => '100-0001', 'address' => 'どこか'])->assertRedirect($go);
            $this->post("/engineers/$eid/skills", ['skill_name' => ['Java'], 'skill_cat' => ['lang'], 'skill_years' => ['3'], 'skill_level' => ['3']])->assertRedirect($go);
            $this->post("/engineers/$eid/projects", ['title' => '不可の案件'])->assertRedirect($go);
        }
        $e = DB::table('engineers')->find($eid);
        $this->assertSame('新人', $e->name_sei);
        $this->assertNull($e->phone);
        $this->assertSame(0, DB::table('engineer_skills')->where('engineer_id', $eid)->count());
        $this->assertSame(0, DB::table('engineer_projects')->where('engineer_id', $eid)->count());

        // 本登録のあと：上位が直すと本人の確認が外れ、本人が直しても外れない
        $this->finish($u->token, $this->egPayload());
        $this->as('ses1@park.example')->post("/engineers/$eid", ['name_sei' => '本人', 'name_mei' => '太郎'])->assertSessionHas('flash.type', 'ok');
        $this->assertNull(DB::table('engineers')->find($eid)->self_confirmed_at);
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now_str()]);
        $this->as('e16@example.com')->post("/engineers/$eid", ['name_sei' => '本人', 'name_mei' => '太郎'])->assertSessionHas('flash.type', 'ok');
        $this->assertNotNull(DB::table('engineers')->find($eid)->self_confirmed_at);
    }

    // ---------- S2-17・S2-18・S2-19 ----------

    public function test_s2_17_decline_invalidates_the_link_and_tells_the_registrar(): void
    {
        $u = $this->provisionEg('ses1@park.example', 'e17@example.com');
        $this->guest();
        $this->post('/invite/'.$u->token.'/decline')->assertOk();
        $r = $this->row('e17@example.com');
        $this->assertNull($r->token);
        $this->assertNotNull($r->reg_declined_at);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->uid('ses1@park.example'))->where('title', 'like', '「心当たりがない」%')->count());
        $this->get('/invite/'.$u->token)->assertSee(T::LINK_UNUSABLE);
    }

    public function test_s2_18_ses_people_get_the_same_refusal_for_every_reason(): void
    {
        $this->staff('stopped18@example.com', '停止 太郎');
        DB::table('users')->where('email', 'stopped18@example.com')->update(['active' => 0]);
        $this->staff('left18@example.com', '退会 太郎');
        DB::table('users')->where('email', 'left18@example.com')->update(['active' => 0, 'left_at' => now_str()]);
        $this->provisionStaff('ses1@park.example', 'prov18@example.com');
        // 他社の使用中・仮登録中・停止中・退会済：どれも同じ文
        foreach (['ses2@park.example', 'prov18@example.com', 'stopped18@example.com', 'left18@example.com'] as $i => $email) {
            $before = DB::table('audit_log')->where('action', 'reg-refused')->count();
            $this->as('ses1@park.example')->post('/ses/company/users', ['name_sei' => '拒否', 'name_mei' => '確認', 'email' => $email]);
            $this->assertSame(['bad', T::REFUSED_EMAIL], [session('flash')['type'], session('flash')['msg']], $email);
            $this->assertSame($before + 1, DB::table('audit_log')->where('action', 'reg-refused')->count(), $email);
            $this->post('/engineers', ['name_sei' => '拒否', 'name_mei' => '確認', 'email' => $email]);
            $this->assertSame(T::REFUSED_EMAIL, session('flash')['msg'], $email);
        }
        // 記録にアドレスは書かない
        $this->assertStringNotContainsString('@', (string) DB::table('audit_log')->where('action', 'reg-refused')->get()->pluck('detail')->implode(''));
        // 管理者には理由を出す
        $this->as('admin@park.example')->post('/admin/ses/1/users', ['name_sei' => '拒否', 'name_mei' => '確認', 'email' => 'stopped18@example.com']);
        $this->assertStringContainsString('停止中', session('flash')['msg']);
        // 同じ SES の本登録済みのエンジニアのアドレスなら、担当者の役割が足される（兼任）
        $this->as('ses1@park.example')->post('/ses/company/users', ['name_sei' => '山田', 'name_mei' => '太郎', 'email' => 'eg1@park.example'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('ses', $this->row('eg1@park.example')->role);
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'eg1@park.example')->where('subject', 'like', '%仮登録のご案内%')->count());
    }

    public function test_s2_18_refusals_are_rate_limited(): void
    {
        $this->as('ses1@park.example');
        for ($i = 0; $i < 20; $i++) $this->post('/ses/company/users', ['name_sei' => '制限', 'name_mei' => '確認', 'email' => 'abc']);
        $this->post('/ses/company/users', ['name_sei' => '制限', 'name_mei' => '確認', 'email' => 'abc'])->assertStatus(429);
    }

    public function test_s2_19_links_need_an_active_company(): void
    {
        $this->staff('reg19@example.com');
        $p = $this->provisionStaff('ses1@park.example', 'p19@example.com');
        $e = $this->provisionEg('ses1@park.example', 'e19@example.com');
        DB::table('ses_companies')->where('id', 1)->update(['status' => 'suspended']);
        $this->guest();
        foreach ([$p, $e] as $x) $this->get('/invite/'.$x->token)->assertOk()->assertSee(T::LINK_UNUSABLE);
        $this->post('/forgot', ['email' => 'reg19@example.com'])->assertOk();
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'reg19@example.com')->where('kind', 'reset')->count());
        foreach ([$p, $e] as $x) $this->as('admin@park.example')->post("/people/{$x->id}/resend")->assertSessionHas('flash.type', 'bad');
        // 再開すると、元のリンクが期限内なら使える
        DB::table('ses_companies')->where('id', 1)->update(['status' => 'active']);
        $this->guest();
        $this->get('/invite/'.$p->token)->assertOk()->assertSee('本登録');
        $this->post('/forgot', ['email' => 'reg19@example.com'])->assertOk();
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'reg19@example.com')->where('kind', 'reset')->count());
    }

    // ---------- S2-27・S2-28・S2-30 ----------

    public function test_s2_27_eg_required_items_cannot_be_emptied(): void
    {
        $u = $this->provisionEg('ses1@park.example', 'e27@example.com');
        $eid = (int) $u->engineer_id;
        $this->finish($u->token, $this->egPayload());
        $keep = DB::table('engineers')->find($eid);
        foreach (['ses1@park.example', 'admin@park.example', 'e27@example.com'] as $who) {
            foreach (['name_sei' => '/engineers/'.$eid, 'kana_sei' => '/engineers/'.$eid, 'phone' => '/engineers/'.$eid, 'postal_code' => '/engineers/'.$eid.'/profile', 'address' => '/engineers/'.$eid.'/profile'] as $key => $url) {
                $this->as($who)->post($url, [$key => '']);
                $this->assertSame('bad', session('flash')['type'] ?? null, "$who $key");
            }
        }
        $this->as('e27@example.com')->post('/account', ['phone' => ''])->assertSessionHas('flash.type', 'bad');
        $now = DB::table('engineers')->find($eid);
        foreach (['name_sei', 'kana_sei', 'phone', 'postal_code', 'address'] as $c) $this->assertSame($keep->$c, $now->$c, $c);
    }

    public function test_s2_28_staff_sees_company_read_only_and_rep_sees_the_buttons(): void
    {
        $this->staff('mate@example.com');
        $mate2 = $this->staff('mate2@example.com', '同僚 二郎');
        $eid = (int) DB::table('users')->where('email', 'eg1@park.example')->value('engineer_id');
        $r = $this->as('mate@example.com')->get('/ses/company')->assertOk();
        $r->assertDontSee('担当者を登録')->assertDontSee('/ses/company/edit', false)->assertDontSee("/admin/ses/1/users/$mate2/suspend");
        $r->assertSee("/engineers/$eid/suspend"); // エンジニアの操作は担当者もできる
        $rep = $this->as('ses1@park.example')->get('/ses/company')->assertOk();
        $rep->assertSee('担当者を登録')->assertSee('/ses/company/edit', false)->assertSee("/admin/ses/1/users/$mate2/suspend");
    }

    public function test_s2_30_cancel_permissions_and_records(): void
    {
        $this->staff();
        $p = $this->provisionStaff('ses1@park.example', 'p30@example.com');
        $e = $this->provisionEg('ses1@park.example', 'e30@example.com');
        $e2 = $this->provisionEg('ses1@park.example', 'e30b@example.com');
        $this->as('mate@example.com')->post("/people/{$p->id}/cancel")->assertForbidden();
        $this->as('ses2@park.example')->post("/people/{$e->id}/cancel")->assertNotFound();
        $this->as('mate@example.com')->post("/people/{$e->id}/cancel")->assertRedirect('/ses/engineers');
        $this->assertNull(DB::table('users')->find($e->id));
        $this->assertNull(DB::table('engineers')->find($e->engineer_id));
        $log = (string) DB::table('audit_log')->where('action', 'reg-cancel')->orderByDesc('id')->value('detail');
        $this->assertStringNotContainsString('e30@example.com', $log);
        $this->assertStringNotContainsString('新人', $log);
        $this->as('ses1@park.example')->post("/people/{$p->id}/cancel")->assertRedirect('/ses/company');
        $this->assertNull(DB::table('users')->find($p->id));
        // やり取りなどの記録があるエンジニアは、取り消せない（両方の行が残る）
        DB::table('messages')->insert(['engineer_id' => $e2->engineer_id, 'from_user' => null, 'body' => '記録', 'created_at' => now_str()]);
        $this->as('admin@park.example')->post("/people/{$e2->id}/cancel")->assertSessionHas('flash.type', 'bad');
        $this->assertNotNull(DB::table('users')->find($e2->id));
        $this->assertNotNull(DB::table('engineers')->find($e2->engineer_id));
    }
}
