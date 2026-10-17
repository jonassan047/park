<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// ログイン画面にメールアドレスを残しておく（PLAN.md 2026-10-17）
class LoginRememberEmailTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const EMAIL = 'eg1@park.example';
    private const COOKIE = 'park-last-email';

    private function loginOk()
    {
        Mail::fake();
        return $this->post('/login', ['email' => self::EMAIL, 'password' => 'park1234']);
    }

    /** 入力欄 <input ... name="X" ...> のタグ全体を取り出す */
    private function inputTag(string $html, string $name): string
    {
        preg_match('/<input[^>]*name="' . $name . '"[^>]*>/', $html, $m);
        $this->assertNotEmpty($m, "input $name not found");
        return $m[0];
    }

    public function test_cookie_saved_on_password_success(): void
    {
        $this->loginOk()->assertCookie(self::COOKIE, self::EMAIL);
    }

    public function test_cookie_not_saved_on_wrong_password(): void
    {
        Mail::fake();
        $this->post('/login', ['email' => self::EMAIL, 'password' => 'wrong-pass'])
            ->assertCookieMissing(self::COOKIE);
    }

    public function test_cookie_not_saved_for_unknown_user(): void
    {
        Mail::fake();
        $this->post('/login', ['email' => 'nobody@park.example', 'password' => 'park1234'])
            ->assertCookieMissing(self::COOKIE);
    }

    public function test_cookie_is_one_year_httponly_and_holds_only_email(): void
    {
        $res = $this->loginOk();
        $c = collect($res->headers->getCookies())->first(fn ($x) => $x->getName() === self::COOKIE);
        $this->assertNotNull($c);
        $this->assertTrue($c->isHttpOnly());
        $this->assertEqualsWithDelta(time() + 60 * 60 * 24 * 365, $c->getExpiresTime(), 120);
        // 暗号化されている（平文のメールアドレスではない）。パスワードは含まない
        $this->assertNotSame(self::EMAIL, $c->getValue());
        $this->assertStringNotContainsString('park1234', $c->getValue());
    }

    public function test_logout_keeps_cookie_and_login_page_shows_email(): void
    {
        $this->loginOk();
        $out = $this->post('/logout');
        $out->assertRedirect('/login');
        // 消す cookie（期限切れ）がレスポンスに無い
        $del = collect($out->headers->getCookies())->first(fn ($x) => $x->getName() === self::COOKIE);
        // （テストでは同じアプリ内で queue 済みの cookie が出ることがある。消す指示＝期限切れ・空でなければよい）
        if ($del) {
            $this->assertGreaterThan(time(), $del->getExpiresTime());
            $this->assertNotSame('', (string) $del->getValue());
        }

        $html = $this->withCookie(self::COOKIE, self::EMAIL)->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('value="' . self::EMAIL . '"', $this->inputTag($html, 'email'));
        $this->assertStringContainsString('autofocus', $this->inputTag($html, 'password'));
        $this->assertStringNotContainsString('autofocus', $this->inputTag($html, 'email'));
    }

    public function test_no_cookie_gives_empty_email_and_email_autofocus(): void
    {
        $html = $this->get('/login')->assertOk()->getContent();
        $email = $this->inputTag($html, 'email');
        $this->assertStringContainsString('value=""', $email);
        $this->assertStringContainsString('autofocus', $email);
        $this->assertStringNotContainsString('autofocus', $this->inputTag($html, 'password'));
    }

    public function test_empty_cookie_value_is_treated_as_empty(): void
    {
        $html = $this->withCookie(self::COOKIE, '')->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('autofocus', $this->inputTag($html, 'email'));
    }

    public function test_failed_login_prefers_old_input_over_cookie(): void
    {
        Mail::fake();
        $this->withCookie(self::COOKIE, self::EMAIL)
            ->post('/login', ['email' => 'other@park.example', 'password' => 'wrong-pass'])
            ->assertRedirect();
        $html = $this->withCookie(self::COOKIE, self::EMAIL)->get('/login')->assertOk()->getContent();
        $email = $this->inputTag($html, 'email');
        $this->assertStringContainsString('value="other@park.example"', $email);
        $this->assertStringNotContainsString(self::EMAIL, $email);
    }

    public function test_autocomplete_attributes_unchanged(): void
    {
        $html = $this->withCookie(self::COOKIE, self::EMAIL)->get('/login')->assertOk()->getContent();
        $this->assertStringContainsString('autocomplete="username"', $this->inputTag($html, 'email'));
        $this->assertStringContainsString('autocomplete="current-password"', $this->inputTag($html, 'password'));
    }

    public function test_cookie_value_is_escaped_in_html(): void
    {
        $html = $this->withCookie(self::COOKIE, '"><script>x</script>')->get('/login')->assertOk()->getContent();
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }

    public function test_other_forms_do_not_get_stray_autofocus(): void
    {
        $this->get('/forgot')->assertOk()->assertDontSee('autofocus', false);
    }
}
