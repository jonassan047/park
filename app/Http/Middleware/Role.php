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
        if ((int) $request->session()->get(TwoFactor::SESSION_KEY) !== (int) $u->id) return redirect()->guest(url('/two-factor/challenge'));
        if ($roles && !in_array($u->role, $roles, true)) abort(403, 'このページを見る権限がありません。');
        return $next($request);
    }
}
