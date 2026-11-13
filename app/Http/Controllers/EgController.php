<?php

namespace App\Http\Controllers;

use App\Services\Notifier as N;
use App\Services\Qa;
use App\Services\Workflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// エンジニアの画面：ホーム・届いた案件・応募と面談。プロフィール・経験票・スキルシート・やり取りは共通の画面へ
class EgController extends Controller
{
    public function home(Request $r)
    {
        $eid = $r->user()->engineer_id;
        $contract = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->where('k.engineer_id', $eid)->where('k.status', 'active')->select('k.*', 'c.title')->first();
        return view('eg.home', [
            'e' => DB::table('engineers')->find($eid), 'check' => Qa::sheetCheck($eid), 'contract' => $contract,
            'offers' => DB::table('case_offers as o')->join('cases as c', 'c.id', '=', 'o.case_id')->where('o.engineer_id', $eid)->whereIn('o.status', ['sent', 'viewed'])
                ->where('c.status', 'open')->select('o.*', 'c.title', 'c.price', 'c.start_date', 'c.code as case_code')->orderByDesc('o.sent_at')->get(),
            'apps' => DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->where('a.engineer_id', $eid)->whereNotIn('a.status', ['rejected', 'withdrawn'])
                ->select('a.*', 'c.title', 'c.code as case_code')->orderByDesc('a.updated_at')->get(),
            'ivs' => DB::table('interviews as iv')->join('applications as a', 'a.id', '=', 'iv.application_id')->join('cases as c', 'c.id', '=', 'a.case_id')
                ->where('a.engineer_id', $eid)->where('iv.result', 'pending')->select('iv.*', 'c.title')->orderBy('iv.scheduled_at')->get(),
            'needFollow' => $contract && !DB::table('follow_ups')->where('contract_id', $contract->id)->exists(),
            'historyTodo' => W::historyTodo($eid),
            'projectCount' => DB::table('engineer_projects')->where('engineer_id', $eid)->count(),
            'unread' => DB::table('messages')->where('engineer_id', $eid)->where('read_by_eg', false)->count(),
            // 「担当者への連絡」のカードの文の出し分け（管理者の直属なら、相手は管理者）
            'sesDirect' => W::isDirect((int) DB::table('engineers')->where('id', $eid)->value('ses_id')),
        ]);
    }

    // 古い入口：同意は「足りない項目の画面」で取る（個人情報の取り扱いへの同意と、登録の内容の確認を兼ねる）
    public function consentForm(Request $r)
    {
        return redirect('/register/complete');
    }

    public function consent(Request $r)
    {
        return redirect('/register/complete');
    }

    public function offers(Request $r)
    {
        $list = DB::table('case_offers as o')->join('cases as c', 'c.id', '=', 'o.case_id')->where('o.engineer_id', $r->user()->engineer_id)
            ->select('o.*', 'c.title', 'c.price', 'c.start_date', 'c.status as case_status', 'c.code as case_code')->orderByDesc('o.sent_at')->get();
        return view('eg.offers', compact('list'));
    }

    public function applications(Request $r)
    {
        $list = DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->where('a.engineer_id', $r->user()->engineer_id)->select('a.*', 'c.title', 'c.code as case_code')
            ->selectSub(DB::table('interviews')->whereColumn('application_id', 'a.id')->where('result', 'pending')->selectRaw('min(scheduled_at)'), 'next_iv')
            ->orderByDesc('a.updated_at')->get();
        return view('eg.applications', compact('list'));
    }
}
