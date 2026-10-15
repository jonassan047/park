<?php

namespace App\Http\Controllers;

use App\Services\Notifier as N;
use App\Support\TwoFactor as TF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

// 2要素認証：最初の設定（QR コードを読み取る）・ログインのときのコード入力・予備のコード
class TwoFactorController extends Controller
{
    private function passed(Request $r): void
    {
        $r->session()->put(TF::SESSION_KEY, $r->user()->id);
        $r->session()->regenerate();
    }

    public function setupForm(Request $r)
    {
        $u = $r->user();
        if ($u->two_factor_confirmed_at) return redirect('/two-factor/challenge');
        // 設定が終わるまでは、毎回同じ鍵を見せる（読み取り直しても合うように）
        $secret = $r->session()->get('two_factor_setup_secret') ?: TF::newSecret();
        $r->session()->put('two_factor_setup_secret', $secret);
        return view('two-factor.setup', ['secret' => $secret, 'qr' => TF::qrSvg(TF::uri($secret, $u->email))]);
    }

    public function setup(Request $r)
    {
        $u = $r->user();
        if ($u->two_factor_confirmed_at) return redirect('/two-factor/challenge');
        $secret = $r->session()->get('two_factor_setup_secret');
        $step = $secret ? TF::verify($secret, (string) $r->input('code')) : null;
        if ($step === null) return $this->go('/two-factor/setup', 'コードが違います。認証アプリに出ている、いまの6桁を入れてください。', 'bad');
        $codes = TF::newRecoveryCodes();
        DB::table('users')->where('id', $u->id)->update(['two_factor_secret' => Crypt::encryptString($secret), 'two_factor_confirmed_at' => now_str(), 'two_factor_last_step' => (string) $step]);
        TF::saveRecoveryCodes($u->id, $codes);
        $r->session()->forget('two_factor_setup_secret');
        N::audit($u->id, '2fa-enable', $u->email);
        Auth::setUser(\App\Models\User::find($u->id)); // 設定済みになった状態で読み直す
        $this->passed($r);
        $r->session()->flash('recovery_codes', $codes);
        return redirect('/two-factor/recovery-codes');
    }

    public function challengeForm(Request $r)
    {
        if (!$r->user()->two_factor_confirmed_at) return redirect('/two-factor/setup');
        if ((int) $r->session()->get(TF::SESSION_KEY) === (int) $r->user()->id) return redirect($r->user()->home());
        return view('two-factor.challenge');
    }

    public function challenge(Request $r)
    {
        if (!$r->user()->two_factor_confirmed_at) return redirect('/two-factor/setup');
        $u = DB::table('users')->find($r->user()->id); // 最後に使ったコード・予備のコードは、いまの値で確かめる
        $code = trim((string) $r->input('code'));
        $ok = false;
        if (preg_match('/^[\d\s]{6,7}$/', $code)) {
            $step = TF::verify(TF::secretOf($u), $code, $u->two_factor_last_step !== null ? (int) $u->two_factor_last_step : null);
            if ($step !== null) { DB::table('users')->where('id', $u->id)->update(['two_factor_last_step' => (string) $step]); $ok = true; }
        } elseif ($code !== '' && TF::useRecoveryCode($u, $code)) {
            $ok = true;
            N::audit($u->id, '2fa-recovery', '予備のコードでログイン（残り '.count(TF::recoveryCodesOf(DB::table('users')->find($u->id))).'個）');
        }
        if (!$ok) {
            N::audit($u->id, '2fa-fail', $u->email);
            return $this->go('/two-factor/challenge', 'コードが違います。', 'bad');
        }
        $this->passed($r);
        DB::table('users')->where('id', $u->id)->update(['last_login' => now_str()]);
        return redirect()->intended(url($r->user()->home()));
    }

    public function recoveryCodes(Request $r)
    {
        $codes = $r->session()->get('recovery_codes');
        if (!$codes) return redirect($r->user()->home());
        return view('two-factor.recovery', ['codes' => $codes]);
    }

    // アカウントの画面から：予備のコードを作り直す（前のものは使えなくなる）
    public function regenerate(Request $r)
    {
        $codes = TF::newRecoveryCodes();
        TF::saveRecoveryCodes($r->user()->id, $codes);
        N::audit($r->user()->id, '2fa-recovery-regenerate', $r->user()->email);
        $r->session()->flash('recovery_codes', $codes);
        return redirect('/two-factor/recovery-codes');
    }

    // 運営が、スマホをなくした人などの2要素認証を解除する（次のログインで設定し直す）
    public function adminReset(Request $r, int $id)
    {
        $u = DB::table('users')->find($id);
        abort_unless($u, 404);
        TF::reset($id);
        N::audit($r->user()->id, '2fa-reset', "{$u->name} <{$u->email}>");
        N::queueMail($u->email, $u->name, '2要素認証を解除しました', "{$u->name} 様\n\n運営が、あなたのアカウントの2要素認証を解除しました。次にログインするとき、認証アプリでの設定をもう一度お願いします。\n\n心当たりがない場合は、すぐに運営にご連絡ください。", '2fa');
        return back()->with('flash', ['msg' => "{$u->name} さんの2要素認証を解除しました。次のログインで設定し直します。", 'type' => 'ok']);
    }
}
