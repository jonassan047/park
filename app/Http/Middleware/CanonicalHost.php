<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

// 公開サーバーで www なし・ありの両方から開けると、ブラウザの保存したパスワードが片方でしか使えない。
// APP_URL（https）と違うホスト名で開かれたら、APP_URL のほうへ移す
class CanonicalHost
{
    public function handle(Request $request, Closure $next)
    {
        $app = (string) config('app.url');
        if ($request->isMethod('GET') && str_starts_with($app, 'https://')) {
            $host = parse_url($app, PHP_URL_HOST);
            if ($host && strcasecmp($request->getHost(), $host) !== 0) {
                return redirect()->to('https://'.$host.$request->getRequestUri(), 301);
            }
        }
        return $next($request);
    }
}
