<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Matcher;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.2.0 段階 2：管理者 → 担当者・エンジニアの仮登録（PLAN 2026-10-31 の S2-05・S2-06・S2-24・S2-31）。見本の SES 1 の SES は ses1@park.example
class AdminToPeopleRegistrationTest extends TestCase
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

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function company(string $name, string $status): int
    {
        return (int) DB::table('ses_companies')->insertGetId(['name' => $name, 'kind' => 'ses', 'status' => $status, 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    private function egPayload(array $o = []): array
    {
        return $o + ['name_sei' => '直属', 'name_mei' => '一郎', 'kana_sei' => 'ちょくぞく', 'kana_mei' => 'いちろう', 'phone' => '090-1111-2222', 'postal_code' => '100-0001',
            'address' => '東京都千代田区1-1', 'consent' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
    }

    private function finish(string $token, array $payload): void
    {
        $this->guest();
        $this->post('/invite/'.$token, $payload)->assertRedirect(url('/two-factor/challenge'));
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();
        $this->guest();
    }

    // ---------- S2-05：担当者は参加中の SES だけ ----------

    public function test_s2_05_admin_registers_staff_only_for_active_companies(): void
    {
        $this->as('admin@park.example');
        $n = DB::table('users')->count();
        foreach ([$this->company('仮の会社', 'provisional'), $this->company('停止の会社', 'suspended'), 4 /* 見本の申込中の会社 */] as $sid) {
            $this->get("/admin/ses/$sid/users/new")->assertRedirect("/admin/ses/$sid");
            $this->post("/admin/ses/$sid/users", ['name_sei' => '断り', 'name_mei' => '確認', 'email' => 'refuse5-'.$sid.'@example.com'])->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame($n, DB::table('users')->count());

        $this->get('/admin/ses/1/users/new')->assertOk();
        $this->post('/admin/ses/1/users', ['name_sei' => '管理', 'name_mei' => '登録', 'email' => 'staff5@example.com'])->assertSessionHas('flash.type', 'ok');
        $u = DB::table('users')->where('email', 'staff5@example.com')->first();
        $this->assertSame(['ses', 1, $this->uid('admin@park.example')], [$u->role, (int) $u->ses_id, (int) $u->registered_by]);
        $this->assertNull($u->registered_at);
        // 所属 SES にメールとベル
        $rep = $this->uid('ses1@park.example');
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $rep)->where('title', 'like', '管理者が%を仮登録しました%')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'ses1@park.example')->where('subject', 'like', '%仮登録しました%')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'staff5@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
    }

    // ---------- S2-06：エンジニアはその場でメールが届く。直属は選べない ----------

    public function test_s2_06_admin_registers_eg_and_mail_goes_out_at_once(): void
    {
        $this->as('admin@park.example');
        $this->get('/engineers/new')->assertOk()->assertSee('name="ses_id"', false); // SES を指定しないときは、参加中の SES から選ぶ
        $this->post('/engineers', ['ses_id' => '1', 'name_sei' => '管理', 'name_mei' => '登録', 'email' => 'e6@example.com'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'e6@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->uid('ses1@park.example'))->where('title', 'like', '管理者が%を仮登録しました%')->count());
        $e = DB::table('engineers')->where('email', 'e6@example.com')->first();
        $this->assertSame(1, (int) $e->ses_id);
        $this->assertNull($e->registered_at);

        // 直属・参加中でない SES は選べない
        $direct = W::directSesId();
        $before = DB::table('engineers')->count();
        foreach ([(string) $direct, 'direct', '4', '99999'] as $bad) {
            $this->post('/engineers', ['ses_id' => $bad, 'name_sei' => '断り', 'name_mei' => '確認', 'email' => 'refuse6@example.com'])->assertSessionHas('flash.type', 'bad');
        }
        $this->assertSame($before, DB::table('engineers')->count());
    }

    // ---------- S2-24：兼任の役割を足す ----------

    public function test_s2_24_dual_role_is_added_then_missing_items_are_asked_at_the_next_login(): void
    {
        $uid = W::createUser('dual24@example.com', '兼任 花子', 'ses', 'park1234', ['ses_id' => 1]);
        $this->completeProfile($uid);
        $this->as('ses1@park.example')->post('/engineers', ['name_sei' => '兼任', 'name_mei' => '花子', 'email' => 'dual24@example.com'])->assertSessionHas('flash.type', 'ok');
        $u = DB::table('users')->find($uid);
        $this->assertNotNull($u->engineer_id);
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $uid)->where('title', 'エンジニアとしても登録されました')->count());
        $this->assertSame(0, DB::table('mail_outbox')->where('to_email', 'dual24@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());
        $this->assertNull(DB::table('engineers')->find($u->engineer_id)->registered_at);

        // エンジニアの立場で入ると、足りない項目の画面に回る
        $this->as('dual24@example.com')->withSession(['acting_role' => 'eg', TwoFactor::SESSION_KEY => $uid])->get('/eg')->assertRedirect('/register/complete');
        $this->post('/register/complete', ['kana_sei' => 'けんにん', 'kana_mei' => 'はなこ', 'phone' => '090-0000-0000', 'postal_code' => '100-0001', 'address' => '東京都', 'consent' => '1'])->assertRedirect();
        $this->assertNotNull(DB::table('engineers')->find($u->engineer_id)->registered_at);
        // 担当者の電話は、エンジニアの電話で書き換わらない
        $this->assertSame('03-0000-0009', DB::table('users')->find($uid)->phone);
        $caseId = (int) DB::table('cases')->value('id');
        $this->assertContains((int) $u->engineer_id, array_map(fn ($r) => $r['engineer']->id, Matcher::forCase($caseId, true)));
    }

    // ---------- S2-31：今いる管理者の直属のエンジニア ----------

    public function test_s2_31_existing_direct_eg_goes_through_the_same_flow(): void
    {
        $second = W::createUser('admin2@example.com', '第二 管理', 'admin', 'park1234');
        $this->completeProfile($second);
        $admin = User::where('email', 'admin@park.example')->first();
        $direct = W::directSesId();
        W::createEngineer($direct, ['name' => '直属 一郎', 'email' => 'd31@example.com'], $admin); // 今ある形（仮登録のアカウントと、送ったリンク）
        $u = DB::table('users')->where('email', 'd31@example.com')->first();
        $this->assertNull($u->registered_at);

        // 管理者の送り直し。メールにも本登録の画面にも、企業名（管理者の直属）は出ない
        $this->as('admin@park.example')->post("/people/{$u->id}/resend")->assertRedirect('/admin/engineers');
        $u = DB::table('users')->find($u->id);
        $mail = DB::table('mail_outbox')->where('to_email', 'd31@example.com')->orderByDesc('id')->first();
        $this->assertStringNotContainsString('管理者の直属', $mail->body);
        $this->guest();
        $this->get('/invite/'.$u->token)->assertOk()->assertDontSee('管理者の直属');

        // 本登録を通すと参加中になり、知らせは管理者全員（SES がいないため）
        $this->finish($u->token, $this->egPayload());
        $this->assertSame('参加中', user_state(DB::table('users')->find($u->id)));
        foreach ([$this->uid('admin@park.example'), $second] as $adminId) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $adminId)->where('title', 'like', '本登録が完了しました%')->count());
        }
    }

    public function test_s2_31_migrated_direct_eg_can_complete_missing_items(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        $direct = W::directSesId();
        $eid = W::createEngineer($direct, ['name' => '直属 二郎', 'email' => 'd31b@example.com'], $admin, false);
        DB::table('users')->insert(['email' => 'd31b@example.com', 'name' => '直属 二郎', 'name_sei' => '直属', 'name_mei' => '二郎', 'role' => 'eg', 'ses_id' => $direct, 'engineer_id' => $eid,
            'password' => bcrypt('park1234'), 'registered_at' => now_str(), 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->as('d31b@example.com')->get('/eg')->assertRedirect('/register/complete');
        $this->get('/register/complete')->assertOk()->assertDontSee('管理者の直属');
        $this->post('/register/complete', ['kana_sei' => 'ちょくぞく', 'kana_mei' => 'じろう', 'phone' => '090-2222-3333', 'postal_code' => '100-0001', 'address' => '東京都', 'consent' => '1'])->assertRedirect();
        $this->get('/eg')->assertOk();
        $this->assertNotNull(DB::table('engineers')->find($eid)->registered_at);
    }
}
