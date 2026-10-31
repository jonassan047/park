<?php

namespace App\Http\Middleware;

use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\Requirements;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\DB;
use Closure;
use Illuminate\Http\Request;

// role:admin,ses のように、その役割の人だけ通す。
// 2要素認証（メールの確認コード）が済んでいない人は、コードの入力へ回す
class Role
{
    public function handle(Request $request, Closure $next, string ...$roles)
    {
        $u = $request->user();
        if (!$u || !$u->active) return redirect()->guest(url('/login'));
        if ((int) $request->session()->get(TwoFactor::SESSION_KEY) !== (int) $u->id) {
            // 一度確認コードを通った人は、聞かない（ブラウザに残ったログインの記録から戻ってきたときも）
            if (TwoFactor::confirmed($u)) {
                $request->session()->put(TwoFactor::SESSION_KEY, $u->id);
            } elseif (TwoFactor::pending($request)) {
                return redirect()->guest(url('/two-factor/challenge')); // ログインの途中。コードを入れてもらう
            } else {
                // パスワードを入れた記録（自動ログイン）だけが残っている。ログイン画面からやり直してもらう
                auth()->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                return redirect()->guest(url('/login'));
            }
        }
        // 兼任の人：ログインのときに、どの立場で入るかを選ぶ（選ぶまで、ほかの画面には行けない）
        if (is_dual()) {
            if (!$request->session()->get('acting_role')) {
                $row = DB::table('users')->where('id', $u->id)->first();
                $usable = W::usableRoles($row);
                if (!$usable) {
                    auth()->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    return redirect('/login')->with('flash', ['msg' => 'このアカウントは、いまは使えません。管理者にご連絡ください。', 'type' => 'bad']);
                }
                if (count($usable) === 1) {
                    // 使える立場が1つなら、画面を出さずにその立場で入る
                    $request->session()->put('acting_role', $usable[0]);
                    N::audit($u->id, 'login-role', $usable[0]);
                    $u->role = $usable[0];
                    $u->syncOriginalAttribute('role');
                } elseif ($request->is('login/role')) {
                    return $next($request);
                } else {
                    return redirect('/login/role');
                }
            }
        }
        // 必須項目や同意が足りない人は、いまの立場で「足りない項目の画面」だけを通す（判定は立場ごと。ログアウトはそのまま使える）
        if (!$request->is('register/complete') && Requirements::missing(DB::table('users')->find($u->id), $u->role)) return redirect('/register/complete');
        if ($roles && !in_array($u->role, $roles, true)) abort(403, 'このページを見る権限がありません。');
        return $next($request);
    }
}
