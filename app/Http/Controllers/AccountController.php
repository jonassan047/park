<?php

namespace App\Http\Controllers;

use App\Services\Notifier as N;
use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\Requirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

// どの役割でも使う画面：通知・アカウント
class AccountController extends Controller
{
    // 自分あてのお知らせ。兼任の人は、いまの立場の分（と、立場を決めていない分）だけ
    private function myNotifications(Request $r)
    {
        $q = DB::table('app_notifications')->where('user_id', $r->user()->id);
        if (is_dual()) $q->where(fn ($w) => $w->whereNull('for_role')->orWhere('for_role', $r->user()->role));
        return $q;
    }

    public function notifications(Request $r)
    {
        $list = $this->myNotifications($r)->orderByDesc('id')->limit(200)->get();
        return view('account.notifications', compact('list'));
    }

    public function openNotification(Request $r, int $id)
    {
        $n = DB::table('app_notifications')->where('id', $id)->where('user_id', $r->user()->id)->first();
        abort_unless($n, 404);
        // ほかの立場あてのお知らせは、開かない（既読にもしない）
        if (is_dual() && $n->for_role && $n->for_role !== $r->user()->role) {
            $name = ['admin' => '管理者', 'ses' => 'SES 担当者', 'eg' => 'エンジニア'][$n->for_role] ?? $n->for_role;
            return $this->go('/notifications', "このお知らせは「{$name}」あてです。ログアウトして、{$name}として入り直すと開けます。", 'warn');
        }
        if (!$n->read_at) DB::table('app_notifications')->where('id', $id)->update(['read_at' => now_str()]);
        return redirect($n->link ?: '/notifications');
    }

    public function readAll(Request $r)
    {
        $this->myNotifications($r)->whereNull('read_at')->update(['read_at' => now_str()]);
        return redirect('/notifications');
    }

    // ---------- ログインのときに、立場を選ぶ（兼任の人だけ）。入ったあとは変えられない ----------
    public function roleForm(Request $r)
    {
        if (!is_dual() || $r->session()->get('acting_role')) return redirect($r->user()->home());
        $row = DB::table('users')->find($r->user()->id);
        return view('public.login-role', ['roles' => W::usableRoles($row), 'sesName' => $row->ses_id ? DB::table('ses_companies')->where('id', $row->ses_id)->value('name') : null]);
    }

    public function chooseRole(Request $r)
    {
        $u = $r->user();
        if (!is_dual() || $r->session()->get('acting_role')) return redirect($u->home());
        $row = DB::table('users')->find($u->id);
        $to = (string) $r->input('to');
        if (!in_array($to, W::usableRoles($row), true)) return $this->go('/login/role', 'この立場では、いまは入れません。', 'bad');
        $r->session()->put('acting_role', $to);
        N::audit($u->id, 'login-role', $to);
        $u->role = $to;
        $u->syncOriginalAttribute('role');
        // intended は使わない（別の立場の画面に送られて 403 になるのを防ぐ）。足りない項目・同意は、入った先で role ミドルウェアが回す
        [$home, $msg] = W::profileHome($u);
        return $this->go($home, $msg);
    }

    // エンジニア本人の画面に出す値：アカウントの欄が空なら、欄ごとに技術登録の値で補う（空欄のまま保存して、技術登録の値を消してしまわないため。B1・AC6）。
    // 兼任の人（DB の role が ses・admin）は補わない
    private function withEngineerValues(Request $r): object
    {
        $u = $r->user();
        $a = $u->getAttributes();
        $row = DB::table('users')->find($u->id);
        if ($row && $row->role === 'eg' && $row->engineer_id && ($e = DB::table('engineers')->find($row->engineer_id))) {
            foreach (['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'phone'] as $k) {
                if (($a[$k] ?? null) === null || $a[$k] === '') $a[$k] = $e->$k;
            }
        }
        return (object) $a;
    }

    public function show(Request $r)
    {
        $u = $r->user();
        return view('account.show', ['u' => $this->withEngineerValues($r), 'sesName' => $u->role === 'ses' ? DB::table('ses_companies')->where('id', $u->ses_id)->value('name') : null]);
    }

    public function update(Request $r)
    {
        $this->trimNames($r);
        $u = $r->user();
        $r->validate($this->profileRules(false, $u->role), $this->profileMessages(), $this->profileNames());
        // 必須の欄は、本人も空にできない（企業名・SES名は、アカウントの画面からは変えられない）。
        // 氏名は、姓・名のどちらかが送られたら、両方が入っている必要がある（どちらも空なら、今のまま）
        $in = $r->only(['kana_sei', 'kana_mei', 'phone', 'company_name', 'postal_code', 'address']);
        if ($r->input('name_sei') !== null || $r->input('name_mei') !== null) $in += ['name_sei' => $r->input('name_sei'), 'name_mei' => $r->input('name_mei')];
        if ($bad = Requirements::checkNotEmpty($u->role, Requirements::isRep($u), $in, ['users', 'engineer'])) {
            return back()->withInput()->with('flash', ['msg' => $bad, 'type' => 'bad']);
        }
        $warn = W::saveProfile($u, $r->all());
        DB::table('users')->where('id', $u->id)->update(['mail_opt_in' => $r->boolean('mail_opt_in')]);
        return $warn ? $this->go('/account', '保存しました。'.$warn, 'warn') : $this->go('/account', '保存しました。');
    }

    // ---------- 足りない項目の画面（必須項目や同意が足りない人だけが、ここから先へ進める） ----------
    public function completeForm(Request $r)
    {
        $u = $r->user();
        $row = DB::table('users')->find($u->id);
        $miss = Requirements::missing($row, $u->role);
        if (!$miss) return redirect($u->home());
        $isRep = Requirements::isRep($u);
        return view('account.complete', ['role' => $u->role, 'isRep' => $isRep, 'only' => $miss, 'v' => Requirements::values($row, $u->role, $isRep)]);
    }

    public function complete(Request $r)
    {
        $u = $r->user();
        $row = DB::table('users')->find($u->id);
        $miss = Requirements::missing($row, $u->role);
        if (!$miss) return redirect($u->home());
        $isRep = Requirements::isRep($u);
        // 足りなかった項目だけを受け取る（ほかの項目・企業名は、送られても無視する）。古い送り方（氏名・ふりがなの1欄）は、姓・名に分ける
        $this->legacyNames($r);
        $data = Registration::cleanInput($r->all(), $u->role, $isRep, $miss);
        $r->merge($data);
        [$rules, $msg, $attr] = Registration::rules($u->role, $isRep, $miss);
        $r->validate($rules, $msg, $attr);
        Registration::saveMissing($row, $u->role, $data, $miss);
        [$home, $note] = W::profileHome($u);
        return $this->go($home, $note ?? '登録しました。');
    }

    // 自分の登録情報を見るだけの画面（入力欄なし）
    public function profile(Request $r)
    {
        $u = $r->user();
        $sesName = $u->role === 'ses' ? DB::table('ses_companies')->where('id', $u->ses_id)->value('name') : null;
        return view('account.profile', ['u' => $this->withEngineerValues($r), 'sesName' => $sesName, 'engineerId' => $u->role === 'eg' ? $u->engineer_id : null]);
    }

    // 氏名・企業名・SES名の前後の空白（全角の空白も）を取る。空になれば null（Laravel の標準の取り除きは全角の空白を取らないため）
    private function trimNames(Request $r): void
    {
        $this->legacyNames($r);
        // 送られてきた欄だけ整える（送られなかった欄は、送られなかったまま。空にしたのか、送らなかったのかを見分けるため）
        $r->merge(collect(['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'company_name', 'ses_name'])->filter(fn ($k) => $r->has($k))->mapWithKeys(fn ($k) => [$k => clean_name($r->input($k))])->all());
        // 管理者の郵便番号・住所：郵便番号は「123-4567」にそろえてから検証する（そろえられなければ入れた文字のまま検証に回し、はじく）
        if ($r->user()->role === 'admin') {
            if ($r->has('postal_code')) {
                $in = $r->input('postal_code');
                $norm = is_string($in) ? postal_norm($in) : null;
                $r->merge(['postal_code' => $norm === false ? $in : $norm]);
            }
            if ($r->has('address')) $r->merge(['address' => clean_name($r->input('address'))]);
        }
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
        if ($role === 'admin') {
            $rules['company_name'] = ($required ? 'required' : 'nullable').'|max:100';
            $rules['postal_code'] = ['nullable', 'regex:/^\d{3}-\d{4}$/'];
            $rules['address'] = 'nullable|max:200';
        }
        if ($role === 'ses') $rules['ses_name'] = ($required ? 'required' : 'nullable').'|max:100';
        return $rules;
    }

    private function profileMessages(): array
    {
        return ['postal_code.regex' => '郵便番号は7桁の数字で入れてください（例：123-4567）。', 'address.max' => '住所は200文字までにしてください。'];
    }

    private function profileNames(): array
    {
        return ['postal_code' => '郵便番号', 'address' => '住所', 'name_sei' => '氏名（姓）', 'name_mei' => '氏名（名）', 'kana_sei' => 'ふりがな（姓）', 'kana_mei' => 'ふりがな（名）', 'phone' => '電話', 'position' => '所属・役職', 'company_name' => '企業名', 'ses_name' => 'SES名'];
    }

    // 古い入口（/welcome・/eg/consent）：足りない項目の画面へ転送する（足りなければ /register/complete、なければホーム）
    public function welcomeForm(Request $r)
    {
        return redirect('/register/complete');
    }

    public function welcome(Request $r)
    {
        return redirect('/register/complete');
    }

    public function password(Request $r)
    {
        if (!Hash::check((string) $r->input('current'), $r->user()->password)) return $this->go('/account', '今のパスワードが違います。', 'bad');
        $r->validate(['password' => 'required|min:8|confirmed'], [], ['password' => '新しいパスワード']);
        DB::table('users')->where('id', $r->user()->id)->update(['password' => Hash::make($r->input('password'))]);
        return $this->go('/account', 'パスワードを変えました。');
    }
}
