<?php

namespace App\Http\Controllers;

use App\Services\Notifier as N;
use App\Support\TwoFactor as TF;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 2要素認証：パスワードのあと、メールで届いた確認コードを入れる
class TwoFactorController extends Controller
{
    public function challengeForm(Request $r)
    {
        $u = $r->user();
        if ((int) $r->session()->get(TF::SESSION_KEY) === (int) $u->id) return redirect($u->home());
        // 使えるコードが無いとき（途中から来た・期限切れ・まちがえすぎ）は、ここで新しく送る
        $st = TF::state($r);
        $next = ' 新しいメールのコードを入れてください。';
        $flash = null;
        if ($st === 'none' || $st === 'mismatch') {
            TF::send($r, $st === 'none' ? 'challenge-form' : 'challenge-form-mismatch');
            $flash = ['msg' => '確認コードを送りました。メールのコードを入れてください。', 'type' => 'ok'];
        } elseif ($st === 'timeout') {
            TF::send($r, 'challenge-form-timeout');
            $flash = ['msg' => '前のコードの期限が切れたので、新しいコードを送りました。'.trim($next), 'type' => 'ok'];
        } elseif ($st === 'too-many') {
            if (TF::canResend($r)) {
                TF::send($r, 'challenge-form-too-many');
                $flash = ['msg' => 'まちがえた回数が多すぎたので、新しいコードを送りました。'.trim($next), 'type' => 'ok'];
            } else {
                $flash = ['msg' => 'まちがえた回数が多すぎます。1分ほど待ってから「コードを送り直す」を押してください。', 'type' => 'bad'];
            }
        }
        // 送ったときに確認済み・省略で通ったなら、画面を出さずに進む
        if ((int) $r->session()->get(TF::SESSION_KEY) === (int) $u->id) return redirect($u->home());
        if ($flash) $r->session()->now('flash', $flash);
        return view('two-factor.challenge', ['email' => TF::maskedEmail($u->email), 'devCode' => $r->session()->get(TF::DEV_KEY)]);
    }

    public function challenge(Request $r)
    {
        $u = $r->user();
        $res = TF::check($r, (string) $r->input('code'));
        $sid = TF::sid($r); // pass() でセッションが作り直されるので、先に取る
        if ($res !== 'ok') {
            // 理由と補足（メールアドレス・コードは残さない）
            $extra = match ($res) {
                'wrong', 'too-many' => 'tries='.(TF::$last['tries'] ?? 0),
                'timeout' => 'age='.(TF::$last['age'] ?? 0),
                'user-mismatch' => 'pending_user='.(TF::$last['pending_user'] ?? 0),
                default => '',
            };
            N::audit($u->id, '2fa-fail', trim("{$res} {$extra}")." s={$sid}");
            if ($res === 'wrong') return $this->go('/two-factor/challenge', 'コードが違います。メールに書かれた6桁を入れてください。', 'bad');
            // 期限切れ・まちがえすぎなどは、開いた画面（challengeForm）が新しいコードを送って文を出す
            return redirect(url('/two-factor/challenge'));
        }
        N::audit($u->id, '2fa-ok', "s={$sid}");
        TF::pass($r);
        DB::table('users')->where('id', $u->id)->update(['last_login' => now_str(), 'two_factor_confirmed_at' => now_str()]);
        return redirect()->intended(url($u->home()));
    }

    public function resend(Request $r)
    {
        if (!TF::canResend($r)) return $this->go('/two-factor/challenge', '1分ほど待ってから、もう一度押してください。', 'bad');
        TF::send($r, 'resend');
        return $this->go('/two-factor/challenge', '確認コードを送り直しました。新しいメールのコードを入れてください。');
    }
}
