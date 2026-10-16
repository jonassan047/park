<?php

namespace App\Http\Controllers;

use App\Services\AiJobs;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// AI の仕事の箱：頼む・下書きを採用する／捨てる（画面）と、手元の PC の AI とのやり取り（/ai/worker）
class AiController extends Controller
{
    // ---------- 手元の PC の AI とのやり取り（合言葉 PARK_AI_TOKEN で確かめる） ----------
    private function worker(Request $r): void
    {
        $token = (string) config('services.park_ai.token');
        abort_unless($token !== '' && hash_equals($token, (string) $r->header('X-Park-Ai-Token')), 403);
    }

    public function next(Request $r)
    {
        $this->worker($r);
        $j = AiJobs::next();
        if (!$j) return response()->json(['job' => null]);
        return response()->json(['job' => ['id' => $j->id, 'kind' => $j->kind, 'prompt' => json_decode($j->input, true)['prompt'] ?? '']]);
    }

    public function result(Request $r, int $id)
    {
        $this->worker($r);
        AiJobs::finish($id, $r->input('output'), $r->input('error'), mb_substr((string) $r->input('engine'), 0, 60));
        return response()->json(['ok' => true]);
    }

    // ---------- 画面 ----------
    private function job(Request $r, int $id)
    {
        $j = DB::table('ai_jobs')->find($id);
        abort_unless($j, 404);
        if ($j->ref_type === 'interview') {
            $iv = DB::table('interviews')->find($j->ref_id);
            abort_unless($iv && in_array($r->user()->role, ['admin', 'ses']) && Perms::app($r->user(), W::loadApp($iv->application_id)), 403);
        } else {
            $e = DB::table('engineers')->find(json_decode($j->input, true)['engineer_id'] ?? 0);
            abort_unless($e && Perms::engineer($r->user(), $e), 403);
        }
        return $j;
    }

    public function queueScript(Request $r, int $id)
    {
        $iv = DB::table('interviews')->find($id);
        abort_unless($iv && Perms::app($r->user(), W::loadApp($iv->application_id)), 403);
        AiJobs::queueScript($id, $r->user());
        return $this->go("/interviews/$id", 'AI に頼みました。手元の PC の AI が順に作業します（数分かかります。できたら知らせます）。');
    }

    public function queueResume(Request $r, int $id, int $fid)
    {
        $e = DB::table('engineers')->find($id);
        abort_unless($e && Perms::engineer($r->user(), $e), 403);
        try {
            AiJobs::queueResume($id, $fid, $r->user());
            return $this->go("/engineers/$id#docs", 'AI に頼みました。手元の PC の AI が順に作業します（数分かかります。できたら知らせます）。');
        } catch (\RuntimeException $ex) {
            return $this->go("/engineers/$id#docs", $ex->getMessage(), 'bad');
        }
    }

    // 面談スクリプト：下書きをそのまま採用する
    public function apply(Request $r, int $id)
    {
        $j = $this->job($r, $id);
        abort_unless($j->status === 'done' && $j->kind === 'script', 400);
        $qa = AiJobs::json($j->output);
        $qa = array_values(array_filter((array) $qa, fn ($x) => is_array($x) && !empty($x['q'])));
        if (!$qa) return $this->go("/interviews/{$j->ref_id}", 'AI の下書きが読めませんでした。捨ててください。', 'bad');
        $qa = array_map(fn ($x) => ['category' => (string) ($x['category'] ?? ''), 'q' => (string) $x['q'], 'hint' => (string) ($x['hint'] ?? '')], $qa);
        DB::table('interviews')->where('id', $j->ref_id)->update(['qa_json' => json_encode($qa, JSON_UNESCAPED_UNICODE)]);
        DB::table('ai_jobs')->where('id', $id)->update(['status' => 'applied']);
        N::audit($r->user()->id, 'ai-apply', "#$id");
        return $this->go("/interviews/{$j->ref_id}", 'AI の下書きを採用しました（前のスクリプトは置き換わりました。直すときは「スクリプトを直す」で）。');
    }

    // 経歴書：下書きの案件を1つずつ経験票に足す
    public function addProject(Request $r, int $id, int $n)
    {
        $j = $this->job($r, $id);
        abort_unless($j->status === 'done' && $j->kind === 'resume', 400);
        $eid = (int) json_decode($j->input, true)['engineer_id'];
        $out = AiJobs::json($j->output);
        $p = $out['projects'][$n] ?? null;
        abort_unless(is_array($p) && !empty($p['title']), 404);
        W::saveProject($eid, array_map(fn ($v) => is_array($v) ? $v : (string) $v, $p));
        W::editedBy($eid, $r->user());
        $added = json_decode((string) ($r->session()->get("ai_added_$id") ?? '[]'), true);
        $r->session()->put("ai_added_$id", json_encode(array_values(array_unique(array_merge($added, [$n])))));
        N::audit($r->user()->id, 'ai-project', "#$id {$p['title']}");
        return $this->go("/engineers/$eid#docs", '「'.$p['title'].'」を経験票に足しました。経験票の画面で中身を確かめてください。');
    }

    public function drop(Request $r, int $id)
    {
        $j = $this->job($r, $id);
        DB::table('ai_jobs')->where('id', $id)->update(['status' => $j->kind === 'resume' && $j->status === 'done' && $r->input('done') ? 'applied' : 'dropped']);
        $back = $j->ref_type === 'interview' ? "/interviews/{$j->ref_id}" : '/engineers/'.(json_decode($j->input, true)['engineer_id'] ?? '').'#docs';
        return $this->go($back, $r->input('done') ? '下書きを閉じました。' : '下書きを捨てました。');
    }

    public function index()
    {
        $list = DB::table('ai_jobs as j')->leftJoin('users as u', 'u.id', '=', 'j.created_by')->select('j.id', 'j.kind', 'j.ref_type', 'j.ref_id', 'j.status', 'j.engine', 'j.error', 'j.created_at', 'j.picked_at', 'j.done_at', 'j.input', 'u.name')
            ->orderByDesc('j.id')->limit(200)->get();
        $lastPick = DB::table('ai_jobs')->max('picked_at');
        return view('admin.ai', ['list' => $list, 'lastPick' => $lastPick, 'hasToken' => (string) config('services.park_ai.token') !== '']);
    }
}
