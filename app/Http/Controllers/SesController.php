<?php

namespace App\Http\Controllers;

use App\Services\Billing;
use App\Services\Matcher;
use App\Services\Notifier as N;
use App\Services\Registration;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use App\Support\Requirements;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// SES の画面：ダッシュボード・自社のエンジニア・案件・応募状況・契約・請求・会社情報
class SesController extends Controller
{
    // 仮登録中のエンジニアのアカウント（role='eg' で本登録が済んでいないもの）の id。「直す」「取り消し」の対象
    public static function provisionalLoginId(?object $u): ?int
    {
        return $u && $u->role === 'eg' && !$u->registered_at ? (int) $u->id : null;
    }

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
        $skills = SkillChecks::summaryMany($list->pluck('id')->all());
        // ログインの状態：兼任の人も engineer_id で探す（管理者との兼任かどうかは、ここには出さない）
        $users = DB::table('users')->whereIn('engineer_id', $list->pluck('id'))->orderByRaw("case when role = 'eg' then 1 else 0 end")->get()->keyBy('engineer_id');
        foreach ($list as $e) {
            $e->skills = $skills[$e->id] ?? ''; // 同じ技術は1回だけ
            $e->account = engineer_state($e, $users[$e->id] ?? null);
            $e->mail = account_mail($users[$e->id] ?? null);
            $e->login_id = self::provisionalLoginId($users[$e->id] ?? null);
        }
        $egIds = $list->pluck('id');
        return view('ses.engineers', [
            'list' => $list,
            // 停止の確認の文に添える、稼働中の契約と選考中の応募（エンジニアごとに問い合わせず、まとめて取る）
            'working' => DB::table('contracts')->whereIn('engineer_id', $egIds)->where('status', 'active')->pluck('engineer_id')->flip(),
            'inSel' => DB::table('applications')->whereIn('engineer_id', $egIds)->whereIn('status', PipelineController::OPEN)->get(['engineer_id'])->groupBy('engineer_id')->map->count(),
        ]);
    }

    public function cases(Request $r)
    {
        $mine = $r->query('mine') === '1';
        $q = DB::table('cases')->orderByDesc('id');
        $mine ? $q->where('source_ses_id', $r->user()->ses_id) : $q->where('status', 'open');
        $engs = Registration::usableEngineers(DB::table('engineers')->where('ses_id', $r->user()->ses_id)->whereIn('status', ['available', 'proposing']))->get();
        $idxs = Matcher::skillIndexMany($engs->pluck('id')->all()); // 案件の数によらず、1回だけまとめて読む
        $list = $q->get()->map(function ($c) use ($engs, $idxs) {
            $best = $engs->map(fn ($e) => ['e' => $e, 's' => Matcher::score($c, $e, $idxs[$e->id] ?? [])['score']])->sortByDesc('s')->first();
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
        $id = $r->user()->ses_id;
        $engs = DB::table('engineers')->where('ses_id', $id)->orderBy('name')->get();
        $egIds = $engs->pluck('id');
        $s = DB::table('ses_companies')->find($id);
        $egUsers = DB::table('users')->whereIn('engineer_id', $egIds)->orderByRaw("case when role = 'eg' then 1 else 0 end")->get()->keyBy('engineer_id');
        foreach ($engs as $e) { $e->account = engineer_state($e, $egUsers[$e->id] ?? null); $e->mail = account_mail($egUsers[$e->id] ?? null); $e->login_id = self::provisionalLoginId($egUsers[$e->id] ?? null); }
        return view('ses.company', [
            's' => $s, 'repName' => Registration::repName($s)[0], 'isRep' => Requirements::isRep($r->user()), 'repId' => $s->rep_user_id,
            'users' => DB::table('users')->where('ses_id', $id)->where('role', 'ses')->get(),
            'engs' => $engs,
            'working' => DB::table('contracts')->whereIn('engineer_id', $egIds)->where('status', 'active')->pluck('engineer_id')->flip(),
            'inSel' => DB::table('applications')->whereIn('engineer_id', $egIds)->whereIn('status', PipelineController::OPEN)->get(['engineer_id'])->groupBy('engineer_id')->map->count(),
            'invs' => DB::table('invoices')->where('ses_id', $id)->where('status', '!=', 'draft')->orderByDesc('period')->get(),
        ]);
    }

    // 担当者の仮登録は SES だけ（担当者は 403。K13）。入れるのは 姓・名・メール の3つだけ
    public function userNew(Request $r)
    {
        abort_unless(Requirements::isRep($r->user()), 403);
        $s = DB::table('ses_companies')->find($r->user()->ses_id);
        return view('ses.user-new', ['s' => $s]);
    }

    public function userStore(Request $r)
    {
        abort_unless(Requirements::isRep($r->user()), 403);
        [, $sei, $mei] = name_parts($r->all(), 'name', 'name');
        // 所属は自社に固定（ses_id が混ぜられても使わない）
        $res = Registration::provisionalPerson('ses', (int) $r->user()->ses_id, ['sei' => $sei, 'mei' => $mei, 'email' => $r->input('email')], $r->user());
        if (isset($res['bad'])) return $this->go('/ses/company', $res['bad'], 'bad');
        if ($res['linked']) return $this->go('/ses/company', 'このメールアドレスのエンジニアのアカウントに、SES 担当者の役割を足しました（仮登録のご案内は送っていません）。');
        return $this->go('/ses/company', '担当者を仮登録し、仮登録のご案内を送りました。');
    }

    // 会社情報の編集は SES だけ（担当者は見るだけ。K34）
    public function companyEdit(Request $r)
    {
        abort_unless(Requirements::isRep($r->user()), 403);
        $s = DB::table('ses_companies')->find($r->user()->ses_id);
        [, $sei, $mei] = Registration::repName($s);
        return view('ses.company-edit', ['s' => $s, 'rep' => ['sei' => $sei, 'mei' => $mei]]);
    }

    public function saveCompany(Request $r)
    {
        abort_unless(Requirements::isRep($r->user()), 403);
        $in = Registration::companyInput($r->all());
        // 必須の欄（企業名・SES の姓名・電話・郵便番号・住所）は、空にできない。長さ・形も確かめる
        if ($bad = Requirements::checkNotEmpty('ses', true, $in) ?? Registration::checkCompanyInput($in)) return back()->withInput()->with('flash', ['msg' => $bad, 'type' => 'bad']);
        $ad = W::addressCols($r->all(), '所在地');
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        $sesId = (int) $r->user()->ses_id;
        $warn = null;
        DB::transaction(function () use ($r, $in, $ad, $sesId, &$warn) {
            // 名前の変更は、アカウント・管理者の画面と同じ動きにするため Workflow に任せる
            $warn = W::renameSes($sesId, (string) $in['ses_name'], $r->user());
            DB::table('ses_companies')->where('id', $sesId)->update([
                'phone' => $in['phone'], ...$ad['ok'], 'employees' => int_or_null($r->input('employees')), 'strengths' => str_in($r->input('strengths')), 'updated_at' => now_str(),
            ]);
            Registration::syncRepName($sesId, $in['name_sei'], $in['name_mei']); // SES の姓名は、会社の欄と本人の氏名の両方に
        });
        return $warn ? $this->go('/ses/company', '保存しました。'.$warn, 'warn') : $this->go('/ses/company', '保存しました。');
    }

}
