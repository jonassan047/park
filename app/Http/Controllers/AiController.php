<?php

namespace App\Http\Controllers;

use App\Services\AiJobs;
use App\Services\CaseAi;
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
        $in = json_decode($j->input, true) ?: [];
        $job = ['id' => $j->id, 'kind' => $j->kind, 'prompt' => $in['prompt'] ?? ''];
        // system・user・options があれば足す（無い仕事と古い worker は、今までどおり prompt だけで動く）
        foreach (['system', 'user', 'options'] as $k) if (isset($in[$k])) $job[$k] = $in[$k];
        return response()->json(['job' => $job]);
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
        if ($j->ref_type === 'case') {
            $c = DB::table('cases')->find($j->ref_id);
            abort_unless($c && in_array($r->user()->role, ['admin', 'ses']) && Perms::caseEdit($r->user(), $c), 403);
        } elseif ($j->ref_type === 'interview') {
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
        // 仮登録中のエンジニアには、上位が AI を使えない（K18）
        abort_if(DB::table('users')->where('engineer_id', $id)->where('role', 'eg')->whereNull('registered_at')->exists(), 403);
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
        $qa = AiJobs::items($j);
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
        // 仮登録中のエンジニアには、上位が取り込めない（K18。queueResume と同じ守り）
        abort_if($r->user()->role !== 'eg' && DB::table('users')->where('engineer_id', $eid)->where('role', 'eg')->whereNull('registered_at')->exists(), 403);
        $p = (AiJobs::items($j) ?? [])[$n] ?? null;
        abort_unless(is_array($p) && !empty($p['title']), 404);
        $p = array_map(fn ($v) => is_array($v) ? $v : (string) $v, $p);
        if (isset($p['team_size'])) $p['team_size'] = preg_replace('/\D/', '', mb_convert_kana($p['team_size'], 'n')); // 「8名」→ 8
        W::saveProject($eid, $p);
        W::editedBy($eid, $r->user());
        $added = json_decode((string) ($r->session()->get("ai_added_$id") ?? '[]'), true);
        $r->session()->put("ai_added_$id", json_encode(array_values(array_unique(array_merge($added, [$n])))));
        N::audit($r->user()->id, 'ai-project', "#$id {$p['title']}");
        return $this->go("/engineers/$eid#docs", '「'.$p['title'].'」を経験票に足しました。経験票の画面で中身を確かめてください。');
    }

    // 案件の必須・尚可：AI の結果を使う（本文が頼んだときと同じときだけ）
    public function applyCase(Request $r, int $id)
    {
        $j = $this->job($r, $id);
        abort_unless($j->status === 'done' && $j->kind === 'case_skills', 400);
        $c = DB::table('cases')->find($j->ref_id);
        $cmp = CaseAi::compare($j, $c);
        if ($cmp['stale']) return $this->go("/cases/{$c->id}#ai", '本文が変わったので使えません。もう一度頼んでください。', 'bad');
        // 二重押しの守り：まだ done のときだけ applied にする（0件なら、もう片付いている）
        if (!DB::table('ai_jobs')->where('id', $id)->where('status', 'done')->update(['status' => 'applied'])) abort(400);
        CaseAi::apply($c, $cmp);
        N::audit($r->user()->id, 'ai-case-apply', "#$id {$c->title}");
        return $this->go("/cases/{$c->id}#ai", 'AI の結果を使いました。必須・尚可・業務を置き換えました。');
    }

    public function drop(Request $r, int $id)
    {
        $j = $this->job($r, $id);
        if ($j->kind === 'case_skills') {
            abort_unless($j->status === 'done', 400);
            if (!DB::table('ai_jobs')->where('id', $id)->where('status', 'done')->update(['status' => 'dropped'])) abort(400); // 二重押しの守り
            N::audit($r->user()->id, 'ai-case-keep', "#$id");
            return $this->go("/cases/{$j->ref_id}#ai", '今のままにしました。');
        }
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
