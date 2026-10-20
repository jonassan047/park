<?php

namespace App\Http\Middleware;

use App\Services\Workflow as W;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 兼任（エンジニアと、SES 担当者・管理者を兼ねる）の人の「いまの立場」を、毎回の画面で $u->role に入れる。
// 立場はログインのときに選んだもの（セッションの acting_role）。DB の users.role は書き換えない。
// 立場ごとの見える範囲は、今までの Perms・role ミドルウェアにそのまま任せる。
class ActingRole
{
    public function handle(Request $request, Closure $next)
    {
        $u = $request->user();
        if ($u) {
            $row = DB::table('users')->where('id', $u->id)->first();
            if ($row && W::isDual($row)) {
                $request->attributes->set('park_dual', true);
                $request->attributes->set('park_base_role', $row->role);
                $acting = $request->session()->get('acting_role');
                if ($acting) {
                    if (in_array($acting, W::usableRoles($row), true)) {
                        $u->role = $acting;
                        $u->syncOriginalAttribute('role'); // 保存されても、DB の role は書き換えない
                    } else {
                        // 入っている間に、この立場が使えなくなった（停止など）。入り直してもらう
                        auth()->logout();
                        $request->session()->invalidate();
                        $request->session()->regenerateToken();
                        return redirect('/login')->with('flash', ['msg' => 'この立場では、いまは入れません。もう一度ログインしてください。', 'type' => 'bad']);
                    }
                }
            } elseif ($row && $request->session()->has('acting_role')) {
                $acting = $request->session()->get('acting_role');
                $request->session()->forget('acting_role'); // 兼任でなくなった（退会など）。古い立場は忘れる
                // 入っていた立場が、いまの本来の役割と違うなら（エンジニアの立場だったなど）、入り直してもらう
                if ($acting !== $row->role) {
                    auth()->logout();
                    $request->session()->invalidate();
                    $request->session()->regenerateToken();
                    return redirect('/login')->with('flash', ['msg' => 'この立場では、いまは入れません。もう一度ログインしてください。', 'type' => 'bad']);
                }
            }
        }
        return $next($request);
    }
}
