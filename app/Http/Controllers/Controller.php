<?php

namespace App\Http\Controllers;

abstract class Controller
{
    // 画面の上に出すお知らせ付きで移動する（type は ok / bad / warn）
    protected function go(string $to, ?string $msg = null, string $type = 'ok')
    {
        $r = redirect($to);
        return $msg ? $r->with('flash', ['msg' => $msg, 'type' => $type]) : $r;
    }
}
