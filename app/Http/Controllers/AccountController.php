<?php

namespace App\Http\Controllers;

use App\Services\Workflow as W;
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
        $r->validate($this->profileRules(false), [], $this->profileNames());
        W::saveProfile($r->user(), $r->all());
        DB::table('users')->where('id', $r->user()->id)->update(['mail_opt_in' => $r->boolean('mail_opt_in')]);
        return $this->go('/account', '保存しました。');
    }

    // 最初の情報登録の入力欄の決まり（氏名は、アカウントの画面では空でもよい＝今の名前のまま）
    private function profileRules(bool $nameRequired): array
    {
        return ['name' => ($nameRequired ? 'required|' : 'nullable|').'max:100', 'kana' => 'nullable|max:100', 'phone' => ['nullable', 'max:30', 'regex:/^[0-9+\-() ]*$/'], 'position' => 'nullable|max:100'];
    }

    private function profileNames(): array
    {
        return ['name' => '氏名', 'kana' => 'ふりがな', 'phone' => '電話', 'position' => '所属・役職'];
    }

    // 最初のログインで出す「あなたの情報」。済んでいる人は、アカウントの画面へ
    public function welcomeForm(Request $r)
    {
        $u = $r->user();
        if ($u->profile_completed_at) return redirect('/account');
        $v = (object) ['name' => $u->name, 'kana' => $u->kana, 'phone' => $u->phone, 'position' => $u->position];
        if ($u->role === 'eg' && $u->engineer_id) {
            $e = DB::table('engineers')->find($u->engineer_id);
            $v->name = $u->name ?: ($e->name ?? '');
            $v->kana = $u->kana ?: ($e->kana ?? null);
        }
        return view('account.welcome', ['u' => $u, 'v' => $v]);
    }

    public function welcome(Request $r)
    {
        $r->validate($this->profileRules(true), [], $this->profileNames());
        $u = $r->user();
        W::saveProfile($u, $r->all());
        [$to, $msg] = W::profileHome($u);
        return $this->go($to, $msg ?? '登録しました。');
    }

    public function password(Request $r)
    {
        if (!Hash::check((string) $r->input('current'), $r->user()->password)) return $this->go('/account', '今のパスワードが違います。', 'bad');
        $r->validate(['password' => 'required|min:8|confirmed'], [], ['password' => '新しいパスワード']);
        DB::table('users')->where('id', $r->user()->id)->update(['password' => Hash::make($r->input('password'))]);
        return $this->go('/account', 'パスワードを変えました。');
    }
}
