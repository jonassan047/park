<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// ログイン前の画面：トップ・ログイン・SES の参加申込・招待（パスワード設定）・パスワード再設定・最初の運営アカウント
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
        return $r->user() ? redirect($r->user()->home()) : view('public.login');
    }

    public function login(Request $r)
    {
        $email = strtolower(trim((string) $r->input('email')));
        if (!Auth::attempt(['email' => $email, 'password' => (string) $r->input('password'), 'active' => 1], true)) {
            return back()->withInput(['email' => $email])->with('flash', ['msg' => 'メールアドレスかパスワードが違います。', 'type' => 'bad']);
        }
        $u = Auth::user();
        if ($u->role === 'ses' && DB::table('ses_companies')->where('id', $u->ses_id)->value('status') !== 'active') {
            Auth::logout();
            return back()->withInput(['email' => $email])->with('flash', ['msg' => '会社の参加がまだ承認されていないか、停止されています。', 'type' => 'bad']);
        }
        $r->session()->regenerate();
        DB::table('users')->where('id', $u->id)->update(['last_login' => now_str()]);
        return redirect()->intended(url($u->home()));
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
        $r->validate(['name' => 'required', 'email' => 'required|email', 'password' => 'required|min:8'], [], ['password' => 'パスワード']);
        $id = W::createUser($r->input('email'), $r->input('name'), 'admin', $r->input('password'));
        Auth::loginUsingId($id);
        $r->session()->regenerate();
        return $this->go('/admin/settings', '運営のアカウントを作りました。まず設定を確認してください。');
    }

    public function applyForm() { return view('public.apply'); }

    public function apply(Request $r)
    {
        $r->validate(['name' => 'required', 'contact_name' => 'required', 'email' => 'required|email', 'agree' => 'accepted'], [], ['name' => '会社名', 'contact_name' => 'ご担当者名', 'agree' => '守秘義務への同意']);
        W::applySes($r->all());
        return view('public.message', ['title' => 'お申し込みを受け付けました', 'text' => 'ありがとうございます。運営で内容を確認のうえ、ご連絡します。']);
    }

    // エンジニアが、まだ個人情報の取り扱いに同意していないか
    private function needConsent($u): bool
    {
        return $u->role === 'eg' && $u->engineer_id && !DB::table('engineers')->where('id', $u->engineer_id)->value('consent_at');
    }

    public function inviteForm(string $token)
    {
        $u = W::userByToken($token);
        if (!$u) return view('public.message', ['title' => 'リンクの期限切れ', 'text' => 'このリンクは使えません（期限切れか、使用済み）。運営またはご担当の SES にご連絡ください。']);
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
        $r->session()->regenerate();
        return $this->go(User::find($u->id)->home(), $u->role === 'eg' ? 'ようこそ。まずプロフィールと経験票を入れて、スキルシートを作りましょう。' : 'ようこそ。');
    }

    public function forgotForm() { return view('public.forgot'); }

    public function forgot(Request $r)
    {
        $u = DB::table('users')->where('email', strtolower(trim((string) $r->input('email'))))->where('active', 1)->first();
        if ($u) {
            $t = W::issueToken($u->id, 1);
            N::queueMail($u->email, $u->name, 'パスワードの再設定', "{$u->name} 様\n\n次のリンクから、新しいパスワードを設定してください（24時間有効）。\n".url("/invite/$t")."\n\n心当たりがない場合は、このメールを無視してください。", 'reset');
        }
        return view('public.message', ['title' => 'メールを送りました', 'text' => '登録があれば、再設定のリンクをメールで送りました。']);
    }
}
