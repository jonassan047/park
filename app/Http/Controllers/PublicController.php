<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// ログイン前の画面：トップ・ログイン・SES の参加申込・招待（パスワード設定）・パスワード再設定・最初の管理者アカウント
class PublicController extends Controller
{
    public function home(Request $r)
    {
        if (!DB::table('users')->where('role', 'admin')->exists()) return redirect('/setup');
        if ($r->user()) return redirect($r->user()->home());
        return view('public.home');
    }

    public function loginForm(Request $r)
    {
        return $r->user() ? redirect($r->user()->home()) : view('public.login', ['lastEmail' => (string) $r->cookie('park-last-email', '')]);
    }

    public function login(Request $r)
    {
        $email = strtolower(trim((string) $r->input('email')));
        if (!Auth::attempt(['email' => $email, 'password' => (string) $r->input('password'), 'active' => 1], true)) {
            return back()->withInput(['email' => $email])->with('flash', ['msg' => 'メールアドレスかパスワードが違います。', 'type' => 'bad']);
        }
        $u = Auth::user();
        if ($u->role === 'ses' && (DB::table('ses_companies')->where('id', $u->ses_id)->value('status') !== 'active' || W::isDirect($u->ses_id))) {
            Auth::logout();
            return back()->withInput(['email' => $email])->with('flash', ['msg' => '会社の参加がまだ承認されていないか、停止されています。', 'type' => 'bad']);
        }
        $r->session()->regenerate();
        // 次にログイン画面を開いたとき、メールアドレスを最初から入れておく（1年。パスワードは残さない）
        Cookie::queue('park-last-email', $email, 60 * 24 * 365);
        // パスワードの次は2要素認証（メールで確認コードを送る）。済むまでほかの画面は開けない
        N::audit($u->id, 'login', '');
        TwoFactor::send($r, 'login');
        return redirect('/two-factor/challenge');
    }

    public function logout(Request $r)
    {
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        return redirect('/login');
    }

    public function setupForm()
    {
        if (DB::table('users')->where('role', 'admin')->exists()) return redirect('/login');
        return view('public.setup');
    }

    public function setup(Request $r)
    {
        if (DB::table('users')->where('role', 'admin')->exists()) return redirect('/login');
        $r->validate(['email' => 'required|email', 'password' => 'required|min:8'], [], ['password' => 'パスワード']);
        [$full, $sei, $mei] = name_parts($r->all(), 'name', 'name');
        if ($full === null) return back()->withInput()->with('flash', ['msg' => 'お名前（姓）を入れてください。', 'type' => 'bad']);
        $id = W::createUser($r->input('email'), $full, 'admin', $r->input('password'), ['sei' => $sei, 'mei' => $mei]);
        Auth::loginUsingId($id);
        $r->session()->regenerate();
        TwoFactor::pass($r); // 最初の管理者を作るときだけ（まだ誰もいないので）
        return $this->go('/welcome', '管理者のアカウントを作りました。続けて、あなたの情報を登録してください。');
    }

    public function applyForm() { return view('public.apply'); }

    public function apply(Request $r)
    {
        $r->validate(['name' => 'required', 'email' => 'required|email', 'agree' => 'accepted'], [], ['name' => '会社名', 'agree' => '守秘義務への同意']);
        if (name_parts($r->all(), 'contact', 'contact_name')[0] === null) return back()->withInput()->with('flash', ['msg' => 'ご担当者名（姓）を入れてください。', 'type' => 'bad']);
        W::applySes($r->all());
        return view('public.message', ['title' => 'お申し込みを受け付けました', 'text' => 'ありがとうございます。管理者が内容を確認のうえ、ご連絡します。']);
    }

    // エンジニアが、まだ個人情報の取り扱いに同意していないか
    private function needConsent($u): bool
    {
        return $u->role === 'eg' && $u->engineer_id && !DB::table('engineers')->where('id', $u->engineer_id)->value('consent_at');
    }

    public function inviteForm(string $token)
    {
        $u = W::userByToken($token);
        if (!$u) return view('public.message', ['title' => 'リンクの期限切れ', 'text' => 'このリンクは使えません（期限切れか、使用済み）。管理者またはご担当の SES にご連絡ください。']);
        return view('public.invite', ['u' => $u, 'token' => $token, 'needConsent' => $this->needConsent($u)]);
    }

    public function invite(Request $r, string $token)
    {
        $u = W::userByToken($token);
        if (!$u) return $this->go('/login', 'リンクの期限が切れています。', 'bad');
        $rules = ['password' => 'required|min:8|confirmed'];
        if ($this->needConsent($u)) $rules['consent'] = 'accepted';
        $r->validate($rules, [], ['password' => 'パスワード', 'consent' => '個人情報の取り扱いへの同意']);
        if ($this->needConsent($u)) DB::table('engineers')->where('id', $u->engineer_id)->update(['consent_at' => now_str()]);
        DB::table('users')->where('id', $u->id)->update(['password' => Hash::make($r->input('password')), 'token' => null, 'token_expires' => null, 'last_login' => now_str()]);
        N::audit($u->id, 'password-set', $u->email);
        Auth::loginUsingId($u->id);
        // 初めてのログインも、登録のメールアドレスとパスワードに加えて、確認コードで2段階認証を通ってもらう
        TwoFactor::send($r, 'invite');
        if (TwoFactor::confirmed($u)) return $this->go(Auth::user()->home(),'パスワードを設定しました。');
        return $this->go('/two-factor/challenge', 'パスワードを設定しました。メールで届いた確認コードを入れてください。');
    }

    public function forgotForm() { return view('public.forgot'); }

    public function forgot(Request $r)
    {
        $u = DB::table('users')->where('email', strtolower(trim((string) $r->input('email'))))->where('active', 1)->first();
        if ($u) {
            $t = W::issueToken($u->id, 1);
            N::queueMail($u->email, $u->name, 'パスワードの再設定', user_label($u)." 様\n\n次のリンクから、新しいパスワードを設定してください（24時間有効）。\n".url("/invite/$t")."\n\n心当たりがない場合は、このメールを無視してください。", 'reset');
        }
        return view('public.message', ['title' => 'メールを送りました', 'text' => '登録があれば、再設定のリンクをメールで送りました。']);
    }
}
