<?php

namespace App\Http\Controllers;

use App\Services\DirectMail as DM;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

// 連絡メール：作成（宛先・件名・本文）→ 送る → 一覧・画面。管理者・SES・担当者・エンジニアが使える
// 送れる範囲は DirectMail が決める（管理者は全社のエンジニア、SES は自社のエンジニア、エンジニアは所属の会社の SES・担当者）
class DirectMailController extends Controller
{
    // 宛先を送る欄の名前：管理者・SES はエンジニアの id（eg[]）、エンジニアは相手の users.id（to[]）
    private function field($u): string
    {
        return $u->role === 'eg' ? 'to' : 'eg';
    }

    private function ids(Request $r, $u, bool $query): array
    {
        $v = $query ? $r->query($this->field($u), []) : $r->input($this->field($u), []);
        return is_array($v) ? array_values($v) : [];
    }

    // 作成画面に戻る URL（管理者・SES は選んだ人を付ける。1人も選んでいなければ一覧へ）
    private function backUrl($u, array $ids): string
    {
        if ($u->role === 'eg') return '/mails/new';
        return $ids ? '/mails/new?'.http_build_query(['eg' => array_values(array_filter($ids, 'is_scalar'))]) : ($u->role === 'admin' ? '/admin/engineers' : '/ses/engineers');
    }

    // 一覧：受け取った・送った・（管理者だけ）すべて
    public function index(Request $r)
    {
        $u = $r->user();
        abort_unless(DM::canCompose($u), 403);
        $box = DM::box($u, is_string($r->query('box')) ? $r->query('box') : '');
        $page = max(1, (int) $r->query('page', 1));
        $list = DM::listFor($u, $box, $page);
        return view('mails.index', ['box' => $box, 'rows' => $list['rows'], 'more' => $list['more'], 'page' => $page]);
    }

    // 作成画面
    public function create(Request $r)
    {
        $u = $r->user();
        abort_unless(DM::canCompose($u), 403);
        $ids = $this->ids($r, $u, true);
        $toEg = $u->role !== 'eg'; // 管理者・SES・担当者が送る相手はエンジニア。エンジニアが送る相手は SES・担当者（直属なら管理者）
        if ($toEg && !$ids) return $this->go($this->backUrl($u, []), '送る相手を選んでください。', 'bad');
        $t = DM::targets($u, $ids);
        abort_if($t['outside'], 403); // 範囲の外（他社のエンジニアなど）が混ざっていたら、開かない
        if ($toEg && !$t['ok']) return $this->go($this->backUrl($u, []), '送れる相手がいません。', 'bad');
        if ($toEg) {
            $labels = DM::engineerLabels($u, $t['ok']);
            $checked = array_keys($labels);
        } else {
            $labels = DM::staffLabels($u, DM::staffTargets($u));
            // エンジニアの宛先：はじめはチェックなし（前の画面で選んだ人と、1人だけのときは最初からチェック）
            $checked = count($labels) === 1 ? array_keys($labels) : array_values(array_intersect(array_keys($labels), array_map('intval', $ids)));
        }
        return view('mails.new', [
            'toEg' => $toEg, 'field' => $this->field($u), 'labels' => $labels, 'checked' => $checked,
            'tooMany' => count($ids) > DM::MAX_TO, 'skipped' => count($t['unusable']), 'sendKey' => Str::random(32), 'canSend' => N::canSend(),
            'myEmail' => $u->email, 'isAdmin' => $u->role === 'admin',
        ]);
    }

    // 送る
    public function store(Request $r)
    {
        $u = $r->user();
        abort_unless(DM::canCompose($u), 403);
        $ids = $this->ids($r, $u, false);
        $res = DM::send($u, $ids, is_string($r->input('subject')) ? $r->input('subject') : '', is_string($r->input('body')) ? $r->input('body') : '', is_string($r->input('send_key')) ? $r->input('send_key') : '');
        if (!empty($res['forbidden'])) abort(403);
        if (isset($res['dup'])) return $this->go('/mails/'.$res['dup'], 'この連絡メールは、もう送っています。');
        if (isset($res['bad'])) return redirect($this->backUrl($u, $ids))->withInput($r->only('subject', 'body'))->with('flash', ['msg' => $res['bad'], 'type' => 'bad']);
        $msg = "{$res['sent']}名に連絡メールを送りました。".($res['skipped'] ? "送れない {$res['skipped']} 名を外しました。" : '');
        return $this->go('/mails/'.$res['ok'], $msg);
    }

    // 連絡メールの画面（送った人・受け取った人・管理者だけ）
    public function show(Request $r, int $id)
    {
        $u = $r->user();
        abort_unless(DM::canCompose($u), 403);
        $m = DB::table('direct_mails')->find($id);
        abort_unless($m && DM::canView($u, $m), 404);
        $isSender = $m->from_user_id !== null && (int) $m->from_user_id === (int) $u->id;
        $seeAll = $isSender || $u->role === 'admin'; // 宛先の一覧は、送った人と管理者だけ
        $mine = DB::table('direct_mail_recipients')->where('direct_mail_id', $m->id)->where('to_user_id', $u->id)->first();
        // エンジニアから届いたものには、そのエンジニアの詳細へのリンクを付ける（管理者・SES・担当者だけ）
        $fromEng = $m->from_role === 'eg' && in_array($u->role, ['admin', 'ses'], true) && $m->from_user_id ? DB::table('users')->where('id', $m->from_user_id)->value('engineer_id') : null;
        return view('mails.show', ['m' => $m, 'isSender' => $isSender, 'recipients' => $seeAll ? DM::recipients($m->id) : collect(), 'mine' => $mine, 'fromEng' => $fromEng]);
    }
}
