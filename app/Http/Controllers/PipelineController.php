<?php

namespace App\Http\Controllers;

use App\Services\Notifier as N;
use App\Services\Qa;
use App\Services\Workflow as W;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 選考の流れ：応募 → 書類 → 面談（Q&A） → 成約（契約） → 延長・フォロー、と請求書
class PipelineController extends Controller
{
    const OPEN = ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed'];

    private function app(Request $r, int $id)
    {
        $a = W::loadApp($id);
        abort_unless($a, 404);
        abort_unless(Perms::app($r->user(), $a), 403);
        return $a;
    }

    public function show(Request $r, int $id)
    {
        $a = $this->app($r, $id);
        return view('pipeline.application', [
            'a' => $a, 'open' => in_array($a->status, self::OPEN),
            'events' => DB::table('application_events as ev')->leftJoin('users as u', 'u.id', '=', 'ev.user_id')->where('application_id', $id)->select('ev.*', 'u.name')->orderBy('ev.id')->get(),
            'ivs' => DB::table('interviews')->where('application_id', $id)->orderBy('round')->get(),
            'contract' => DB::table('contracts')->where('application_id', $id)->first(),
            'sheetUrl' => url("/engineers/{$a->engineer_id}/sheet?case={$a->case_id}&anon=1"),
        ]);
    }

    public function status(Request $r, int $id)
    {
        $this->app($r, $id);
        $st = (string) $r->input('status');
        if (!in_array($st, ['submitted', 'doc_passed', 'rejected'])) return redirect("/applications/$id");
        W::setStatus($id, $st, $r->user(), (string) $r->input('reason'), (string) $r->input('note'));
        return $this->go("/applications/$id", '「'.lbl('appStatus', $st).'」にしました。');
    }

    public function withdraw(Request $r, int $id)
    {
        $a = $this->app($r, $id);
        if (in_array($a->status, self::OPEN)) W::setStatus($id, 'withdrawn', $r->user(), '', (string) $r->input('note'));
        return $this->go("/applications/$id", '取り下げました。');
    }

    public function note(Request $r, int $id)
    {
        $this->app($r, $id);
        if (str_in($r->input('note'))) W::addEvent($id, 'note', str_in($r->input('note')), $r->user());
        return $this->go("/applications/$id", '残しました。');
    }

    public function interview(Request $r, int $id)
    {
        $this->app($r, $id);
        $ivId = W::scheduleInterview($id, $r->all(), $r->user());
        return $this->go("/interviews/$ivId", '面談を設定し、Q&A を作りました。中身を確かめて、必要なら直してください。');
    }

    public function contract(Request $r, int $id)
    {
        $this->app($r, $id);
        try {
            $kid = W::makeContract($id, $r->all(), $r->user());
            return $this->go("/contracts/$kid", '成約を記録しました。おめでとうございます。');
        } catch (\RuntimeException $e) {
            return $this->go("/applications/$id", $e->getMessage(), 'bad');
        }
    }

    // ---------- 面談（Q&A） ----------
    private function iv(Request $r, int $id)
    {
        $iv = DB::table('interviews')->find($id);
        abort_unless($iv, 404);
        return [$iv, $this->app($r, $iv->application_id)];
    }

    public function interviewShow(Request $r, int $id)
    {
        [$iv, $a] = $this->iv($r, $id);
        return view('pipeline.interview', ['iv' => $iv, 'a' => $a, 'qa' => json_decode($iv->qa_json ?: '[]', true)]);
    }

    public function qa(Request $r, int $id)
    {
        [$iv] = $this->iv($r, $id);
        $cats = (array) $r->input('category', []); $qs = (array) $r->input('q', []); $hints = (array) $r->input('hint', []);
        $qa = [];
        foreach ($qs as $i => $q) if (trim((string) $q) !== '') $qa[] = ['category' => (string) ($cats[$i] ?? ''), 'q' => trim($q), 'hint' => trim((string) ($hints[$i] ?? ''))];
        DB::table('interviews')->where('id', $id)->update(['qa_json' => json_encode($qa, JSON_UNESCAPED_UNICODE)]);
        return $this->go("/interviews/$id", 'Q&A を保存しました。');
    }

    public function regen(Request $r, int $id)
    {
        [$iv, $a] = $this->iv($r, $id);
        DB::table('interviews')->where('id', $id)->update(['qa_json' => json_encode(Qa::generate($a->case_id, $a->engineer_id), JSON_UNESCAPED_UNICODE)]);
        return $this->go("/interviews/$id", '作り直しました。');
    }

    public function memo(Request $r, int $id)
    {
        $this->iv($r, $id);
        DB::table('interviews')->where('id', $id)->update(['eg_memo' => str_in($r->input('eg_memo'))]);
        return $this->go("/interviews/$id", '保存しました。');
    }

    public function result(Request $r, int $id)
    {
        [$iv] = $this->iv($r, $id);
        W::interviewResult($id, $r->input('result') === 'fail' ? 'fail' : 'pass', str_in($r->input('feedback')), $r->user());
        return $this->go("/applications/{$iv->application_id}", '結果を記録しました。');
    }

    // ---------- 契約（延長・終了・成約後のフォロー） ----------
    private function contractRow(Request $r, int $id)
    {
        $k = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->join('engineers as e', 'e.id', '=', 'k.engineer_id')
            ->join('ses_companies as s', 's.id', '=', 'k.ses_id')->where('k.id', $id)->select('k.*', 'c.title', 'e.name as eg_name', 's.name as ses_name')->first();
        abort_unless($k, 404);
        abort_unless(Perms::app($r->user(), $k), 403);
        return $k;
    }

    public function contractShow(Request $r, int $id)
    {
        $k = $this->contractRow($r, $id);
        $fus = DB::table('follow_ups as f')->leftJoin('users as u', 'u.id', '=', 'f.by_user')->where('contract_id', $id)->select('f.*', 'u.name')->orderByDesc('f.id')->get();
        return view('pipeline.contract', compact('k', 'fus'));
    }

    public function extend(Request $r, int $id)
    {
        $this->contractRow($r, $id);
        W::extendContract($id, (int) $r->input('months', 3), $r->user());
        return $this->go("/contracts/$id", '延長を記録しました。');
    }

    public function end(Request $r, int $id)
    {
        $this->contractRow($r, $id);
        W::endContract($id, $r->all(), $r->user());
        return $this->go("/contracts/$id", '終了にしました。');
    }

    public function followup(Request $r, int $id)
    {
        $k = $this->contractRow($r, $id);
        $mood = int_or_null($r->input('mood'));
        DB::table('follow_ups')->insert(['contract_id' => $id, 'at' => now_str(), 'mood' => $mood, 'troubles' => str_in($r->input('troubles')), 'note' => str_in($r->input('note')), 'by_user' => $r->user()->id]);
        if (($mood !== null && $mood <= 2) || str_in($r->input('troubles'))) {
            N::toAdmins('フォロー：困りごとがあります', "{$k->eg_name}（{$k->title}）：".(str_in($r->input('troubles')) ?? '調子が良くない'), "/contracts/$id", true, 'followup');
        }
        return $this->go("/contracts/$id", '記録しました。');
    }

    // ---------- 請求書（印刷用） ----------
    public function invoice(Request $r, int $id)
    {
        $inv = DB::table('invoices as i')->join('ses_companies as s', 's.id', '=', 'i.ses_id')->where('i.id', $id)->select('i.*', 's.name as ses_name')->first();
        abort_unless($inv, 404);
        if ($r->user()->role === 'ses') abort_unless((int) $inv->ses_id === (int) $r->user()->ses_id && $inv->status !== 'draft', 403);
        return view('pipeline.invoice', ['inv' => $inv, 'items' => json_decode($inv->items_json, true)]);
    }
}
