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
        $sid = (int) $r->user()->ses_id;
        $engs = DB::table('engineers')->where('ses_id', $sid)->get();
        $withProj = DB::table('engineer_projects')->distinct()->pluck('engineer_id')->all();
        // 上の表（2026-11-27 TEZZO の SESダッシュボード.xlsx）：企業内（自社）とグループ内（PARK 全体）の数。グループ内は合計の数だけで、他社の名前は出さない
        $fy = (int) date('n') >= 4 ? date('Y').'-04-01' : (date('Y') - 1).'-04-01'; // 年度は4月から
        $count = function (?int $ses) use ($fy) {
            $e = fn () => DB::table('engineers')->where('status', '!=', 'left')->when($ses, fn ($q) => $q->where('ses_id', $ses));
            $c = fn () => DB::table('contracts')->when($ses, fn ($q) => $q->where('ses_id', $ses));
            return [
                $e()->count(), $e()->where('status', 'working')->count(), $e()->whereIn('status', ['available', 'proposing'])->count(),
                DB::table('applications')->whereIn('status', PipelineController::OPEN)->when($ses, fn ($q) => $q->where('ses_id', $ses))->count(),
                $c()->where('created_at', '>=', date('Y-m-01'))->count(), $c()->where('created_at', '>=', $fy)->count(),
            ];
        };
        // 下の5つの表：自社の応募を、成約に近い順に状態で分ける
        $apps = DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->where('a.ses_id', $sid)->whereIn('a.status', PipelineController::OPEN)
            ->select('a.*', 'c.title', 'c.code as case_code', 'e.name as eg_name', 'e.code as eg_code')->orderByDesc('a.updated_at')->get();
        $by = fn (array $st) => $apps->whereIn('status', $st)->values();
        return view('ses.dashboard', [
            's' => DB::table('ses_companies')->find($sid), 'engs' => $engs,
            'rows' => ['企業内' => $count($sid), 'グループ内' => $count(null)],
            'decide' => $by(['interviewed']),          // 合否決定待ち（面談済み）
            'meet' => $by(['interview']),              // 面談予定（面談調整）
            'pv' => $by(['submitted', 'doc_passed']),  // プロバイダーの回答待ち（書類提出・書類通過）
            'offers' => DB::table('case_offers as o')->join('cases as c', 'c.id', '=', 'o.case_id')->join('engineers as e', 'e.id', '=', 'o.engineer_id')
                ->where('e.ses_id', $sid)->whereIn('o.status', ['sent', 'viewed'])->where('c.status', 'open')->select('o.*', 'c.title', 'c.code as case_code', 'e.name as eg_name', 'e.code as eg_code')->orderByDesc('o.sent_at')->get(), // EG の回答待ち
            'making' => $by(['applied']),              // 提案を作成中（EG が応募し、まだプロバイダーへ出していない）
            'noSheet' => $engs->filter(fn ($e) => !in_array($e->id, $withProj)),
        ]);
    }

    public function engineers(Request $r)
    {
        $list = DB::table('engineers as e')->where('e.ses_id', $r->user()->ses_id)->select('e.*')
            ->selectSub(DB::table('engineer_projects')->whereColumn('engineer_id', 'e.id')->selectRaw('count(*)'), 'projs')
            ->selectSub(DB::table('contracts')->whereColumn('engineer_id', 'e.id')->selectRaw('count(*)'), 'wins') // 実績＝成約の件数
            ->selectSub(DB::table('applications')->whereColumn('engineer_id', 'e.id')->selectRaw('count(*)'), 'apps')->get(); // 応募の件数
        [$list, $left] = filter_left($list, 'eg'); // 自社の行の中から、退会済みは選んだときだけ出す
        $order = list_order(['reg', 'kana']); // 自社だけなので「企業ごと」は無い
        [$list, $pager] = page_slice($order === 'kana' ? sort_kana($list) : sort_reg($list, 'code')); // 1ページ 20 件
        $skills = SkillChecks::summaryMany($list->pluck('id')->all());
        // ログインの状態：兼任の人も engineer_id で探す（管理者との兼任かどうかは、ここには出さない）
        $users = DB::table('users')->whereIn('engineer_id', $list->pluck('id'))->orderByRaw("case when role = 'eg' then 1 else 0 end")->get()->keyBy('engineer_id');
        $staff = \App\Services\EgStaff::ofMany($list); // 担当者（まとめて読む）
        foreach ($list as $e) {
            $x = $staff[(int) $e->id] ?? null;
            $e->staff_name = $x?->name; // 担当者の氏名と ID
            $e->staff_code = $x?->staff_code;
            $e->skills = $skills[$e->id] ?? ''; // 同じ技術は1回だけ
            $e->account = engineer_state($e, $users[$e->id] ?? null);
            $e->mail = self::mailForSes($users[$e->id] ?? null);
            $e->login_id = self::provisionalLoginId($users[$e->id] ?? null);
        }
        $egIds = $list->pluck('id');
        return view('ses.engineers', [
            's' => DB::table('ses_companies')->find($r->user()->ses_id),
            'list' => $list, 'pager' => $pager, 'left' => $left,
            // 連絡メールを送れる人（行にチェックを出す）。判定は DirectMail の1か所
            'mailable' => \App\Services\DirectMail::engineerTargets($r->user(), $egIds->all())['ok'],
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
        $exps = SkillChecks::expMany($engs->pluck('id')->all());
        $cases = $q->get();
        $texts = \App\Services\CaseMail::priceTextsForSes((int) $r->user()->ses_id, $cases->pluck('id')->map(fn ($x) => (int) $x)->all()); // 案件ごとに問い合わせず、まとめて読む
        $list = $cases->map(function ($c) use ($engs, $idxs, $exps, $r, $texts) {
            $best = $engs->map(fn ($e) => ['e' => $e, 's' => Matcher::score($c, $e, $idxs[$e->id] ?? [], $exps[$e->id] ?? [])['score']])->sortByDesc('s')->first();
            $c->best = $best ? "{$best['e']->name}（{$best['s']}点）" : '—';
            $c->price_disp = self::priceDisp($r->user(), $c, $texts); // 自社が受注 SES の案件だけ、プロバイダーからの金額
            return $c;
        });
        return view('ses.cases', compact('list', 'mine'));
    }

    // 一覧の金額の列（v0.17.0）：自社が受注 SES の案件はプロバイダーからの金額。ほかは、自社あての紹介の依頼で伝えられた単価の文、無ければ「—」
    public static function priceDisp($u, object $c, array $texts): string
    {
        return \App\Services\CaseMail::canSend($u, $c) ? man($c->price) : ($texts[(int) $c->id] ?? '—');
    }

    public function applications(Request $r)
    {
        $list = DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->where('a.ses_id', $r->user()->ses_id)->select('a.*', 'c.title', 'c.code as case_code', 'e.name as eg_name')->orderByDesc('a.updated_at')->get();
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

    // SES・担当者の画面に出すメールアドレス。管理者を兼ねる人（DB の role が admin）のアドレスは出さない
    public static function mailForSes(?object $u): string
    {
        return $u && $u->role === 'admin' ? '—' : account_mail($u);
    }

    public function company(Request $r)
    {
        $id = $r->user()->ses_id;
        $engs = sort_reg(DB::table('engineers')->where('ses_id', $id)->get(), 'code'); // EG- の順
        $egIds = $engs->pluck('id');
        $s = DB::table('ses_companies')->find($id);
        $egUsers = DB::table('users')->whereIn('engineer_id', $egIds)->orderByRaw("case when role = 'eg' then 1 else 0 end")->get()->keyBy('engineer_id');
        foreach ($engs as $e) { $e->account = engineer_state($e, $egUsers[$e->id] ?? null); $e->mail = self::mailForSes($egUsers[$e->id] ?? null); $e->login_id = self::provisionalLoginId($egUsers[$e->id] ?? null); }
        // 並びは 代表 → 次の代表 → 一般（ST- の順）→ 担当者を兼ねる管理者（ST- の順）
        $users = sort_reg(DB::table('users')->where('ses_id', $id)->whereIn('role', ['ses', 'admin'])->get(), 'staff_code')->sortBy(fn ($x) => staff_rank($x, $s))->values();
        // 表に出す行だけ、退会済みを除く（下の契約・応募の数えは全件のまま）
        [$users, $staffLeft] = filter_left($users, 'staff');
        [$engsShown, $egLeft] = filter_left($engs, 'eg');
        return view('ses.company', [
            's' => $s, 'repName' => Registration::repName($s)[0], 'repCode' => $s->rep_user_id ? DB::table('users')->where('id', $s->rep_user_id)->value('staff_code') : null, 'isRep' => Requirements::isRep($r->user()), 'repId' => $s->rep_user_id,
            'users' => $users, 'staffLeft' => $staffLeft, 'egLeft' => $egLeft,
            'engs' => $engsShown,
            'working' => DB::table('contracts')->whereIn('engineer_id', $egIds)->where('status', 'active')->pluck('engineer_id')->flip(),
            'inSel' => DB::table('applications')->whereIn('engineer_id', $egIds)->whereIn('status', PipelineController::OPEN)->get(['engineer_id'])->groupBy('engineer_id')->map->count(),
            'invs' => DB::table('invoices')->where('ses_id', $id)->where('status', '!=', 'draft')->orderByDesc('period')->get(),
        ]);
    }

    // 一般担当者の仮登録は PARK窓口担当者だけ（一般担当者は 403。K13）。入れるのは 姓・名・メール の3つだけ
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
        if ($res['linked']) return $this->go('/ses/company', 'このメールアドレスのエンジニアのアカウントに、一般担当者の役割を足しました（仮登録のご案内は送っていません）。');
        return $this->go('/ses/company', '一般担当者を仮登録し、仮登録のご案内を送りました。');
    }

    // 企業情報の編集は PARK窓口担当者だけ（一般担当者は見るだけ。K34）
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
        $now = DB::table('ses_companies')->find($sesId);
        $ceoNow = Registration::ceoColumns($in);
        if ((int) $now->ceo_same !== $ceoNow['ceo_same'] || ($now->ceo_name ?? null) !== $ceoNow['ceo_name']) \App\Services\Notifier::audit($r->user()->id, 'ses-ceo', "ses #{$sesId} 企業代表者を変更"); // 氏名は書かない
        DB::transaction(function () use ($r, $in, $ad, $sesId, &$warn) {
            // 名前の変更は、アカウント・管理者の画面と同じ動きにするため Workflow に任せる
            $warn = W::renameSes($sesId, (string) $in['ses_name'], $r->user());
            DB::table('ses_companies')->where('id', $sesId)->update(Registration::ceoColumns($in) + [
                'phone' => $in['phone'], ...$ad['ok'], 'employees' => int_or_null($r->input('employees')), 'strengths' => str_in($r->input('strengths')), 'updated_at' => now_str(),
            ]);
            Registration::syncRepName($sesId, $in['name_sei'], $in['name_mei']); // SES の姓名は、会社の欄と本人の氏名の両方に
        });
        return $warn ? $this->go('/ses/company', '保存しました。'.$warn, 'warn') : $this->go('/ses/company', '保存しました。');
    }

}
