<?php

namespace App\Http\Controllers;

use App\Services\Billing;
use App\Services\Matcher;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// SES の画面：ダッシュボード・自社のエンジニア・案件・応募状況・契約・請求・会社情報
class SesController extends Controller
{
    public function dashboard(Request $r)
    {
        $sid = $r->user()->ses_id;
        $engs = DB::table('engineers')->where('ses_id', $sid)->get();
        $all = DB::table('applications')->where('ses_id', $sid)->pluck('status');
        $withProj = DB::table('engineer_projects')->distinct()->pluck('engineer_id')->all();
        return view('ses.dashboard', [
            's' => DB::table('ses_companies')->find($sid), 'engs' => $engs,
            'apps' => DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
                ->where('a.ses_id', $sid)->whereNotIn('a.status', ['won', 'rejected', 'withdrawn'])->select('a.*', 'c.title', 'e.name as eg_name')->orderByDesc('a.updated_at')->get(),
            'offers' => DB::table('case_offers as o')->join('cases as c', 'c.id', '=', 'o.case_id')->join('engineers as e', 'e.id', '=', 'o.engineer_id')
                ->where('e.ses_id', $sid)->whereIn('o.status', ['sent', 'viewed'])->where('c.status', 'open')->select('o.*', 'c.title', 'e.name as eg_name')->orderByDesc('o.sent_at')->get(),
            'newCases' => DB::table('cases')->where('status', 'open')->orderByDesc('id')->limit(5)->get(),
            'est' => Billing::items($sid, this_month()),
            'won' => $all->filter(fn ($s) => $s === 'won')->count(), 'appCount' => $all->count(),
            'noSheet' => $engs->filter(fn ($e) => !in_array($e->id, $withProj)),
        ]);
    }

    public function engineers(Request $r)
    {
        $list = DB::table('engineers as e')->where('e.ses_id', $r->user()->ses_id)->select('e.*')
            ->selectSub(DB::table('engineer_projects')->whereColumn('engineer_id', 'e.id')->selectRaw('count(*)'), 'projs')->orderBy('e.name')->get();
        $skills = DB::table('engineer_skills')->whereIn('engineer_id', $list->pluck('id'))->get()->groupBy('engineer_id');
        $users = DB::table('users')->where('role', 'eg')->whereIn('engineer_id', $list->pluck('id'))->get()->keyBy('engineer_id');
        foreach ($list as $e) {
            $e->skills = implode('、', ($skills[$e->id] ?? collect())->pluck('name')->all());
            $e->account = user_state($users[$e->id] ?? null);
        }
        return view('ses.engineers', compact('list'));
    }

    public function cases(Request $r)
    {
        $mine = $r->query('mine') === '1';
        $q = DB::table('cases')->orderByDesc('id');
        $mine ? $q->where('source_ses_id', $r->user()->ses_id) : $q->where('status', 'open');
        $engs = DB::table('engineers')->where('ses_id', $r->user()->ses_id)->whereIn('status', ['available', 'proposing'])->get();
        $list = $q->get()->map(function ($c) use ($engs) {
            $best = $engs->map(fn ($e) => ['e' => $e, 's' => Matcher::score($c, $e)['score']])->sortByDesc('s')->first();
            $c->best = $best ? "{$best['e']->name}（{$best['s']}点）" : '—';
            return $c;
        });
        return view('ses.cases', compact('list', 'mine'));
    }

    public function applications(Request $r)
    {
        $list = DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->where('a.ses_id', $r->user()->ses_id)->select('a.*', 'c.title', 'e.name as eg_name')->orderByDesc('a.updated_at')->get();
        return view('ses.applications', ['list' => $list, 'funnel' => AdminController::funnel($r->user()->ses_id)]);
    }

    public function contracts(Request $r)
    {
        $list = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->join('engineers as e', 'e.id', '=', 'k.engineer_id')
            ->where('k.ses_id', $r->user()->ses_id)->select('k.*', 'c.title', 'e.name as eg_name')->orderBy('k.status')->orderBy('k.end_date')->get();
        return view('ses.contracts', compact('list'));
    }

    public function invoices(Request $r)
    {
        return view('ses.invoices', [
            'list' => DB::table('invoices')->where('ses_id', $r->user()->ses_id)->where('status', '!=', 'draft')->orderByDesc('period')->get(),
            'est' => Billing::items($r->user()->ses_id, this_month()),
        ]);
    }

    public function company(Request $r)
    {
        return view('ses.company', ['s' => DB::table('ses_companies')->find($r->user()->ses_id), 'users' => DB::table('users')->where('ses_id', $r->user()->ses_id)->where('role', 'ses')->get()]);
    }

    public function saveCompany(Request $r)
    {
        // 名前の変更は、三つの入口（最初の登録・アカウント・ここ）で同じ動きにするため Workflow に任せる
        $r->merge(['name' => clean_name($r->input('name'))]);
        $r->validate(['name' => 'nullable|max:100'], [], ['name' => 'SES名（会社名）']);
        $ad = W::addressCols($r->all() + ['postal_code' => null, 'address' => null], '所在地'); // 送られなかった欄は空として扱う（この画面は全欄を送る）
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        $warn = ($name = $r->input('name')) ? W::renameSes((int) $r->user()->ses_id, $name, $r->user()) : null;
        DB::table('ses_companies')->where('id', $r->user()->ses_id)->update([
            ...W::sesPeopleCols($r->all()), 'phone' => str_in($r->input('phone')),
            ...$ad['ok'], 'employees' => int_or_null($r->input('employees')), 'strengths' => str_in($r->input('strengths')), 'updated_at' => now_str(),
        ]);
        return $warn ? $this->go('/ses/company', '保存しました。'.$warn, 'warn') : $this->go('/ses/company', '保存しました。');
    }

    // 管理者が入れた会社の情報を、SES が確かめて責任を持つ
    public function confirmCompany(Request $r)
    {
        DB::table('ses_companies')->where('id', $r->user()->ses_id)->update(['self_confirmed_at' => now_str()]);
        N::audit($r->user()->id, 'ses-confirm', (string) $r->user()->ses_id);
        return $this->go('/ses/company', '会社の情報を確認しました。内容が変わったら、ご自身で直してください。');
    }
}
