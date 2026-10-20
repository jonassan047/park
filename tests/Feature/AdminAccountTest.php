<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 管理者の情報を直す／管理者・SES の登録（企業名・担当者名・メール）と招待メール（PLAN 2026-10-20 の P・Q・R）
class AdminAccountTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', strtolower($email))->value('id');
    }

    private function users(): int { return DB::table('users')->count(); }
    private function invites(): int { return DB::table('mail_outbox')->where('kind', 'invite')->count(); }
    private function audits(string $a): int { return DB::table('audit_log')->where('action', $a)->count(); }

    // 使用中（パスワード設定済み）の管理者を1人作る
    private function activeAdmin(string $email = 'admin2@park.example', string $name = '別 管理者', string $company = '別企業'): int
    {
        $id = W::createUser($email, $name, 'admin', 'park1234');
        DB::table('users')->where('id', $id)->update(['company_name' => $company, 'profile_completed_at' => now()]);
        return $id;
    }

    // 招待中の管理者を1人作る（管理者として登録する）
    private function pendingAdmin(string $email = 'inv-a@park.example'): int
    {
        $this->as('admin@park.example')->post('/admin/admins', ['name' => '招待 一郎', 'company_name' => 'テスト企業', 'email' => $email])
            ->assertSessionHas('flash.type', 'ok');
        return $this->uid($email);
    }

    private function save(int $id, array $over = []): \Illuminate\Testing\TestResponse
    {
        $u = DB::table('users')->find($id);
        [$sei, $mei] = ($u->name_sei || $u->name_mei) ? [$u->name_sei, $u->name_mei] : split_name($u->name);
        return $this->post("/admin/admins/$id", $over + ['name_sei' => $sei, 'name_mei' => $mei, 'company_name' => $u->company_name, 'email' => $u->email]);
    }

    // ---------- P. 管理者の招待 ----------

    public function test_p1_form_fields(): void
    {
        $this->as('admin@park.example')->get('/admin/admins/new')->assertOk()
            ->assertSee('担当者名')->assertSee('企業名')->assertSee('メールアドレス')->assertSee('登録して招待メールを送る');
    }

    public function test_p1_settings_has_no_admin_card_and_nav_has_admins_before_ses(): void
    {
        $r = $this->as('admin@park.example')->get('/admin/settings')->assertOk();
        $r->assertDontSee('登録して招待メールを送る')->assertDontSee('招待メール（7日間有効のリンク）');
        $r->assertSeeInOrder(['href="'.url('/admin').'"', 'href="'.url('/admin/admins').'"', 'href="'.url('/admin/ses').'"'], false);
        // 直す画面では「管理者」が選択中になる
        $id = $this->activeAdmin();
        $this->get("/admin/admins/$id")->assertOk()->assertSee('class="on">管理者</a>', false);
    }

    public function test_p1_ses_and_engineer_get_403_on_admin_admins(): void
    {
        $id = $this->activeAdmin();
        foreach (['ses1@park.example', 'eg1@park.example'] as $email) {
            $this->as($email)->get('/admin/admins')->assertForbidden();
            $this->get("/admin/admins/$id")->assertForbidden();
            $this->post('/admin/admins', ['name' => '侵入 太郎', 'company_name' => '企業', 'email' => 'intruder@park.example'])->assertForbidden();
            $this->post("/admin/admins/$id", ['name_sei' => '乗取', 'name_mei' => '太郎', 'company_name' => '企業', 'email' => 'admin2@park.example'])->assertForbidden();
        }
        $this->assertSame(0, $this->uid('intruder@park.example'));
        $this->assertSame('別企業', DB::table('users')->where('id', $id)->value('company_name'));
    }

    public function test_p2_invite_creates_admin_with_company_and_mail(): void
    {
        $u0 = $this->users(); $m0 = $this->invites(); $a0 = $this->audits('invite');
        $this->as('admin@park.example')->post('/admin/admins', ['name' => '招待 一郎', 'company_name' => 'テスト企業', 'email' => 'Inv-A@park.example'])
            ->assertRedirect(url('/admin/admins'))->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '登録し、招待メールを送りました。');
        $row = DB::table('users')->where('email', 'inv-a@park.example')->first();
        $this->assertSame('admin', $row->role);
        $this->assertSame('テスト企業', $row->company_name);
        $this->assertNull($row->password);
        $this->assertSame('招待 一郎', $row->name);
        $this->assertSame('招待', $row->name_sei);
        $this->assertSame('一郎', $row->name_mei);
        $this->assertSame($u0 + 1, $this->users());
        $this->assertSame($m0 + 1, $this->invites());
        $this->assertSame($a0 + 1, $this->audits('invite'));
        $mail = DB::table('mail_outbox')->where('kind', 'invite')->orderByDesc('id')->first();
        $this->assertSame('inv-a@park.example', $mail->to_email);
        $this->assertStringContainsString('テスト企業 招待 一郎 様', $mail->body);
    }

    public function test_p3_required_fields_and_no_change(): void
    {
        $ok = ['name' => '招待 二郎', 'company_name' => '二郎企業', 'email' => 'inv-b@park.example'];
        $cases = [
            [['name' => ''], '担当者名（姓）を入れてください。'],
            [['name' => "　 \t"], '担当者名（姓）を入れてください。'],
            [['company_name' => ''], '企業名を入れてください。'],
            [['email' => ''], 'メールアドレスを正しく入れてください。'],
            [['email' => 'abc'], 'メールアドレスを正しく入れてください。'],
        ];
        foreach ($cases as [$over, $msg]) {
            $u0 = $this->users(); $m0 = DB::table('mail_outbox')->count();
            $this->as('admin@park.example')->post('/admin/admins', $over + $ok)
                ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', $msg)->assertSessionHasInput(array_key_first($over) === 'name' ? 'company_name' : 'name');
            $this->assertSame($u0, $this->users());
            $this->assertSame($m0, DB::table('mail_outbox')->count());
        }
    }

    public function test_p4_duplicate_emails_any_role_and_case(): void
    {
        $this->pendingAdmin('inv-a@park.example');
        DB::table('users')->where('email', 'eg2@park.example')->update(['active' => 0]);
        foreach (['ses1@park.example', 'inv-a@park.example', 'INV-A@Park.Example', 'ADMIN@park.example', 'eg2@park.example'] as $email) {
            $u0 = $this->users(); $m0 = DB::table('mail_outbox')->count();
            $this->as('admin@park.example')->post('/admin/admins', ['name' => '重複 太郎', 'company_name' => '重複企業', 'email' => $email])
                ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'このメールアドレスはすでに使われています。');
            $this->assertSame($u0, $this->users(), $email);
            $this->assertSame($m0, DB::table('mail_outbox')->count(), $email);
        }
    }

    public function test_p5_trim_and_length_limits(): void
    {
        $sp = "　 ";
        $this->as('admin@park.example')->post('/admin/admins', ['name' => $sp.'空白 太郎'.$sp, 'company_name' => $sp.'空白企業'.$sp, 'email' => 'trim@park.example'])
            ->assertSessionHas('flash.type', 'ok');
        $r = DB::table('users')->where('email', 'trim@park.example')->first();
        $this->assertSame('空白 太郎', $r->name);
        $this->assertSame('空白企業', $r->company_name);

        $u0 = $this->users();
        $this->as('admin@park.example')->post('/admin/admins', ['name' => '招待 一郎', 'company_name' => str_repeat('あ', 101), 'email' => 'long1@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->post('/admin/admins', ['name' => str_repeat('あ', 51).' 名', 'company_name' => '企業', 'email' => 'long2@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->post('/admin/admins', ['name' => '姓 '.str_repeat('あ', 51), 'company_name' => '企業', 'email' => 'long3@park.example'])->assertSessionHas('flash.type', 'bad');
        $this->assertSame($u0, $this->users());
        // ちょうど上限は通る
        $this->post('/admin/admins', ['name' => str_repeat('あ', 50).' '.str_repeat('い', 50), 'company_name' => str_repeat('う', 100), 'email' => 'edge@park.example'])->assertSessionHas('flash.type', 'ok');
    }

    public function test_p6_ses_and_engineer_invites_have_no_company_name(): void
    {
        $sesId = (int) DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('id')->value('id');
        $this->as('admin@park.example')->post("/admin/ses/$sesId/users", ['name' => '担当 次郎', 'email' => 'ses-new@example.com'])->assertSessionHas('flash.type', 'ok');
        $this->assertNull(DB::table('users')->where('email', 'ses-new@example.com')->value('company_name'));
        $this->assertSame('ses', DB::table('users')->where('email', 'ses-new@example.com')->value('role'));
        // W::invite を直接呼んでも、管理者以外には入れない
        W::invite(['email' => 'ses-direct@example.com', 'name' => '直接 三郎', 'role' => 'ses', 'ses_id' => $sesId, 'company_name' => '入れない企業'], User::where('email', 'admin@park.example')->first());
        $this->assertNull(DB::table('users')->where('email', 'ses-direct@example.com')->value('company_name'));
    }

    public function test_p7_reinvite_keeps_company_and_renews_token(): void
    {
        $id = $this->pendingAdmin();
        $t0 = DB::table('users')->where('id', $id)->value('token');
        $m0 = $this->invites();
        $this->post("/admin/admins/$id/reinvite")->assertSessionHas('flash.type', 'ok');
        $this->assertSame('テスト企業', DB::table('users')->where('id', $id)->value('company_name'));
        $this->assertNotSame($t0, DB::table('users')->where('id', $id)->value('token'));
        $this->assertSame($m0 + 1, $this->invites());
    }

    // ---------- Q. 管理者の情報を直す ----------

    public function test_q1_q2_edit_link_and_screen(): void
    {
        $other = $this->activeAdmin();
        $self = $this->uid('admin@park.example');
        $html = $this->as('admin@park.example')->get('/admin/admins')->assertOk()->getContent();
        $this->assertStringContainsString("/admin/admins/$other", $html);
        $this->assertStringContainsString("/admin/admins/$self", $html);
        $this->assertStringContainsString('>詳細</a>', $html);
        $this->assertStringNotContainsString('>修正<', $html);
        $this->assertStringNotContainsString('>直す<', $html);

        $this->get("/admin/admins/$other/edit")->assertOk()->assertSee('管理者の編集：別 管理者')->assertSee('使用中')
            ->assertSee('value="別"', false)->assertSee('value="管理者"', false)->assertSee('value="別企業"', false)->assertSee('value="admin2@park.example"', false);
    }

    public function test_q3_edit_others_name_and_company(): void
    {
        $id = $this->activeAdmin();
        $a0 = $this->audits('admin-edit'); $m0 = DB::table('mail_outbox')->count();
        $this->as('admin@park.example')->post("/admin/admins/$id", ['name_sei' => '新姓', 'name_mei' => '新名', 'company_name' => '新企業', 'email' => 'admin2@park.example'])
            ->assertRedirect(url("/admin/admins/$id"))->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '保存しました。');
        $r = DB::table('users')->find($id);
        $this->assertSame('新姓 新名', $r->name);
        $this->assertSame('新姓', $r->name_sei);
        $this->assertSame('新名', $r->name_mei);
        $this->assertSame('新企業', $r->company_name);
        $this->assertSame($a0 + 1, $this->audits('admin-edit'));
        $this->assertSame($m0, DB::table('mail_outbox')->count());
        $this->assertStringContainsString('氏名：別 管理者→新姓 新名', (string) DB::table('audit_log')->where('action', 'admin-edit')->orderByDesc('id')->value('detail'));
        // 名が空でもよい
        $this->post("/admin/admins/$id", ['name_sei' => '姓だけ', 'name_mei' => '', 'company_name' => '新企業', 'email' => 'admin2@park.example'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('姓だけ', DB::table('users')->where('id', $id)->value('name'));
    }

    public function test_q3_edit_self_company_shows_everywhere(): void
    {
        $self = $this->uid('admin@park.example');
        $this->as('admin@park.example')->post("/admin/admins/$self", ['name_sei' => '管理者', 'name_mei' => '太郎', 'company_name' => '自分の新企業', 'email' => 'admin@park.example'])
            ->assertSessionHas('flash.type', 'ok');
        $this->assertSame('自分の新企業', DB::table('users')->where('id', $self)->value('company_name'));
        $this->assertStringContainsString('自分の新企業', user_label(DB::table('users')->find($self)));
        $this->get('/admin/admins')->assertSee('自分の新企業');
    }

    public function test_q3_required_and_length_nothing_saved(): void
    {
        $id = $this->activeAdmin();
        $before = (array) DB::table('users')->find($id);
        $this->as('admin@park.example');
        foreach ([['name_sei' => ''], ['company_name' => ''], ['name_sei' => str_repeat('あ', 51)], ['name_mei' => str_repeat('あ', 51)], ['company_name' => str_repeat('あ', 101)]] as $over) {
            $this->save($id, $over + ['name_sei' => '変更姓', 'company_name' => '変更企業'])->assertSessionHas('flash.type', 'bad');
            $this->assertEquals($before, (array) DB::table('users')->find($id), json_encode($over, JSON_UNESCAPED_UNICODE));
        }
        $this->assertSame(0, $this->audits('admin-edit'));
    }

    public function test_q4_self_email_rejected_and_nothing_saved(): void
    {
        $self = $this->uid('admin@park.example');
        $this->as('admin@park.example')->get("/admin/admins/$self/edit")->assertOk()->assertSee('disabled', false)->assertSee('ご自分のメールは、ほかの管理者に頼んで変えてもらいます。');
        $before = (array) DB::table('users')->find($self);
        $this->save($self, ['email' => 'self-new@park.example', 'name_sei' => '書き換え', 'company_name' => '書き換え企業'])
            ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'ご自分のメールアドレスは変えられません。');
        $this->assertEquals($before, (array) DB::table('users')->find($self));
    }

    public function test_q4_suspended_email_rejected_but_name_ok(): void
    {
        $id = $this->activeAdmin();
        DB::table('users')->where('id', $id)->update(['active' => 0]);
        $this->as('admin@park.example')->get("/admin/admins/$id/edit")->assertOk()->assertSee('停止')->assertSee('停止中の人のメールアドレスは変えられません。');
        $m0 = DB::table('mail_outbox')->count();
        $this->save($id, ['email' => 'moved@park.example'])->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', '停止中の人のメールアドレスは変えられません。');
        $this->assertSame('admin2@park.example', DB::table('users')->where('id', $id)->value('email'));
        $this->save($id, ['name_sei' => '停止の姓'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('停止の姓', DB::table('users')->where('id', $id)->value('name_sei'));
        $this->assertSame($m0, DB::table('mail_outbox')->count());
    }

    public function test_q4_pending_other_email_changes_and_reinvites(): void
    {
        $id = $this->pendingAdmin('inv-a@park.example');
        $t0 = DB::table('users')->where('id', $id)->value('token');
        $old = DB::table('mail_outbox')->where('to_email', 'inv-a@park.example')->count();
        $this->get("/admin/admins/$id/edit")->assertOk()->assertSee('招待中')->assertSee('変えると、新しいアドレスに招待を送り直します。');
        $this->save($id, ['email' => 'Moved@Park.Example'])->assertRedirect(url("/admin/admins/$id"))
            ->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '保存し、新しいメールアドレスに招待を送り直しました。');
        $r = DB::table('users')->find($id);
        $this->assertSame('moved@park.example', $r->email);
        $this->assertNotSame($t0, $r->token);
        $this->assertGreaterThan(date('Y-m-d H:i:s', time() + 6 * 86400), $r->token_expires);
        $this->assertSame(1, DB::table('mail_outbox')->where('to_email', 'moved@park.example')->where('kind', 'invite')->count());
        $this->assertSame($old, DB::table('mail_outbox')->where('to_email', 'inv-a@park.example')->count());
        $this->assertSame(1, $this->audits('admin-edit'));
        $this->assertStringContainsString('メール：inv-a@park.example→moved@park.example', (string) DB::table('audit_log')->where('action', 'admin-edit')->value('detail'));
        // 古いリンクは使えない
        $this->assertNull(W::userByToken($t0));
        // 新しい宛先のメールにも企業名が付く
        $this->assertStringContainsString('テスト企業 招待 一郎 様', DB::table('mail_outbox')->where('to_email', 'moved@park.example')->value('body'));
    }

    public function test_q4_active_other_email_rejected_no_mail(): void
    {
        $id = $this->activeAdmin();
        $this->as('admin@park.example')->get("/admin/admins/$id/edit")->assertOk()->assertSee('使用中の方のメールは変えられません。')->assertSee('disabled', false);
        $before = (array) DB::table('users')->find($id);
        $m0 = DB::table('mail_outbox')->count(); $a0 = $this->audits('admin-edit');
        $this->save($id, ['email' => 'moved2@park.example', 'name_sei' => '混ぜる姓', 'company_name' => '混ぜる企業'])
            ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', '使用中の方のメールアドレスは変えられません。');
        $this->assertEquals($before, (array) DB::table('users')->find($id));
        $this->assertSame($m0, DB::table('mail_outbox')->count());
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', 'account-email')->count());
        $this->assertSame($a0, $this->audits('admin-edit'));
        // 元のメールとパスワードでそのまま入れる
        $this->assertTrue(auth()->validate(['email' => 'admin2@park.example', 'password' => 'park1234']));
    }

    public function test_q5_email_rules(): void
    {
        $pend = $this->pendingAdmin('inv-a@park.example');
        $this->activeAdmin();
        $before = (array) DB::table('users')->find($pend);
        foreach (['admin2@park.example', 'ADMIN2@PARK.EXAMPLE', 'ses1@park.example', 'eg1@park.example'] as $email) {
            $this->save($pend, ['email' => $email, 'name_sei' => '変わらない', 'company_name' => '変わらない企業'])
                ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'このメールアドレスはすでに使われています。');
            $this->assertEquals($before, (array) DB::table('users')->find($pend), $email);
        }
        foreach (['', 'abc', 'a@b'] as $email) {
            $this->save($pend, ['email' => $email, 'name_sei' => '変わらない'])->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'メールアドレスを正しく入れてください。');
            $this->assertEquals($before, (array) DB::table('users')->find($pend), "[$email]");
        }
        // 大文字小文字だけの違いは「変えていない」
        $m0 = DB::table('mail_outbox')->count(); $a0 = $this->audits('admin-edit');
        $this->save($pend, ['email' => 'INV-A@Park.Example'])->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '保存しました。');
        $this->assertSame('inv-a@park.example', DB::table('users')->where('id', $pend)->value('email'));
        $this->assertSame($m0, DB::table('mail_outbox')->count());
        $this->assertSame($a0, $this->audits('admin-edit'));
    }

    public function test_q6_no_change_and_double_submit(): void
    {
        $pend = $this->pendingAdmin('inv-a@park.example');
        $this->as('admin@park.example');
        $m0 = DB::table('mail_outbox')->count(); $a0 = $this->audits('admin-edit');
        $this->save($pend)->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '保存しました。');
        $this->save($pend)->assertSessionHas('flash.type', 'ok');
        $this->assertSame($m0, DB::table('mail_outbox')->count());
        $this->assertSame($a0, $this->audits('admin-edit'));
        // 変更を2回送っても、2回目は変化なし
        $this->save($pend, ['company_name' => '二度押し企業'])->assertSessionHas('flash.type', 'ok');
        $this->save($pend, ['company_name' => '二度押し企業'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame($a0 + 1, $this->audits('admin-edit'));
        // メール変更の二度押し：2回目は変化なしなので、招待は増えない
        $this->save($pend, ['email' => 'moved@park.example']);
        $m1 = DB::table('mail_outbox')->count();
        $this->save($pend, ['email' => 'moved@park.example'])->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '保存しました。');
        $this->assertSame($m1, DB::table('mail_outbox')->count());
    }

    public function test_q7_audit_detail_changed_items_only(): void
    {
        $id = $this->activeAdmin();
        $this->as('admin@park.example');
        $this->save($id, ['company_name' => '監査企業'])->assertSessionHas('flash.type', 'ok');
        $d = (string) DB::table('audit_log')->where('action', 'admin-edit')->value('detail');
        $this->assertStringStartsWith("#$id ", $d);
        $this->assertStringContainsString('企業名：別企業→監査企業', $d);
        $this->assertStringNotContainsString('氏名', $d);
        $this->assertStringNotContainsString('メール', $d);
        $this->assertLessThanOrEqual(300, mb_strlen($d));
    }

    public function test_q8_not_admin_target_404_and_roles_403(): void
    {
        $ses = $this->uid('ses1@park.example'); $eg = $this->uid('eg1@park.example');
        $this->as('admin@park.example');
        foreach ([$ses, $eg, 99999] as $id) {
            $this->get("/admin/admins/$id")->assertNotFound();
            $this->get("/admin/admins/$id/edit")->assertNotFound();
            $this->post("/admin/admins/$id", ['name_sei' => 'あ', 'company_name' => 'い', 'email' => 'x@park.example'])->assertNotFound();
        }
        $this->assertSame('ses1@park.example', DB::table('users')->where('id', $ses)->value('email'));
        $adm = $this->activeAdmin();
        foreach (['ses1@park.example', 'eg1@park.example'] as $who) {
            $this->as($who)->get("/admin/admins/$adm")->assertForbidden();
            $this->get("/admin/admins/$adm/edit")->assertForbidden();
            $this->post("/admin/admins/$adm", ['name_sei' => '乗っ取り', 'company_name' => '乗っ取り', 'email' => 'admin2@park.example'])->assertForbidden();
            $this->post('/admin/admins', ['name' => '勝手 太郎', 'company_name' => '勝手', 'email' => 'sneak@park.example'])->assertForbidden();
        }
        $this->assertSame('別 管理者', DB::table('users')->where('id', $adm)->value('name'));
        $this->assertNull(DB::table('users')->where('email', 'sneak@park.example')->first());
        // ログインしていない
        auth()->logout();
        $this->flushSession();
        $this->get("/admin/admins/$adm")->assertRedirect();
        $this->post("/admin/admins/$adm", ['name_sei' => 'x', 'company_name' => 'y'])->assertRedirect();
        $this->assertSame('別 管理者', DB::table('users')->where('id', $adm)->value('name'));
    }

    public function test_q9_edited_values_appear_in_table_and_labels(): void
    {
        $id = $this->pendingAdmin('inv-a@park.example');
        $this->as('admin@park.example');
        $this->save($id, ['name_sei' => '反映', 'name_mei' => '花子', 'company_name' => '反映企業']);
        $this->get('/admin/admins')->assertSee('反映企業')->assertSee('反映 花子');
        $this->assertStringContainsString('反映企業 反映 花子', user_label(DB::table('users')->find($id)));
        // 以降の招待の送り直しの宛名にも新しい値
        $this->post("/admin/admins/$id/reinvite");
        $this->assertStringContainsString('反映企業 反映 花子 様', DB::table('mail_outbox')->where('kind', 'invite')->orderByDesc('id')->value('body'));
    }

    // ---------- R. SES の登録 ----------

    public function test_r1_form_labels(): void
    {
        $html = $this->as('admin@park.example')->get('/admin/ses/new')->assertOk()->assertSee('担当者名')->assertDontSee('ご担当者名')
            ->assertSee('招待メールの宛名になります。')->getContent();
        $this->assertLessThan(strpos($html, 'メールアドレス<'), strpos($html, '担当者名'));
        auth()->logout();
        $this->flushSession();
        $this->get('/apply')->assertOk()->assertSee('ご担当者名');
    }

    public function test_r2_register_ses_with_invite(): void
    {
        $m0 = $this->invites();
        $this->as('admin@park.example')->post('/admin/ses', ['name' => '登録商事', 'contact_name' => '担当 花', 'email' => 'Reg-R@park.example'])
            ->assertSessionHas('flash.type', 'ok')->assertSessionHas('flash.msg', '登録し、ご担当者に招待メールを送りました。');
        $sid = (int) DB::table('ses_companies')->where('name', '登録商事')->value('id');
        $this->assertSame('active', DB::table('ses_companies')->where('id', $sid)->value('status'));
        $u = DB::table('users')->where('email', 'reg-r@park.example')->first();
        $this->assertSame('ses', $u->role);
        $this->assertSame($sid, (int) $u->ses_id);
        $this->assertSame('担当 花', $u->name);
        $this->assertNull($u->company_name);
        $this->assertSame($m0 + 1, $this->invites());
        $this->assertStringContainsString('登録商事 担当 花 様', DB::table('mail_outbox')->where('to_email', 'reg-r@park.example')->value('body'));
    }

    public function test_r3_no_email_is_rejected(): void
    {
        $u0 = $this->users(); $m0 = DB::table('mail_outbox')->count();
        $this->as('admin@park.example')->post('/admin/ses', ['name' => 'メール無し商事', 'contact_name' => '担当 者', 'email' => ''])
            ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'メールアドレスを入れてください（担当の方に招待メールを送ります）。');
        $this->assertSame(0, DB::table('ses_companies')->where('name', 'メール無し商事')->count());
        $this->assertSame($u0, $this->users());
        $this->assertSame($m0, DB::table('mail_outbox')->count());
    }

    public function test_r4_email_without_contact_name_rejected(): void
    {
        $s0 = DB::table('ses_companies')->count(); $u0 = $this->users(); $m0 = DB::table('mail_outbox')->count();
        foreach (['', '　 '] as $c) {
            $this->as('admin@park.example')->post('/admin/ses', ['name' => '宛名無し商事', 'contact_name' => $c, 'email' => 'noname@park.example'])
                ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', '担当者名を入れてください（招待メールの宛名になります）。');
            $this->assertSame($s0, DB::table('ses_companies')->count());
            $this->assertSame($u0, $this->users());
            $this->assertSame($m0, DB::table('mail_outbox')->count());
        }
    }

    public function test_r5_blank_company_and_duplicate_emails(): void
    {
        $pend = $this->pendingAdmin('inv-a@park.example');
        $s0 = DB::table('ses_companies')->count(); $u0 = $this->users(); $m0 = DB::table('mail_outbox')->count();
        $this->post('/admin/ses', ['name' => '', 'contact_name' => '誰か', 'email' => 'blank-co@park.example'])->assertSessionHas('flash.type', 'bad');
        foreach (['SES1@Park.Example', 'ses1@park.example', 'INV-A@park.example', 'admin@park.example', 'eg1@park.example'] as $email) {
            $this->post('/admin/ses', ['name' => '重複商事', 'contact_name' => '誰か', 'email' => $email])
                ->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'このメールアドレスはすでに使われています。');
        }
        $this->assertSame($s0, DB::table('ses_companies')->count());
        $this->assertSame($u0, $this->users());
        $this->assertSame($m0, DB::table('mail_outbox')->count());
        $this->assertGreaterThan(0, $pend);
    }

    public function test_r6_ses_update_does_not_require_contact_name(): void
    {
        $sid = (int) DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('id')->value('id');
        $this->as('admin@park.example')->post("/admin/ses/$sid", ['name' => '更新商事', 'contact_name' => '', 'email' => ''])->assertSessionHas('flash.type', 'ok');
        $this->assertSame('更新商事', DB::table('ses_companies')->where('id', $sid)->value('name'));
    }

    public function test_r_ses_and_engineer_cannot_register(): void
    {
        $s0 = DB::table('ses_companies')->count();
        $this->as('ses1@park.example')->post('/admin/ses', ['name' => '勝手商事', 'contact_name' => '誰か', 'email' => 'x1@park.example'])->assertForbidden();
        $this->as('eg1@park.example')->post('/admin/ses', ['name' => '勝手商事', 'contact_name' => '誰か', 'email' => 'x2@park.example'])->assertForbidden();
        $this->assertSame($s0, DB::table('ses_companies')->count());
    }
}
