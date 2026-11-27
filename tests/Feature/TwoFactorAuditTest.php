<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 確認コードで弾かれた理由・送信・成功が audit_log に残ること、park:twofactor が見るだけで伏せ字になることを確かめる
class TwoFactorAuditTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const EMAIL = 'eg1@park.example';

    private function loginEg(): void
    {
        $this->post('/login', ['email' => self::EMAIL, 'password' => 'park1234'])->assertRedirect(url('/two-factor/challenge'));
    }

    private function userId(): int
    {
        return (int) User::where('email', self::EMAIL)->value('id');
    }

    private function lastCode(): string
    {
        $subject = DB::table('mail_outbox')->where('to_email', self::EMAIL)->where('kind', '2fa')->orderByDesc('id')->value('subject');
        preg_match('/(\d{6})/', $subject, $m);
        return $m[1];
    }

    private function wrongOf(string $code): string
    {
        return $code === '000000' ? '111111' : '000000';
    }

    private function details(string $action): array
    {
        return DB::table('audit_log')->where('user_id', $this->userId())->where('action', $action)->orderBy('id')->pluck('detail')->all();
    }

    private function lastDetail(string $action): ?string
    {
        $d = $this->details($action);
        return $d ? end($d) : null;
    }

    public function test_login_sent_and_ok_are_recorded(): void
    {
        $this->loginEg();
        $this->assertCount(1, $this->details('login'));
        $sent = $this->details('2fa-sent');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('from=login', $sent[0]);
        $this->assertMatchesRegularExpression('/ s=[0-9a-f]{8}$/', $sent[0]);

        $this->post('/two-factor/challenge', ['code' => $this->lastCode()])->assertRedirect(url('/eg'));
        $ok = $this->details('2fa-ok');
        $this->assertCount(1, $ok);
        $this->assertMatchesRegularExpression('/^s=[0-9a-f]{8}$/', $ok[0]);
        $this->assertSame([], $this->details('2fa-fail'));
        $this->get('/eg')->assertOk();
    }

    public function test_wrong_code(): void
    {
        $this->loginEg();
        $this->followingRedirects()->post('/two-factor/challenge', ['code' => $this->wrongOf($this->lastCode())])->assertSee('コードが違います');
        $d = $this->lastDetail('2fa-fail');
        $this->assertStringStartsWith('wrong', $d);
        $this->assertStringContainsString('tries=1', $d);
    }

    public function test_no_pending(): void
    {
        $u = User::where('email', self::EMAIL)->firstOrFail();
        $this->actingAs($u)->post('/two-factor/challenge', ['code' => '123456'])->assertRedirect(url('/two-factor/challenge'));
        $this->assertStringStartsWith('no-pending', $this->lastDetail('2fa-fail'));
    }

    public function test_user_mismatch(): void
    {
        $u = User::where('email', self::EMAIL)->firstOrFail();
        $other = (int) User::where('id', '!=', $u->id)->value('id');
        $this->actingAs($u)->withSession([TwoFactor::PENDING_KEY => [
            'user' => $other, 'hash' => 'x', 'expires' => now()->getTimestamp() + 600, 'tries' => 0, 'sent' => now()->getTimestamp(),
        ]])->post('/two-factor/challenge', ['code' => '123456'])->assertRedirect(url('/two-factor/challenge'));
        $d = $this->lastDetail('2fa-fail');
        $this->assertStringStartsWith('user-mismatch', $d);
        $this->assertStringContainsString("pending_user={$other}", $d);
    }

    public function test_timeout(): void
    {
        $this->loginEg();
        $code = $this->lastCode();
        $this->travel(11)->minutes();
        $this->followingRedirects()->post('/two-factor/challenge', ['code' => $code])
            ->assertSee('前のコードの期限が切れたので')
            ->assertDontSee('コードの期限が切れたか')->assertDontSee('多すぎます。「コードを送り直す」を押してください');
        $d = $this->lastDetail('2fa-fail');
        $this->assertStringStartsWith('timeout', $d);
        $this->assertMatchesRegularExpression('/age=\d+/', $d);
        $sent = $this->details('2fa-sent');
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('from=challenge-form-timeout', $sent[1]);
        $this->post('/two-factor/challenge', ['code' => $this->lastCode()])->assertRedirect(url('/eg'));
    }

    public function test_timeout_direct_get_resends(): void
    {
        $this->loginEg();
        $old = $this->lastCode();
        $this->travel(11)->minutes();
        $this->get('/two-factor/challenge')->assertOk()->assertSee('前のコードの期限が切れたので');
        $this->assertCount(2, $this->details('2fa-sent'));
        $this->post('/two-factor/challenge', ['code' => $this->lastCode()])->assertRedirect(url('/eg'));
    }

    public function test_live_pending_does_not_resend(): void
    {
        $this->loginEg();
        $code = $this->lastCode();
        $this->travel(5)->minutes();
        $this->get('/two-factor/challenge')->assertOk();
        $this->assertCount(1, $this->details('2fa-sent'));
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect(url('/eg'));
    }

    public function test_too_many_on_fifth_wrong_and_afterwards(): void
    {
        $this->loginEg();
        $code = $this->lastCode();
        $wrong = $this->wrongOf($code);
        for ($i = 1; $i <= 4; $i++) {
            $this->post('/two-factor/challenge', ['code' => $wrong]);
            $this->assertStringStartsWith('wrong', $this->lastDetail('2fa-fail'));
        }
        // 5回目のまちがいは too-many。60秒以内なので送らず、待つよう伝える
        $this->followingRedirects()->post('/two-factor/challenge', ['code' => $wrong])->assertSee('1分ほど待ってから');
        $fails = $this->details('2fa-fail');
        $this->assertCount(5, $fails);
        $this->assertStringStartsWith('too-many', $fails[4]);
        $this->assertStringContainsString('tries=5', $fails[4]);
        $this->assertCount(1, $this->details('2fa-sent'));
        // 6回目は正しいコードでも通らない
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect(url('/two-factor/challenge'));
        $fails = $this->details('2fa-fail');
        $this->assertCount(6, $fails);
        $this->assertStringStartsWith('too-many', $fails[5]);
        $this->get('/eg')->assertRedirect(url('/two-factor/challenge'));
        $this->assertSame([], $this->details('2fa-ok'));
    }

    public function test_too_many_after_wait_resends(): void
    {
        $this->loginEg();
        $wrong = $this->wrongOf($this->lastCode());
        for ($i = 1; $i <= 5; $i++) {
            $this->post('/two-factor/challenge', ['code' => $wrong]);
        }
        $this->travel(2)->minutes();
        $this->get('/two-factor/challenge')->assertOk()->assertSee('まちがえた回数が多すぎたので、新しいコードを送りました');
        $sent = $this->details('2fa-sent');
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('from=challenge-form-too-many', end($sent));
        $this->post('/two-factor/challenge', ['code' => $this->lastCode()])->assertRedirect(url('/eg'));
    }

    public function test_no_pending_post_sends_new_code(): void
    {
        $u = User::where('email', self::EMAIL)->firstOrFail();
        $this->actingAs($u)->followingRedirects()->post('/two-factor/challenge', ['code' => '123456'])->assertSee('確認コードを送りました');
        $this->assertStringStartsWith('no-pending', $this->lastDetail('2fa-fail'));
        $sent = $this->details('2fa-sent');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('from=challenge-form', $sent[0]);
        $this->assertStringNotContainsString('challenge-form-', $sent[0]);
    }

    public function test_user_mismatch_post_sends_new_code(): void
    {
        $u = User::where('email', self::EMAIL)->firstOrFail();
        $other = (int) User::where('id', '!=', $u->id)->value('id');
        $this->actingAs($u)->withSession([TwoFactor::PENDING_KEY => [
            'user' => $other, 'hash' => 'x', 'expires' => now()->getTimestamp() + 600, 'tries' => 0, 'sent' => now()->getTimestamp(),
        ]])->followingRedirects()->post('/two-factor/challenge', ['code' => '123456'])->assertOk();
        $sent = $this->details('2fa-sent');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('from=challenge-form-mismatch', $sent[0]);
        $this->post('/two-factor/challenge', ['code' => $this->lastCode()])->assertRedirect(url('/eg'));
    }

    public function test_resend_button_is_outside_details(): void
    {
        $this->loginEg();
        $html = $this->get('/two-factor/challenge')->assertOk()->assertSee('two-factor/resend', false)->getContent();
        $this->assertSame(1, preg_match('#<details.*?</details>#s', $html, $m));
        $this->assertStringNotContainsString('two-factor/resend', $m[0]);
    }

    public function test_challenge_form_without_pending_sends_code(): void
    {
        $u = User::where('email', self::EMAIL)->firstOrFail();
        $this->actingAs($u)->get('/two-factor/challenge')->assertOk();
        $sent = $this->details('2fa-sent');
        $this->assertCount(1, $sent);
        $this->assertStringContainsString('from=challenge-form', $sent[0]);
    }

    public function test_resend_records_from_resend(): void
    {
        $this->loginEg();
        $this->travel(2)->minutes();
        $this->post('/two-factor/resend');
        $sent = $this->details('2fa-sent');
        $this->assertCount(2, $sent);
        $this->assertStringContainsString('from=resend', $sent[1]);
    }

    public function test_no_email_code_or_hash_in_audit(): void
    {
        $this->loginEg();
        $code = $this->lastCode();
        $this->post('/two-factor/challenge', ['code' => $this->wrongOf($code)]);
        $this->post('/two-factor/challenge', ['code' => $code]);
        $this->get('/eg')->assertOk();
        $u = User::where('email', self::EMAIL)->firstOrFail();
        $this->actingAs($u->fresh())->withSession([])->post('/two-factor/challenge', ['code' => '123456']);

        $rows = DB::table('audit_log')->where('user_id', $u->id)->where(fn ($q) => $q->where('action', 'like', '2fa%')->orWhere('action', 'login'))->get();
        $this->assertGreaterThan(3, $rows->count());
        foreach ($rows as $row) {
            $this->assertStringNotContainsString('@', (string) $row->detail);
            $this->assertStringNotContainsString('park.example', (string) $row->detail);
            $this->assertStringNotContainsString($code, (string) $row->detail);
            $this->assertStringNotContainsString(hash_hmac('sha256', $code, config('app.key')), (string) $row->detail);
        }
    }

    public function test_command_shows_state_without_secrets_and_does_not_write(): void
    {
        $this->loginEg();
        $code = $this->lastCode();
        // エラー文にメールアドレスが入っていても伏せられる
        DB::table('mail_outbox')->where('to_email', self::EMAIL)->where('kind', '2fa')->update(['error' => 'Failed to send to secret.person@example.com now']);
        DB::table('audit_log')->insert(['user_id' => $this->userId(), 'action' => '2fa-fail', 'detail' => 'wrong note=leak.me@example.org', 'at' => now_str()]);

        $count = fn () => [DB::table('users')->count(), DB::table('mail_outbox')->count(), DB::table('audit_log')->count()];
        $before = $count();
        $userBefore = (array) DB::table('users')->where('email', self::EMAIL)->first();

        $exit = Artisan::call('park:twofactor', ['email' => '  '.strtoupper(self::EMAIL).' ']);
        $out = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('2fa-sent', $out);
        $this->assertStringContainsString('session.driver', $out);
        $this->assertStringContainsString('***@***', $out);
        $this->assertStringNotContainsString('secret.person@example.com', $out);
        $this->assertStringNotContainsString('leak.me@example.org', $out);
        $this->assertStringNotContainsString($code, $out);
        $this->assertStringNotContainsString('確認コード '.$code, $out);
        $this->assertStringNotContainsString('ログインするための', $out);

        $this->assertSame($before, $count());
        $userAfter = (array) DB::table('users')->where('email', self::EMAIL)->first();
        $this->assertSame($userBefore['two_factor_confirmed_at'], $userAfter['two_factor_confirmed_at']);
        $this->assertSame($userBefore['last_login'], $userAfter['last_login']);
    }

    public function test_command_unknown_user_exits_1(): void
    {
        $this->assertSame(1, Artisan::call('park:twofactor', ['email' => 'nobody@park.example']));
        $this->assertStringContainsString('その人はいません', Artisan::output());
    }
}
