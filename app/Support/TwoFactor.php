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

    // 一度確認コードを通った人は、二度と聞かない（DB の two_factor_confirmed_at に日時を入れる。2026-10-17 tezzo）
    public static function confirmed(object $u): bool
    {
        return (bool) \Illuminate\Support\Facades\DB::table('users')->where('id', $u->id)->value('two_factor_confirmed_at');
    }

    // 最後に check() で見た情報（記録の補足用。コード・ハッシュは入れない）
    public static array $last = [];

    // セッションが同じかどうかを見分ける印（セッション ID の sha256 の先頭8文字。ID そのものではない）
    public static function sid(Request $r): string
    {
        return substr(hash('sha256', $r->session()->getId()), 0, 8);
    }

    // $from：どこから送ったか（login・challenge-form・resend・invite）
    public static function send(Request $r, string $from = 'login'): void
    {
        $u = $r->user();
        $skip = self::skipped($u);
        if ($skip || self::confirmed($u)) {
            N::audit($u->id, '2fa-skip', "from={$from} ".($skip ? 'skipped' : 'confirmed'));
            self::pass($r);
            return;
        }
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $r->session()->put(self::PENDING_KEY, ['user' => $u->id, 'hash' => self::hash($code), 'expires' => now()->getTimestamp() + self::TTL, 'tries' => 0, 'sent' => now()->getTimestamp()]);
        $mailId = N::queueMail($u->email, $u->name, "確認コード {$code}", user_label($u)." 様\n\nPARK にログインするための確認コードです。\n\n　{$code}\n\nログインの画面に入れてください（10分間有効）。\n\n心当たりがない場合は、このメールを無視してください。パスワードを知っている誰かがログインしようとしている可能性があるので、パスワードの変更をおすすめします。", '2fa');
        N::audit($u->id, '2fa-sent', "from={$from} mail=#{$mailId} s=".self::sid($r));
        // 手元（メールは保存のみ）で試すときだけ、画面にコードを出す
        if (app()->environment('local') && !N::canSend()) $r->session()->put(self::DEV_KEY, $code);
    }

    public static function canResend(Request $r): bool
    {
        $p = self::pending($r);
        return !$p || now()->getTimestamp() - $p['sent'] >= self::RESEND_WAIT;
    }

    // 'ok'・'wrong'（違うコード）・'no-pending'（セッションにコードが無い）・'user-mismatch'（別の人のコード）
    // 'timeout'（期限切れ）・'too-many'（まちがえすぎ）。詳しい情報は self::$last に入れる
    public static function check(Request $r, string $code): string
    {
        self::$last = [];
        $p = $r->session()->get(self::PENDING_KEY);
        if (!$p) return 'no-pending';
        self::$last = ['tries' => (int) $p['tries'], 'age' => now()->getTimestamp() - (int) $p['sent'], 'pending_user' => (int) $p['user']];
        if ((int) $p['user'] !== (int) $r->user()->id) return 'user-mismatch';
        if (now()->getTimestamp() > $p['expires']) return 'timeout';
        if ($p['tries'] >= self::MAX_TRIES) return 'too-many';
        $code = preg_replace('/\D/', '', mb_convert_kana($code, 'n'));
        if (strlen($code) === 6 && hash_equals($p['hash'], self::hash($code))) return 'ok';
        $p['tries']++;
        $r->session()->put(self::PENDING_KEY, $p);
        self::$last['tries'] = $p['tries'];
        return $p['tries'] >= self::MAX_TRIES ? 'too-many' : 'wrong';
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
