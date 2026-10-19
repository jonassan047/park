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

    public function show(Request $r)
    {
        $u = $r->user();
        return view('account.show', ['u' => $u, 'sesName' => $u->role === 'ses' ? DB::table('ses_companies')->where('id', $u->ses_id)->value('name') : null]);
    }

    public function update(Request $r)
    {
        $this->trimNames($r);
        // まだ登録していない人は、ここからの登録でも企業名・SES名を必須にする
        $r->validate($this->profileRules(!$r->user()->profile_completed_at, $r->user()->role), [], $this->profileNames());
        $warn = W::saveProfile($r->user(), $r->all());
        DB::table('users')->where('id', $r->user()->id)->update(['mail_opt_in' => $r->boolean('mail_opt_in')]);
        return $warn ? $this->go('/account', '保存しました。'.$warn, 'warn') : $this->go('/account', '保存しました。');
    }

    // 自分の登録情報を見るだけの画面（入力欄なし）
    public function profile(Request $r)
    {
        $u = $r->user();
        $sesName = $u->role === 'ses' ? DB::table('ses_companies')->where('id', $u->ses_id)->value('name') : null;
        return view('account.profile', ['u' => $u, 'sesName' => $sesName, 'engineerId' => $u->role === 'eg' ? $u->engineer_id : null]);
    }

    // 氏名・企業名・SES名の前後の空白（全角の空白も）を取る。空になれば null（Laravel の標準の取り除きは全角の空白を取らないため）
    private function trimNames(Request $r): void
    {
        $this->legacyNames($r);
        $r->merge(collect(['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'company_name', 'ses_name'])->mapWithKeys(fn ($k) => [$k => clean_name($r->input($k))])->all());
    }

    // 前の送り方（name・kana の1つの欄）で来たら、姓・名に分けてから検証する。姓・名の欄があるときは、そちらを使う
    private function legacyNames(Request $r): void
    {
        foreach (['name' => ['name_sei', 'name_mei'], 'kana' => ['kana_sei', 'kana_mei']] as $full => [$sei, $mei]) {
            if ($r->has($sei) || $r->has($mei) || !$r->has($full)) continue;
            [$s, $m] = split_name(is_string($r->input($full)) ? $r->input($full) : null);
            $r->merge([$sei => $s, $mei => $m]);
        }
    }

    // 最初の情報登録の入力欄の決まり（氏名・企業名・SES名は、アカウントの画面では空でもよい＝今のままにする）
    private function profileRules(bool $required, string $role): array
    {
        $rules = [
            'name_sei' => ($required ? 'required' : 'nullable|required_with:name_mei').'|max:50',
            'name_mei' => ($required ? 'required' : 'nullable').'|max:50',
            'kana_sei' => 'nullable|max:50', 'kana_mei' => 'nullable|max:50',
            'phone' => ['nullable', 'max:30', 'regex:/^[0-9+\-() ]*$/'], 'position' => 'nullable|max:100'];
        if ($role === 'admin') $rules['company_name'] = ($required ? 'required' : 'nullable').'|max:100';
        if ($role === 'ses') $rules['ses_name'] = ($required ? 'required' : 'nullable').'|max:100';
        return $rules;
    }

    private function profileNames(): array
    {
        return ['name_sei' => '氏名（姓）', 'name_mei' => '氏名（名）', 'kana_sei' => 'ふりがな（姓）', 'kana_mei' => 'ふりがな（名）', 'phone' => '電話', 'position' => '所属・役職', 'company_name' => '企業名', 'ses_name' => 'SES名'];
    }

    // 最初のログインで出す「あなたの情報」。済んでいる人は、アカウントの画面へ
    public function welcomeForm(Request $r)
    {
        $u = $r->user();
        if ($u->profile_completed_at) return redirect('/account');
        $v = (object) ['name_sei' => $u->name_sei, 'name_mei' => $u->name_mei, 'kana_sei' => $u->kana_sei, 'kana_mei' => $u->kana_mei,
            'phone' => $u->phone, 'position' => $u->position,
            'company_name' => $u->role === 'admin' ? $u->company_name : null,
            'ses_name' => $u->role === 'ses' ? DB::table('ses_companies')->where('id', $u->ses_id)->value('name') : null];
        // 姓・名の列がまだ空の人は、つないだ name・kana から分ける
        if (!$v->name_sei && !$v->name_mei) [$v->name_sei, $v->name_mei] = split_name($u->name);
        if (!$v->kana_sei && !$v->kana_mei) [$v->kana_sei, $v->kana_mei] = split_name($u->kana);
        if ($u->role === 'eg' && $u->engineer_id) {
            $e = DB::table('engineers')->find($u->engineer_id);
            if (!$v->name_sei && $e) {
                $v->name_sei = $e->name_sei; $v->name_mei = $e->name_mei;
                $v->kana_sei = $e->kana_sei; $v->kana_mei = $e->kana_mei;
            }
        }
        return view('account.welcome', ['u' => $u, 'v' => $v]);
    }

    public function welcome(Request $r)
    {
        $this->trimNames($r);
        $u = $r->user();
        $r->validate($this->profileRules(true, $u->role), [], $this->profileNames());
        $warn = W::saveProfile($u, $r->all());
        [$to, $msg] = W::profileHome($u);
        return $warn ? $this->go($to, '登録しました。'.$warn, 'warn') : $this->go($to, $msg ?? '登録しました。');
    }

    public function password(Request $r)
    {
        if (!Hash::check((string) $r->input('current'), $r->user()->password)) return $this->go('/account', '今のパスワードが違います。', 'bad');
        $r->validate(['password' => 'required|min:8|confirmed'], [], ['password' => '新しいパスワード']);
        DB::table('users')->where('id', $r->user()->id)->update(['password' => Hash::make($r->input('password'))]);
        return $this->go('/account', 'パスワードを変えました。');
    }
}
