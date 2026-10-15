<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// どの役割でも使う画面：通知・アカウント
class AccountController extends Controller
{
    public function notifications(Request $r)
    {
        $list = DB::table('app_notifications')->where('user_id', $r->user()->id)->orderByDesc('id')->limit(200)->get();
        return view('account.notifications', compact('list'));
    }

    public function openNotification(Request $r, int $id)
    {
        $n = DB::table('app_notifications')->where('id', $id)->where('user_id', $r->user()->id)->first();
        abort_unless($n, 404);
        if (!$n->read_at) DB::table('app_notifications')->where('id', $id)->update(['read_at' => now_str()]);
        return redirect($n->link ?: '/notifications');
    }

    public function readAll(Request $r)
    {
        DB::table('app_notifications')->where('user_id', $r->user()->id)->whereNull('read_at')->update(['read_at' => now_str()]);
        return redirect('/notifications');
    }

    public function show(Request $r) { return view('account.show', ['u' => $r->user()]); }

    public function update(Request $r)
    {
        DB::table('users')->where('id', $r->user()->id)->update(['name' => str_in($r->input('name')) ?? $r->user()->name, 'mail_opt_in' => $r->boolean('mail_opt_in')]);
        return $this->go('/account', '保存しました。');
    }

    public function password(Request $r)
    {
        if (!Hash::check((string) $r->input('current'), $r->user()->password)) return $this->go('/account', '今のパスワードが違います。', 'bad');
        $r->validate(['password' => 'required|min:8|confirmed'], [], ['password' => '新しいパスワード']);
        DB::table('users')->where('id', $r->user()->id)->update(['password' => Hash::make($r->input('password'))]);
        return $this->go('/account', 'パスワードを変えました。');
    }
}
