<?php

namespace App\Http\Controllers;

use App\Services\CaseThread as CT;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 案件のやり取り（v0.10.0）：エンジニアと、所属の会社の担当者（直属なら管理者）が、案件について確かめ合う。
// 見える範囲・書ける条件は CaseThread に集めている。ここでは「見られない人には 404」だけを確かめる。
class CaseThreadController extends Controller
{
    // 一覧（管理者・SES・担当者）：SES・担当者は自社の1本だけ、管理者はすべて（会社で絞れる）
    public function index(Request $r)
    {
        $u = $r->user();
        $q = CT::listQuery();
        $sesFilter = 0;
        $sesOptions = [];
        if ($u->role === 'ses') {
            $q->where('t.ses_id', $u->ses_id);
        } else {
            $sesOptions = DB::table('ses_companies')->whereIn('id', DB::table('case_threads')->select('ses_id'))->orderBy('name')->pluck('name', 'id')->all();
            $sesFilter = (int) $r->query('ses', 0);
            if ($sesFilter && isset($sesOptions[$sesFilter])) $q->where('t.ses_id', $sesFilter); else $sesFilter = 0;
        }
        // 未読があるものを上に、次に新しい順。管理者は、直属の未読だけで並べる（会社の1本は、管理者が読んでも未読が消えないため）
        $list = $q->orderByDesc('t.last_post_at')->orderByDesc('t.id')->get()
            ->sortByDesc(fn ($t) => ($u->role === 'admin' && $t->ses_kind !== 'direct') ? 0 : (int) $t->unread)->values();
        [$list, $pager] = page_slice($list);
        return view('threads.index', compact('list', 'pager', 'sesOptions', 'sesFilter'));
    }

    // 案件・エンジニアを確かめる。見られない人には 404（あるかどうかも漏らさない）
    private function target(Request $r, int $id, int $eid): array
    {
        $u = $r->user();
        $c = DB::table('cases')->find($id);
        abort_unless($c, 404);
        abort_unless(Perms::caseView($u, $c), 404);
        $e = DB::table('engineers')->find($eid);
        abort_unless($e, 404);
        if ($u->role === 'eg') abort_unless((int) $e->id === (int) $u->engineer_id, 404);
        // 所属が変わったあとも、前の会社は、前のやり取りを読める
        if ($u->role === 'ses') abort_unless((int) $e->ses_id === (int) $u->ses_id || DB::table('case_threads')->where('case_id', $id)->where('engineer_id', $eid)->where('ses_id', $u->ses_id)->exists(), 404);
        return [$u, $c, $e];
    }

    public function show(Request $r, int $id, int $eid)
    {
        [$u, $c, $e] = $this->target($r, $id, $eid);
        if ($u->role === 'eg') return redirect("/cases/$id#thread"); // エンジニアは、案件の画面で見る
        $threads = CT::withPosts(CT::threadsFor($u, $id, $e), $e);
        foreach ($threads as $t) CT::markRead($u, $t);
        $why = CT::canPost($u, $c, $e);
        return view('threads.show', compact('c', 'e', 'threads', 'why'));
    }

    public function store(Request $r, int $id, int $eid)
    {
        [$u, $c, $e] = $this->target($r, $id, $eid);
        $body = $r->input('body');
        $res = CT::post($u, $c, $e, is_string($body) ? $body : '');
        $to = $u->role === 'eg' ? "/cases/$id#thread" : "/cases/$id/threads/$eid";
        if ($res['type'] === 'bad') return redirect($to)->withInput()->with('flash', ['msg' => $res['msg'], 'type' => 'bad']);
        return $this->go($to, $res['msg']);
    }
}
