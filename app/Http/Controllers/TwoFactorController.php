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
        // ログインの直後でなく途中から来たとき（コードが無い）は、ここで送る
        if (!TF::pending($r)) TF::send($r);
        return view('two-factor.challenge', ['email' => TF::maskedEmail($u->email), 'devCode' => $r->session()->get(TF::DEV_KEY)]);
    }

    public function challenge(Request $r)
    {
        $u = $r->user();
        $res = TF::check($r, (string) $r->input('code'));
        if ($res !== 'ok') {
            N::audit($u->id, '2fa-fail', $u->email);
            $msg = $res === 'expired' ? 'コードの期限が切れたか、まちがえた回数が多すぎます。「コードを送り直す」を押してください。' : 'コードが違います。メールに書かれた6桁を入れてください。';
            return $this->go('/two-factor/challenge', $msg, 'bad');
        }
        TF::pass($r);
        DB::table('users')->where('id', $u->id)->update(['last_login' => now_str(), 'two_factor_confirmed_at' => now_str()]);
        return redirect()->intended(url($u->home()));
    }

    public function resend(Request $r)
    {
        if (!TF::canResend($r)) return $this->go('/two-factor/challenge', '1分ほど待ってから、もう一度押してください。', 'bad');
        TF::send($r);
        return $this->go('/two-factor/challenge', '確認コードを送り直しました。新しいメールのコードを入れてください。');
    }
}
