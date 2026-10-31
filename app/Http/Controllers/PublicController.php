<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Services\Notifier as N;
use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\Requirements;
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
        // 会社が使えない SES 担当者は断る。ただし、エンジニアを兼ねていてエンジニアが使えるなら断らない（選ぶ画面でエンジニアだけになる）
        if ($u->role === 'ses' && (DB::table('ses_companies')->where('id', $u->ses_id)->value('status') !== 'active' || W::isDirect($u->ses_id))) {
            $row = DB::table('users')->find($u->id);
            if (!(W::isDual($row) && in_array('eg', W::usableRoles($row), true))) {
                Auth::logout();
                return back()->withInput(['email' => $email])->with('flash', ['msg' => '会社の参加がまだ承認されていないか、停止されています。', 'type' => 'bad']);
            }
        }
        $r->session()->forget('acting_role'); // 立場は、ログインのたびに選び直す
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
        // 企業名・姓・名・電話・メール・パスワードを1画面で入れる（K33）。必須の決まりは、本登録の画面と同じ（Requirements）
        $only = ['company_name', 'name_sei', 'name_mei', 'phone'];
        $data = Registration::cleanInput($r->all(), 'admin', false, $only);
        $r->merge($data);
        [$rules, $msg, $attr] = Registration::rules('admin', false, $only);
        $rules += ['email' => 'required|email', 'password' => 'required|min:8'];
        $attr += ['email' => 'メールアドレス', 'password' => 'パスワード'];
        $r->validate($rules, $msg, $attr);
        $id = W::createUser((string) $r->input('email'), (string) join_name($data['name_sei'], $data['name_mei']), 'admin', (string) $r->input('password'), ['sei' => $data['name_sei'], 'mei' => $data['name_mei']]);
        DB::table('users')->where('id', $id)->update(['company_name' => $data['company_name'], 'phone' => $data['phone'], 'profile_completed_at' => now_str()]);
        Auth::loginUsingId($id);
        $r->session()->regenerate();
        TwoFactor::pass($r); // 最初の管理者を作るときだけ（まだ誰もいないので）。確認コードは聞かない
        return $this->go('/admin', '管理者のアカウントを作りました。');
    }

    public function applyForm() { return view('public.apply'); }

    public function apply(Request $r)
    {
        $r->validate(['name' => 'required', 'email' => 'required|email', 'agree' => 'accepted'], [], ['name' => '会社名', 'agree' => '守秘義務への同意']);
        if (name_parts($r->all(), 'contact', 'contact_name')[0] === null) return back()->withInput()->with('flash', ['msg' => 'ご担当者名（姓）を入れてください。', 'type' => 'bad']);
        $ad = W::addressCols($r->all(), '所在地');
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        W::applySes($r->all());
        return view('public.message', ['title' => 'お申し込みを受け付けました', 'text' => 'ありがとうございます。管理者が内容を確認のうえ、ご連絡します。']);
    }

    // 使えないリンクを開いたとき。誰のものかは出さず、どんな理由でも同じ文
    private function unusable()
    {
        return view('public.message', ['title' => T::LINK_UNUSABLE_TITLE, 'text' => T::LINK_UNUSABLE]);
    }

    // 本登録の画面（メールのリンクから）。リンクの種類が違うときは、正しい画面へ転送する
    public function inviteForm(string $token)
    {
        $row = Registration::tokenRow($token);
        if ($row && $row->token_kind === 'reset') return redirect("/reset/$token");
        $u = Registration::userByRegisterToken($token);
        if (!$u) return $this->unusable();
        $isRep = Requirements::isRep($u);
        return view('public.invite', ['u' => $u, 'token' => $token, 'role' => $u->role, 'isRep' => $isRep, 'v' => Requirements::values($u, $u->role, $isRep)]);
    }

    // 本登録の入力を検査して、DB には書かず保留にし、確認コードへ進む。保存は確認コードを通ったとき（Registration::finishPending）
    public function invite(Request $r, string $token)
    {
        $row = Registration::tokenRow($token);
        if ($row && $row->token_kind === 'reset') return redirect("/reset/$token");
        $u = Registration::userByRegisterToken($token);
        if (!$u) return $this->unusable();
        $isRep = Requirements::isRep($u);
        $data = Registration::cleanInput($r->all(), $u->role, $isRep);
        $r->merge($data);
        [$rules, $msg, $attr] = Registration::rules($u->role, $isRep);
        $rules['password'] = 'required|string|min:8|confirmed';
        $attr['password'] = 'パスワード';
        $r->validate($rules, $msg, $attr);
        // 今のログインを外してセッションを作り直し、この人として確認コードの画面へ（保存はまだ）
        Auth::logout();
        $r->session()->invalidate();
        $r->session()->regenerateToken();
        Auth::loginUsingId($u->id);
        Registration::stash($r, $u, $token, $data, Hash::make((string) $r->input('password')));
        TwoFactor::send($r, 'invite'); // 確認コードを省く環境では、ここで完了する
        if (!$r->user()) return redirect('/login')->with('flash', ['msg' => Registration::notDoneMessage(), 'type' => 'warn']); // 途中でリンクが新しくなった・使えなくなった
        if ((int) $r->session()->get(TwoFactor::SESSION_KEY) === (int) $r->user()->id) {
            $warn = Registration::$lastWarn; // 同じ名前の SES がほかにもあるときの注意（確認コードの道と同じ）
            return $this->go($r->user()->home(), '本登録が完了しました。'.($warn ?: ''), $warn ? 'warn' : 'ok');
        }
        return $this->go('/two-factor/challenge', 'メールで届いた確認コードを入れてください。入れると本登録が完了します。');
    }

    // 「心当たりがない」：リンクを無効にして、登録した人に知らせる
    public function decline(Request $r, string $token)
    {
        $u = Registration::userByRegisterToken($token);
        if (!$u) return $this->unusable();
        if ($r->user() && (int) $r->user()->id === (int) $u->id) {
            Auth::logout();
            $r->session()->invalidate();
            $r->session()->regenerateToken();
        }
        Registration::decline($u);
        return view('public.message', ['title' => 'リンクを無効にしました', 'text' => T::DECLINED_DONE]);
    }

    public function forgotForm() { return view('public.forgot'); }

    // 画面の文は、登録の有無にかかわらず同じ。本登録が済んでいない人には、再設定ではなく「送り直しの依頼」の案内を送る
    public function forgot(Request $r)
    {
        $in = $r->input('email');
        $email = is_string($in) ? strtolower(trim($in)) : ''; // 配列などを送られても落ちない
        $u = $email === '' ? null : DB::table('users')->where('email', $email)->where('active', 1)->whereNull('left_at')->first();
        if ($u && !$u->registered_at) {
            // 同じアドレスへは1時間に1通まで（リンクは変えない）
            $recent = DB::table('mail_outbox')->where('to_email', $u->email)->where('kind', 'reg-help')->where('created_at', '>', date('Y-m-d H:i:s', time() - 3600))->exists();
            if (!$recent) N::queueMail($u->email, $u->name, T::HELP_SUBJECT, T::HELP_BODY, 'reg-help');
        } elseif ($u && Registration::companyAllows($u)) {
            $t = W::issueToken($u->id, 1, 'reset');
            N::queueMail($u->email, $u->name, 'パスワードの再設定', user_label($u)." 様\n\n次のリンクから、新しいパスワードを設定してください（24時間有効）。\n".url("/reset/$t")."\n\n心当たりがない場合は、このメールを無視してください。", 'reset');
        }
        return view('public.message', ['title' => 'メールを送りました', 'text' => T::FORGOT_DONE]);
    }

    // パスワードの再設定（本登録が済んでいる人だけ。パスワードだけを聞く）
    public function resetForm(string $token)
    {
        $row = Registration::tokenRow($token);
        if ($row && $row->token_kind === 'register') return redirect("/invite/$token");
        if (!Registration::userByResetToken($token)) return $this->unusable();
        return view('public.reset', ['token' => $token]);
    }

    public function reset(Request $r, string $token)
    {
        $row = Registration::tokenRow($token);
        if ($row && $row->token_kind === 'register') return redirect("/invite/$token");
        $u = Registration::userByResetToken($token);
        if (!$u) return $this->unusable();
        $r->validate(['password' => 'required|string|min:8|confirmed'], [], ['password' => 'パスワード']);
        DB::table('users')->where('id', $u->id)->update(['password' => Hash::make((string) $r->input('password')), 'token' => null, 'token_expires' => null, 'token_kind' => null, 'last_login' => now_str(), 'updated_at' => now_str()]);
        N::audit($u->id, 'password-reset', '');
        Auth::loginUsingId($u->id);
        $r->session()->forget('acting_role');
        // 確認コードは今までどおり（一度通った人は聞かれない）
        TwoFactor::send($r, 'reset');
        if ((int) $r->session()->get(TwoFactor::SESSION_KEY) === (int) $u->id) return $this->go(Auth::user()->home(), 'パスワードを設定しました。');
        return $this->go('/two-factor/challenge', 'パスワードを設定しました。メールで届いた確認コードを入れてください。');
    }
}
