<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.2.0 段階 2：代表者と担当者の違い・代表者の交代（PLAN 2026-10-31 の S2-20〜S2-23）。見本の SES 1 の代表者は ses1@park.example
class RepresentativeTest extends TestCase
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

    // 会社 1 に、本登録が済んだ担当者を足す
    private function staff(string $email, string $name): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => 1]);
        $this->completeProfile($id);
        return $id;
    }

    private function company(): object
    {
        return DB::table('ses_companies')->find(1);
    }

    private function flashType(): ?string
    {
        return session('flash')['type'] ?? null;
    }

    public function test_s2_20_only_rep_and_admin_stop_staff_but_staff_can_stop_eg(): void
    {
        $this->staff('mate@example.com', '同僚 一郎');
        $other = $this->staff('mate2@example.com', '同僚 二郎');
        foreach (['suspend', 'resume', 'delete'] as $a) $this->as('mate@example.com')->post("/admin/ses/1/users/$other/$a")->assertForbidden();
        $this->assertDatabaseHas('users', ['id' => $other, 'active' => 1, 'left_at' => null]);
        // 代表者・管理者は押せる
        $this->as('ses1@park.example')->post("/admin/ses/1/users/$other/suspend")->assertRedirect('/ses/company');
        $this->assertSame(0, (int) DB::table('users')->find($other)->active);
        $this->as('admin@park.example')->post("/admin/ses/1/users/$other/resume")->assertRedirect('/admin/ses/1');
        $this->assertSame(1, (int) DB::table('users')->find($other)->active);
        // エンジニアの操作は担当者もできる
        $eid = (int) DB::table('users')->where('email', 'eg1@park.example')->value('engineer_id');
        $this->as('mate@example.com')->post("/engineers/$eid/suspend")->assertRedirect("/engineers/$eid");
        $this->assertNotNull(DB::table('engineers')->find($eid)->suspended_at);
    }

    public function test_s2_21_rep_is_stopped_only_after_handover_but_the_company_can_be_stopped(): void
    {
        $rep = $this->uid('ses1@park.example');
        $mate = $this->staff('mate@example.com', '同僚 一郎');
        $this->as('admin@park.example');
        foreach (['suspend', 'delete'] as $a) {
            $this->post("/admin/ses/1/users/$rep/$a")->assertRedirect('/admin/ses/1');
            $this->assertSame('bad', $this->flashType());
            $this->assertStringContainsString('代表者', session('flash')['msg']);
        }
        $this->assertDatabaseHas('users', ['id' => $rep, 'active' => 1, 'left_at' => null]);
        // 交代のあと：前の代表者は止められ、新しい代表者は止められない
        $this->post('/admin/ses/1/rep', ['user_id' => $mate])->assertRedirect('/admin/ses/1');
        $this->assertSame('ok', $this->flashType(), (string) (session('flash')['msg'] ?? ""));
        $this->post("/admin/ses/1/users/$mate/suspend");
        $this->assertSame('bad', $this->flashType());
        $this->post("/admin/ses/1/users/$rep/suspend");
        $this->assertSame('ok', $this->flashType());
        $this->assertSame(0, (int) DB::table('users')->find($rep)->active);
        // 会社ごとの停止は今までどおり
        $this->post('/admin/ses/1/suspend');
        $this->assertSame('suspended', $this->company()->status);
    }

    public function test_s2_22_changing_the_rep_swaps_the_authority(): void
    {
        $rep = $this->uid('ses1@park.example');
        $mate = $this->staff('mate@example.com', '同僚 一郎');
        $this->as('ses1@park.example')->post('/admin/ses/1/rep', ['user_id' => $mate])->assertForbidden(); // SES の人は交代できない
        $this->as('mate@example.com')->post('/admin/ses/1/rep', ['user_id' => $mate])->assertForbidden();
        // 仮登録・他社・存在しない人は選べない
        $this->as('ses1@park.example')->post('/ses/company/users', ['name_sei' => '仮', 'name_mei' => '登録', 'email' => 'prov22@example.com']);
        $prov = $this->uid('prov22@example.com');
        $this->as('admin@park.example');
        foreach ([$prov, $this->uid('ses2@park.example'), 999999] as $bad) {
            $this->post('/admin/ses/1/rep', ['user_id' => (string) $bad]);
            $this->assertSame('bad', $this->flashType(), (string) $bad);
            $this->assertSame($rep, (int) $this->company()->rep_user_id);
        }
        // 本登録が済んだ担当者を選ぶ
        $this->post('/admin/ses/1/rep', ['user_id' => (string) $mate])->assertRedirect('/admin/ses/1');
        $this->assertSame('ok', $this->flashType());
        $s = $this->company();
        $this->assertSame($mate, (int) $s->rep_user_id);
        $this->assertSame(['同僚 一郎', '同僚', '一郎'], [$s->rep_name, $s->rep_sei, $s->rep_mei]); // 代表者の欄は新しい人の姓名
        $this->assertSame('ses', DB::table('users')->find($rep)->role); // 前の代表者は担当者になる
        foreach ([$rep, $mate] as $id) {
            $this->assertSame(1, DB::table('app_notifications')->where('user_id', $id)->where('title', T::REP_CHANGED_TITLE)->count());
        }
        // 権限が入れ替わる
        $this->as('ses1@park.example')->get('/ses/company/edit')->assertForbidden();
        $this->as('mate@example.com')->get('/ses/company/edit')->assertOk();
    }

    public function test_s2_23_next_rep_is_refused_while_registered_staff_exist(): void
    {
        $this->staff('mate@example.com', '同僚 一郎');
        $this->as('admin@park.example')->post('/admin/ses/1/rep', ['name_sei' => '次', 'name_mei' => '代表', 'email' => 'next23x@example.com']);
        $this->assertSame('bad', $this->flashType());
        $this->assertNull($this->company()->next_rep_user_id);
        $this->assertNull(DB::table('users')->where('email', 'next23x@example.com')->first());
    }

    public function test_s2_23_next_rep_takes_over_when_registration_is_done(): void
    {
        $rep = $this->uid('ses1@park.example'); // 見本の会社 1 は、担当者が代表者1人だけ
        $this->as('admin@park.example')->post('/admin/ses/1/rep', ['name_sei' => '次', 'name_mei' => '代表', 'email' => 'next23@example.com'])->assertSessionHas('flash.type', 'ok');
        $n = DB::table('users')->where('email', 'next23@example.com')->first();
        $s = $this->company();
        $this->assertSame([$rep, (int) $n->id], [(int) $s->rep_user_id, (int) $s->next_rep_user_id]);
        $this->assertNull($n->registered_at);
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'next23@example.com')->where('subject', 'like', '%仮登録のご案内%')->count());

        // 次の代表者の本登録の画面は、代表者のもの（企業正式名称・会社の住所・会社として守秘義務）
        $this->guest();
        $this->get('/invite/'.$n->token)->assertOk()->assertSee('企業正式名称')->assertSee('会社の住所');
        $payload = ['ses_name' => $s->name, 'name_sei' => '次', 'name_mei' => '代表', 'phone' => '03-9999-0000', 'postal_code' => '100-0002', 'address' => '東京都千代田区2-2',
            'agree' => '1', 'password' => 'password12', 'password_confirmation' => 'password12'];
        $this->post('/invite/'.$n->token, $payload)->assertRedirect(url('/two-factor/challenge'));
        // 確認コードを通すまでは、代表者は前の人のまま
        $this->assertSame($rep, (int) $this->company()->rep_user_id);
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect(url('/ses'));
        $s = $this->company();
        $this->assertSame([(int) $n->id, null], [(int) $s->rep_user_id, $s->next_rep_user_id]);
        $this->assertSame(['次 代表', '次', '代表'], [$s->rep_name, $s->rep_sei, $s->rep_mei]);
        // 前の代表者は担当者になり、会社情報は見るだけ
        $this->guest();
        $this->as('ses1@park.example')->get('/ses/company/edit')->assertForbidden();
        $this->as('next23@example.com')->get('/ses/company/edit')->assertOk();
    }
}
