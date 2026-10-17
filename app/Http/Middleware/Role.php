<?php

namespace App\Http\Middleware;

use App\Support\TwoFactor;
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
        // 最初の情報登録がまだの人は、先に登録の画面へ（アカウントの画面とログアウトはそのまま使える）
        if (!$u->profile_completed_at && !$request->is('welcome', 'account', 'account/*')) return redirect('/welcome');
        if ($roles && !in_array($u->role, $roles, true)) abort(403, 'このページを見る権限がありません。');
        return $next($request);
    }
}
