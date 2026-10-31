<?php

namespace App\Http\Controllers;

use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\Requirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 仮登録の人の「送り直し」と「取り消し」。権限は Registration::canManage に1か所
class PeopleController extends Controller
{
    // 対象の人（users の行）を返す。見つからない・他社の人は 404、権限がなければ 403
    private function target(Request $r, int $id): object
    {
        $t = DB::table('users')->find($id);
        abort_unless($t, 404);
        $me = $r->user();
        abort_if($me->role === 'ses' && (int) $t->ses_id !== (int) $me->ses_id, 404); // 他社の人は見つからない扱い
        abort_unless(Registration::canManage($me, $t), 403);
        return $t;
    }

    // 操作のあとの戻り先（役割と対象から決め、送られた値から作らない）
    private function back(Request $r, object $t, bool $sesDeleted = false): string
    {
        if ($r->user()->role === 'admin') {
            if ($t->role === 'admin') return '/admin/admins';
            if ($t->role === 'ses') return $sesDeleted ? '/admin/ses' : "/admin/ses/{$t->ses_id}";
            // 直属の入れ物には SES の詳細が無い（404 になる）ので、エンジニアの一覧へ
            return $t->ses_id && !W::isDirect((int) $t->ses_id) ? "/admin/ses/{$t->ses_id}" : '/admin/engineers';
        }
        return $t->role === 'eg' ? '/ses/engineers' : '/ses/company';
    }

    // 仮登録中の担当者・エンジニアの直し（姓・名・メール。管理者は所属も）
    public function edit(Request $r, int $id)
    {
        $t = $this->target($r, $id);
        abort_unless(in_array($t->role, ['ses', 'eg'], true) && !$t->registered_at, 404);
        if (Requirements::isRep($t)) return redirect("/admin/ses/{$t->ses_id}/edit"); // 代表者の仮登録は、SES の編集の画面で直す（管理者だけがここに来る）
        [$sei, $mei] = ($t->name_sei || $t->name_mei) ? [$t->name_sei, $t->name_mei] : split_name($t->name);
        return view('people.edit', [
            't' => $t, 'sei' => $sei, 'mei' => $mei, 'company' => Registration::companyOf($t), 'back' => $this->back($r, $t),
            // 所属を選べるのは管理者だけ（参加中の SES から）
            'sesOptions' => $r->user()->role === 'admin' ? DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : null,
        ]);
    }

    public function update(Request $r, int $id)
    {
        $t = $this->target($r, $id);
        [, $sei, $mei] = name_parts($r->all(), 'name', 'name');
        $res = Registration::editProvisionalPerson($t, ['sei' => $sei, 'mei' => $mei, 'email' => $r->input('email'), 'ses_id' => $r->input('ses_id')], $r->user());
        if (isset($res['bad'])) return back()->withInput()->with('flash', ['msg' => $res['bad'], 'type' => 'bad']);
        // 所属を変えたかもしれないので、戻り先は直したあとの行から決める
        return $this->go($this->back($r, DB::table('users')->find($id)), $res['ok']);
    }

    public function resend(Request $r, int $id)
    {
        $t = $this->target($r, $id);
        $res = Registration::resend($t, $r->user());
        return $this->go($this->back($r, $t), $res['msg'], $res['type']);
    }

    public function cancel(Request $r, int $id)
    {
        $t = $this->target($r, $id);
        $res = Registration::cancel($t, $r->user());
        return $this->go($this->back($r, $t, $res['sesDeleted']), $res['msg'], $res['type']);
    }
}
