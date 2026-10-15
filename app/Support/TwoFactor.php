<?php

namespace App\Support;

use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 2要素認証（TOTP。RFC 6238：30秒ごとに変わる6桁のコード）。認証アプリ（Google Authenticator など）で使う
class TwoFactor
{
    const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    const SESSION_KEY = 'two_factor_passed';

    public static function newSecret(): string
    {
        $s = '';
        for ($i = 0; $i < 32; $i++) $s .= self::ALPHABET[random_int(0, 31)];
        return $s;
    }

    private static function base32Decode(string $b32): string
    {
        $bits = '';
        foreach (str_split(strtoupper(preg_replace('/[^A-Za-z2-7]/', '', $b32))) as $c) $bits .= str_pad(decbin(strpos(self::ALPHABET, $c)), 5, '0', STR_PAD_LEFT);
        $out = '';
        foreach (str_split($bits, 8) as $byte) if (strlen($byte) === 8) $out .= chr(bindec($byte));
        return $out;
    }

    public static function codeAt(string $secret, int $step): string
    {
        $h = hash_hmac('sha1', pack('N*', 0, $step), self::base32Decode($secret), true);
        $o = ord($h[19]) & 0xf;
        $n = ((ord($h[$o]) & 0x7f) << 24) | (ord($h[$o + 1]) << 16) | (ord($h[$o + 2]) << 8) | ord($h[$o + 3]);
        return str_pad((string) ($n % 1000000), 6, '0', STR_PAD_LEFT);
    }

    // 合っていれば、使った時間の区切り（step）を返す。前後30秒のずれまで許す
    public static function verify(string $secret, string $code, ?int $after = null): ?int
    {
        $code = preg_replace('/\D/', '', $code);
        if (strlen($code) !== 6) return null;
        $now = intdiv(time(), 30);
        foreach ([0, -1, 1] as $d) {
            $step = $now + $d;
            if ($after !== null && $step <= $after) continue;
            if (hash_equals(self::codeAt($secret, $step), $code)) return $step;
        }
        return null;
    }

    public static function uri(string $secret, string $email): string
    {
        $issuer = rawurlencode(setting('app_name'));
        return "otpauth://totp/{$issuer}:".rawurlencode($email)."?secret={$secret}&issuer={$issuer}&digits=6&period=30";
    }

    public static function qrSvg(string $uri): string
    {
        return (new Writer(new ImageRenderer(new RendererStyle(200, 1), new SvgImageBackEnd())))->writeString($uri);
    }

    public static function newRecoveryCodes(): array
    {
        return array_map(fn () => strtolower(Str::random(5).'-'.Str::random(5)), range(1, 8));
    }

    public static function secretOf($u): ?string
    {
        return $u->two_factor_secret ? Crypt::decryptString($u->two_factor_secret) : null;
    }

    public static function recoveryCodesOf($u): array
    {
        return $u->two_factor_recovery_codes ? json_decode(Crypt::decryptString($u->two_factor_recovery_codes), true) : [];
    }

    // 予備のコードを使ったら、そのコードは消す
    public static function useRecoveryCode($u, string $code): bool
    {
        $code = strtolower(trim($code));
        $codes = self::recoveryCodesOf($u);
        $i = array_search($code, $codes, true);
        if ($i === false) return false;
        unset($codes[$i]);
        DB::table('users')->where('id', $u->id)->update(['two_factor_recovery_codes' => Crypt::encryptString(json_encode(array_values($codes)))]);
        return true;
    }

    public static function saveRecoveryCodes(int $userId, array $codes): void
    {
        DB::table('users')->where('id', $userId)->update(['two_factor_recovery_codes' => Crypt::encryptString(json_encode($codes))]);
    }

    public static function reset(int $userId): void
    {
        DB::table('users')->where('id', $userId)->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null, 'two_factor_last_step' => null]);
    }
}
