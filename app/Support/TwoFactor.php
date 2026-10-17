<?php

namespace App\Support;

use App\Services\Notifier as N;
use Illuminate\Http\Request;

// 2要素認証（メールの確認コード）。パスワードのあと、登録のメールアドレスに6桁のコードを送り、入れてもらう。
// コードはセッションにだけ（ハッシュで）置く。10分で切れ、5回まちがえたら送り直してもらう
class TwoFactor
{
    const SESSION_KEY = 'two_factor_passed';
    const PENDING_KEY = 'two_factor_pending';
    const DEV_KEY = 'two_factor_dev_code';
    const TTL = 600;
    const MAX_TRIES = 5;
    const RESEND_WAIT = 60;

    private static function hash(string $code): string
    {
        return hash_hmac('sha256', $code, config('app.key'));
    }

    public static function pending(Request $r): ?array
    {
        $p = $r->session()->get(self::PENDING_KEY);
        return $p && (int) $p['user'] === (int) $r->user()->id ? $p : null;
    }

    // 手元（APP_ENV=local）で .env に PARK_SKIP_2FA=true を書いたときだけ、確認コードを省く。公開版では効かない
    // PARK_SKIP_2FA_DOMAIN を書いたときは、そのドメインのメールアドレスの人だけ省く（メールの届かないテスト用の人向け）
    public static function skipped(?object $u = null): bool
    {
        if (app()->environment('local') && config('app.skip_2fa')) return true;
        $d = config('app.skip_2fa_domain');
        return $d !== '' && $u && str_ends_with(strtolower((string) $u->email), '@'.$d);
    }

    public static function send(Request $r): void
    {
        $u = $r->user();
        if (self::skipped($u)) { self::pass($r); return; }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $r->session()->put(self::PENDING_KEY, ['user' => $u->id, 'hash' => self::hash($code), 'expires' => now()->getTimestamp() + self::TTL, 'tries' => 0, 'sent' => now()->getTimestamp()]);
        N::queueMail($u->email, $u->name, "確認コード {$code}", user_label($u)." 様\n\nPARK にログインするための確認コードです。\n\n　{$code}\n\nログインの画面に入れてください（10分間有効）。\n\n心当たりがない場合は、このメールを無視してください。パスワードを知っている誰かがログインしようとしている可能性があるので、パスワードの変更をおすすめします。", '2fa');
        // 手元（メールは保存のみ）で試すときだけ、画面にコードを出す
        if (app()->environment('local') && !N::canSend()) $r->session()->put(self::DEV_KEY, $code);
    }

    public static function canResend(Request $r): bool
    {
        $p = self::pending($r);
        return !$p || now()->getTimestamp() - $p['sent'] >= self::RESEND_WAIT;
    }

    // 'ok'・'bad'（違う）・'expired'（期限切れか、まちがえすぎ）
    public static function check(Request $r, string $code): string
    {
        $p = self::pending($r);
        if (!$p || now()->getTimestamp() > $p['expires'] || $p['tries'] >= self::MAX_TRIES) return 'expired';
        $code = preg_replace('/\D/', '', mb_convert_kana($code, 'n'));
        if (strlen($code) === 6 && hash_equals($p['hash'], self::hash($code))) return 'ok';
        $p['tries']++;
        $r->session()->put(self::PENDING_KEY, $p);
        return $p['tries'] >= self::MAX_TRIES ? 'expired' : 'bad';
    }

    // 2要素認証を通ったことにする（確認コードのほか、メールで届いた招待・再設定のリンクから来たとき）
    public static function pass(Request $r): void
    {
        $r->session()->forget([self::PENDING_KEY, self::DEV_KEY]);
        $r->session()->put(self::SESSION_KEY, $r->user()->id);
        $r->session()->regenerate();
    }

    // 画面に出すメールアドレス（ta***@gmail.com）
    public static function maskedEmail(string $email): string
    {
        [$name, $domain] = array_pad(explode('@', $email, 2), 2, '');
        return mb_substr($name, 0, 2).'***@'.$domain;
    }
}
