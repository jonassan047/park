<?php

namespace App\Http\Controllers;

use App\Services\Billing;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 管理者の画面：ダッシュボード・SES・エンジニア・案件・選考・契約・請求・やり取り・メール・分析・設定・記録
class AdminController extends Controller
{
    // 応募のファネル（応募 → 成約）。見送り・辞退の応募も、どの段階まで進んだかで数える
    public static function funnel(?int $sesId = null): array
    {
        $q = DB::table('applications');
        if ($sesId) $q->where('ses_id', $sesId);
        $apps = $q->get(['id', 'status']);
        $reach = [];
        foreach ($apps as $a) {
            $i = array_search($a->status, PARK_FUNNEL, true);
            if ($i === false) {
                $i = -1;
                foreach (DB::table('application_events')->where('application_id', $a->id)->pluck('status') as $st) {
                    $j = array_search($st, PARK_FUNNEL, true);
                    if ($j !== false) $i = max($i, $j);
                }
            }
            $reach[] = $i;
        }
        $out = [];
        foreach (PARK_FUNNEL as $i => $st) {
            $n = count(array_filter($reach, fn ($x) => $x >= $i));
            $out[] = [lbl('appStatus', $st), $n, $i ? pct($n, $out[$i - 1][1]) : ''];
        }
        return $out;
    }

    public function dashboard()
    {
        $limit = date('Y-m-d', strtotime('+31 days'));
        return view('admin.dashboard', [
            'pendingSes' => DB::table('ses_companies')->where('kind', 'ses')->where('status', 'pending')->orderBy('id')->get(),
            'counts' => [
                'ses' => DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->count(),
                'eng' => DB::table('engineers')->count(), 'engAvail' => DB::table('engineers')->where('status', 'available')->count(),
                'cases' => DB::table('cases')->where('status', 'open')->count(),
                'apps' => DB::table('applications')->whereIn('status', ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed'])->count(),
                'contracts' => DB::table('contracts')->where('status', 'active')->count(),
            ],
            'revenue' => Billing::monthTotal(this_month()),
            'todo' => DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
                ->whereIn('a.status', ['applied', 'doc_passed', 'interviewed'])->select('a.*', 'c.title', 'e.name as eg_name')->orderBy('a.updated_at')->get(),
            'openCases' => DB::table('cases as c')->where('status', 'open')->select('c.*')
                ->selectSub(DB::table('case_offers')->whereColumn('case_id', 'c.id')->selectRaw('count(*)'), 'offers')
                ->selectSub(DB::table('applications')->whereColumn('case_id', 'c.id')->selectRaw('count(*)'), 'apps')->orderByDesc('id')->limit(10)->get(),
            'upcoming' => DB::table('interviews as iv')->join('applications as a', 'a.id', '=', 'iv.application_id')->join('cases as c', 'c.id', '=', 'a.case_id')
                ->join('engineers as e', 'e.id', '=', 'a.engineer_id')->where('iv.result', 'pending')->select('iv.*', 'c.title', 'e.name as eg_name')->orderBy('iv.scheduled_at')->limit(10)->get(),
            'ending' => DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->join('engineers as e', 'e.id', '=', 'k.engineer_id')
                ->where('k.status', 'active')->where('k.end_date', '<=', $limit)->select('k.*', 'c.title', 'e.name as eg_name')->orderBy('k.end_date')->get(),
            'unread' => DB::table('messages as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')->where('read_by_admin', false)
                ->groupBy('m.engineer_id', 'e.name')->select('m.engineer_id', 'e.name', DB::raw('count(*) as n'))->get(),
            'funnel' => self::funnel(),
            'unreviewedSkills' => DB::table('skill_terms')->where('reviewed', false)->count(),
        ]);
    }

    // ---------- SES ----------
    public function sesIndex()
    {
        $list = DB::table('ses_companies as s')->where('s.kind', 'ses')->select('s.*')
            ->selectSub(DB::table('engineers')->whereColumn('ses_id', 's.id')->selectRaw('count(*)'), 'engs')
            ->selectSub(DB::table('contracts')->whereColumn('ses_id', 's.id')->where('status', 'active')->selectRaw('count(*)'), 'working')
            ->orderBy('status')->orderBy('name')->get();
        return view('admin.ses-index', compact('list'));
    }

    public function sesNew() { return view('admin.ses-new'); }

    public function sesStore(Request $r)
    {
        if (!str_in($r->input('name'))) return back()->withInput()->with('flash', ['msg' => '会社名を入れてください。', 'type' => 'bad']);
        $email = strtolower((string) str_in($r->input('email')));
        if ($email && DB::table('users')->where('email', $email)->exists()) return back()->withInput()->with('flash', ['msg' => 'このメールアドレスはすでに使われています。', 'type' => 'bad']);
        // 管理者が登録した SES は、承認の手順を省いて、すぐ参加中にする（担当者のメールがあれば招待が届く）
        $id = W::applySes($r->all() + ['agree' => 1], $r->user());
        W::approveSes($id, $r->user(), str_in($r->input('name')).' を PARK に登録しました。');
        if (!$email) return $this->go("/admin/ses/$id", '登録しました（メールアドレスが無いので、招待は送っていません）。', 'warn');
        return $this->go("/admin/ses/$id", '登録し、ご担当者に招待メールを送りました。');
    }

    // SES の画面で扱ってよい会社だけ返す（管理者の直属の入れ物は 404）
    private function sesOrFail(int $id): object
    {
        $s = DB::table('ses_companies')->where('kind', 'ses')->where('id', $id)->first();
        abort_unless($s, 404);
        return $s;
    }

    public function sesShow(int $id)
    {
        $s = $this->sesOrFail($id);
        return view('admin.ses-show', [
            's' => $s,
            'engs' => DB::table('engineers')->where('ses_id', $id)->orderBy('name')->get(),
            'users' => DB::table('users')->where('ses_id', $id)->where('role', 'ses')->get(),
            'invs' => DB::table('invoices')->where('ses_id', $id)->orderByDesc('period')->get(),
        ]);
    }

    public function sesUpdate(Request $r, int $id)
    {
        $this->sesOrFail($id);
        DB::table('ses_companies')->where('id', $id)->update([
            'name' => str_in($r->input('name')), 'rep_name' => str_in($r->input('rep_name')), 'contact_name' => str_in($r->input('contact_name')),
            'email' => strtolower((string) str_in($r->input('email'))) ?: null, 'phone' => str_in($r->input('phone')), 'address' => str_in($r->input('address')),
            'employees' => int_or_null($r->input('employees')), 'strengths' => str_in($r->input('strengths')), 'notes' => str_in($r->input('notes')), 'updated_at' => now_str(),
            'self_confirmed_at' => null, // 管理者が書き換えたら、SES にもう一度確かめてもらう
        ]);
        return $this->go("/admin/ses/$id", '保存しました。');
    }

    public function sesApprove(Request $r, int $id)
    {
        $this->sesOrFail($id);
        W::approveSes($id, $r->user());
        return $this->go("/admin/ses/$id", '承認し、招待メールを送りました。');
    }

    public function sesSuspend(Request $r, int $id)
    {
        $this->sesOrFail($id);
        DB::table('ses_companies')->where('id', $id)->update(['status' => 'suspended']);
        N::audit($r->user()->id, 'ses-suspend', (string) $id);
        return $this->go("/admin/ses/$id", '停止しました。');
    }

    public function sesUsers(Request $r, int $id)
    {
        $this->sesOrFail($id);
        $email = strtolower((string) str_in($r->input('email')));
        if (!$email || DB::table('users')->where('email', $email)->whereNotNull('password')->exists()) return $this->go("/admin/ses/$id", 'このメールアドレスは使えません（空か、すでに使われています）。', 'bad');
        W::invite(['email' => $email, 'name' => (string) str_in($r->input('name')), 'role' => 'ses', 'ses_id' => $id], $r->user());
        return $this->go("/admin/ses/$id", '招待メールを送りました。');
    }

    // ---------- エンジニア ----------
    public function engineers(Request $r)
    {
        $q = trim((string) $r->query('q')); $st = (string) $r->query('status');
        $list = DB::table('engineers as e')->join('ses_companies as s', 's.id', '=', 'e.ses_id')->select('e.*', 's.name as ses_name', 's.kind as ses_kind')
            ->selectSub(DB::table('engineer_projects')->whereColumn('engineer_id', 'e.id')->selectRaw('count(*)'), 'projs')->orderByDesc('e.updated_at')->get();
        $skills = DB::table('engineer_skills')->get()->groupBy('engineer_id');
        foreach ($list as $e) $e->skills = implode('、', ($skills[$e->id] ?? collect())->pluck('name')->all());
        if ($st) $list = $list->where('status', $st);
        $direct = $r->query('ses') === 'direct';
        if ($direct) $list = $list->where('ses_kind', 'direct');
        if ($q !== '') $list = $list->filter(fn ($e) => str_contains(mb_strtolower(implode(' ', [$e->name, $e->kana, $e->skills, $e->ses_name, $e->strengths])), mb_strtolower($q)));
        return view('admin.engineers', ['list' => $list->values(), 'q' => $q, 'st' => $st, 'direct' => $direct]);
    }

    public function operator(Request $r)
    {
        $e = DB::table('engineers')->find((int) $r->input('engineer_id'));
        if ($e) {
            DB::table('engineers')->where('id', $e->id)->update(['is_operator' => !$e->is_operator]);
            N::audit($r->user()->id, 'operator-flag', $e->name);
        }
        return $this->go('/admin/engineers', '切り替えました。');
    }

    // ---------- 案件・選考・契約 ----------
    public function cases(Request $r)
    {
        $st = (string) ($r->query('status') ?: 'open');
        $q = DB::table('cases as c')->leftJoin('ses_companies as s', 's.id', '=', 'c.source_ses_id')->select('c.*', 's.name as ses_name')
            ->selectSub(DB::table('case_offers')->whereColumn('case_id', 'c.id')->selectRaw('count(*)'), 'offers')
            ->selectSub(DB::table('applications')->whereColumn('case_id', 'c.id')->selectRaw('count(*)'), 'apps')->orderByDesc('c.id');
        if ($st !== 'all') $q->where('c.status', $st);
        return view('admin.cases', ['list' => $q->get(), 'st' => $st]);
    }

    public function applications()
    {
        $list = DB::table('applications as a')->join('cases as c', 'c.id', '=', 'a.case_id')->join('engineers as e', 'e.id', '=', 'a.engineer_id')
            ->join('ses_companies as s', 's.id', '=', 'a.ses_id')->select('a.*', 'c.title', 'e.name as eg_name', 's.name as ses_name')->orderByDesc('a.updated_at')->get();
        return view('admin.applications', ['list' => $list, 'lanes' => ['applied', 'submitted', 'doc_passed', 'interview', 'interviewed']]);
    }

    public function contracts()
    {
        $list = DB::table('contracts as k')->join('cases as c', 'c.id', '=', 'k.case_id')->join('engineers as e', 'e.id', '=', 'k.engineer_id')
            ->join('ses_companies as s', 's.id', '=', 'k.ses_id')->select('k.*', 'c.title', 'e.name as eg_name', 's.name as ses_name')->orderBy('k.status')->orderBy('k.end_date')->get();
        return view('admin.contracts', compact('list'));
    }

    // ---------- 請求 ----------
    public function invoices(Request $r)
    {
        $list = DB::table('invoices as i')->join('ses_companies as s', 's.id', '=', 'i.ses_id')->select('i.*', 's.name as ses_name')->orderByDesc('i.period')->orderBy('s.name')->get();
        return view('admin.invoices', ['list' => $list, 'period' => $r->query('period') ?: this_month()]);
    }

    public function buildInvoices(Request $r)
    {
        $period = (string) $r->input('period');
        if (!preg_match('/^\d{4}-\d{2}$/', $period)) return $this->go('/admin/invoices', '対象の月を選んでください。', 'bad');
        $n = 0;
        foreach (DB::table('ses_companies')->where('kind', 'ses')->where('status', '!=', 'pending')->pluck('id') as $id) if (Billing::build($id, $period)) $n++;
        N::audit($r->user()->id, 'invoice-build', $period);
        return $this->go("/admin/invoices?period=$period", $n ? "{$period} の請求書を {$n} 件作りました（下書き）。" : "{$period} に請求するものはありません。");
    }

    public function issueInvoice(Request $r, int $id)
    {
        $inv = DB::table('invoices')->find($id);
        abort_unless($inv, 404);
        DB::table('invoices')->where('id', $id)->update(['status' => 'issued', 'issued_at' => now_str()]);
        N::toSes($inv->ses_id, "{$inv->period} 分のご請求書", "{$inv->period} 分の成功報酬のご請求書を発行しました。\nご請求金額：".yen($inv->total).'（税込）', "/invoices/$id", true, 'invoice');
        N::audit($r->user()->id, 'invoice-issue', "{$inv->period} SES {$inv->ses_id}");
        return $this->go("/invoices/$id", '発行し、SES に知らせました。');
    }

    public function paidInvoice(Request $r, int $id)
    {
        DB::table('invoices')->where('id', $id)->update(['status' => 'paid', 'paid_at' => now_str()]);
        N::audit($r->user()->id, 'invoice-paid', (string) $id);
        return $this->go("/invoices/$id", '入金を記録しました。');
    }

    // ---------- やり取り・メール ----------
    public function messages()
    {
        $list = DB::table('messages as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')->groupBy('e.id', 'e.name')
            ->select('e.id', 'e.name', DB::raw('max(m.created_at) as last'), DB::raw('count(*) as n'), DB::raw('sum(case when m.read_by_admin = 0 then 1 else 0 end) as unread'))
            ->orderByDesc('last')->get();
        return view('admin.messages', compact('list'));
    }

    public function mail()
    {
        return view('admin.mail', ['list' => DB::table('mail_outbox')->orderByDesc('id')->limit(300)->get(), 'canSend' => N::canSend()]);
    }

    public function mailShow(int $id)
    {
        $m = DB::table('mail_outbox')->find($id);
        abort_unless($m, 404);
        return view('admin.mail-show', ['m' => $m, 'canSend' => N::canSend()]);
    }

    public function resend(int $id)
    {
        $ok = N::deliver($id);
        return $this->go("/admin/mail/$id", $ok ? '送りました。' : '送れませんでした。', $ok ? 'ok' : 'bad');
    }

    public function flush() { N::flush(); return $this->go('/admin/mail', '送信待ちを送りました。'); }

    public function broadcast(Request $r)
    {
        $t = (string) $r->input('target');
        $subject = (string) str_in($r->input('subject')); $body = (string) str_in($r->input('body'));
        if (!$subject || !$body) return $this->go('/admin/mail', '件名と本文を入れてください。', 'bad');
        $q = DB::table('users')->where('active', 1);
        match ($t) {
            'ses' => $q->where('role', 'ses'),
            'eg' => $q->where('role', 'eg'),
            'eg_available' => $q->where('role', 'eg')->whereIn('engineer_id', DB::table('engineers')->where('status', 'available')->select('id')),
            default => $q->whereIn('role', ['ses', 'eg']),
        };
        $q->where(fn ($w) => $w->whereNull('ses_id')->orWhereIn('ses_id', DB::table('ses_companies')->where('status', 'active')->select('id')));
        $users = $q->get();
        foreach ($users as $u) N::queueMail($u->email, $u->name, $subject, user_label($u)." 様\n\n$body", 'broadcast');
        N::audit($r->user()->id, 'broadcast', "{$t}：{$subject}（".count($users).'通）');
        return $this->go('/admin/mail', count($users).'通を配信しました。');
    }

    // ---------- 分析 ----------
    public function reports()
    {
        $funnel = self::funnel();
        $sesRows = DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('name')->get()->map(function ($s) {
            $f = self::funnel($s->id);
            return [$s->name, $f[0][1], $f[1][1], $f[3][1], $f[5][1], pct($f[5][1], $f[0][1])];
        });
        $won = DB::table('contracts as k')->join('applications as a', 'a.id', '=', 'k.application_id')->get(['a.applied_at', 'k.created_at']);
        $avgDays = $won->count() ? round($won->avg(fn ($w) => days_between($w->applied_at, $w->created_at))) : null;
        $offers = DB::table('case_offers')->count(); $offersApplied = DB::table('case_offers')->where('status', 'applied')->count();
        $contracts = DB::table('contracts')->count(); $extended = DB::table('contracts')->where('extensions', '>', 0)->count();
        $months = DB::table('contracts')->pluck('start_date')->map(fn ($d) => substr($d, 0, 7))->unique()->sortDesc()->take(12);
        return view('admin.reports', [
            'funnel' => $funnel, 'sesRows' => $sesRows, 'avgDays' => $avgDays,
            'offerRate' => [pct($offersApplied, $offers), "$offersApplied / $offers"], 'extRate' => [pct($extended, $contracts), "$extended / $contracts"],
            'reasons' => DB::table('applications')->where('status', 'rejected')->groupBy('reject_reason')->select('reject_reason as r', DB::raw('count(*) as n'))->orderByDesc('n')->get(),
            'declines' => DB::table('case_offers')->where('status', 'declined')->groupBy('decline_reason')->select('decline_reason as r', DB::raw('count(*) as n'))->orderByDesc('n')->get(),
            'revenue' => $months->map(fn ($m) => [$m, Billing::monthTotal($m)])->values(),
        ]);
    }

    // ---------- 設定・記録 ----------
    public function settings()
    {
        return view('admin.settings', ['admins' => DB::table('users')->where('role', 'admin')->orderBy('id')->get(), 'canSend' => N::canSend()]);
    }

    public function saveSettings(Request $r)
    {
        foreach (array_keys(PARK_DEFAULT_SETTINGS) as $k) if ($r->has($k)) set_setting($k, trim((string) $r->input($k)));
        N::audit($r->user()->id, 'settings', mb_substr(json_encode($r->except('_token'), JSON_UNESCAPED_UNICODE), 0, 300));
        return $this->go('/admin/settings', '保存しました。');
    }

    public function testMail(Request $r)
    {
        $id = N::queueMail((string) $r->input('to'), $r->user()->name, 'テストメール', 'PARK からのテストメールです。届いていれば、メールの設定は正しく動いています。', 'test');
        if (!N::canSend()) return $this->go('/admin/mail', 'メールの送信が設定されていないため、保存だけしました。', 'warn');
        $ok = DB::table('mail_outbox')->where('id', $id)->value('status') === 'sent';
        return $this->go('/admin/settings', $ok ? '送りました。届いたか確認してください。' : '送れませんでした。メールの画面でエラーを確認してください。', $ok ? 'ok' : 'bad');
    }

    public function admins(Request $r)
    {
        $email = strtolower((string) str_in($r->input('email')));
        if (!$email || DB::table('users')->where('email', $email)->exists()) return $this->go('/admin/settings', 'このメールアドレスは使えません（空か、すでに使われています）。', 'bad');
        W::invite(['email' => $email, 'name' => (string) str_in($r->input('name')), 'role' => 'admin'], $r->user());
        return $this->go('/admin/settings', '招待メールを送りました。');
    }

    // まだパスワードを設定していない管理者に、招待を送り直す（リンクは新しくなり、7日に戻る）
    public function reinviteAdmin(Request $r, int $id)
    {
        $u = DB::table('users')->where('id', $id)->where('role', 'admin')->where('active', 1)->whereNull('password')->first();
        if (!$u) return $this->go('/admin/settings', 'この人には送り直せません。', 'bad');
        W::invite(['email' => $u->email, 'name' => $u->name, 'role' => 'admin'], $r->user());
        return $this->go('/admin/settings', '招待を送り直しました。');
    }

    public function audit()
    {
        $list = DB::table('audit_log as l')->leftJoin('users as u', 'u.id', '=', 'l.user_id')->select('l.*', 'u.name')->orderByDesc('l.id')->limit(500)->get();
        return view('admin.audit', compact('list'));
    }

    // ---------- 技術マスタ（案件メールとエンジニアのスキルを突き合わせる辞書） ----------
    public function skills(Request $r)
    {
        $q = trim((string) $r->query('q')); $cat = (string) $r->query('cat');
        $query = DB::table('skill_terms as t')->select('t.*')
            ->selectSub(DB::table('engineer_skills')->whereColumn('term_id', 't.id')->selectRaw('count(*)'), 'used')
            ->orderBy('reviewed')->orderBy('category')->orderBy('name');
        if ($cat) $query->where('category', $cat);
        if ($q !== '') $query->where(fn ($w) => $w->where('name', 'like', "%$q%")->orWhere('aliases', 'like', "%$q%"));
        return view('admin.skills', ['list' => $query->get(), 'q' => $q, 'cat' => $cat,
            'unreviewed' => DB::table('skill_terms')->where('reviewed', false)->count(), 'all' => DB::table('skill_terms')->orderBy('name')->pluck('name', 'id')->all()]);
    }

    public function addSkill(Request $r)
    {
        $name = trim((string) $r->input('name'));
        if ($name === '' || \App\Support\SkillCatalog::find($name)) return $this->go('/admin/skills', 'その言葉は空か、すでにマスタにあります（別名を含む）。', 'bad');
        DB::table('skill_terms')->insert(['name' => $name, 'category' => (string) $r->input('category', 'other'), 'aliases' => str_in($r->input('aliases')),
            'source' => 'user', 'reviewed' => true, 'created_at' => now(), 'updated_at' => now()]);
        \App\Support\SkillCatalog::forget();
        N::audit($r->user()->id, 'skill-add', $name);
        return $this->go('/admin/skills', "「{$name}」を追加しました。");
    }

    public function updateSkill(Request $r, int $id)
    {
        $t = DB::table('skill_terms')->find($id);
        abort_unless($t, 404);
        $name = trim((string) $r->input('name')) ?: $t->name;
        if ($name !== $t->name && DB::table('skill_terms')->where('name', $name)->exists()) return $this->go('/admin/skills', '同じ名前がすでにあります。統合を使ってください。', 'bad');
        DB::table('skill_terms')->where('id', $id)->update(['name' => $name, 'category' => (string) $r->input('category', $t->category),
            'aliases' => str_in($r->input('aliases')), 'reviewed' => true, 'updated_at' => now()]);
        DB::table('engineer_skills')->where('term_id', $id)->update(['name' => $name, 'category' => (string) $r->input('category', $t->category)]);
        \App\Support\SkillCatalog::forget();
        N::audit($r->user()->id, 'skill-update', $name);
        return back()->with('flash', ['msg' => "「{$name}」を確認済みにしました。", 'type' => 'ok']);
    }

    // 同じ技術の言い換え（例：自動で足した「Postgre」）を、正式な技術の別名にまとめる
    public function mergeSkill(Request $r, int $id)
    {
        $t = DB::table('skill_terms')->find($id);
        $to = DB::table('skill_terms')->find((int) $r->input('to'));
        abort_unless($t && $to && $t->id !== $to->id, 404);
        $aliases = array_unique(array_merge(parse_list($to->aliases), [$t->name], parse_list($t->aliases)));
        DB::table('skill_terms')->where('id', $to->id)->update(['aliases' => implode('、', $aliases), 'updated_at' => now()]);
        DB::table('engineer_skills')->where('term_id', $t->id)->update(['term_id' => $to->id, 'name' => $to->name, 'category' => $to->category]);
        DB::table('skill_terms')->where('id', $t->id)->delete();
        \App\Support\SkillCatalog::forget();
        N::audit($r->user()->id, 'skill-merge', "{$t->name} → {$to->name}");
        return $this->go('/admin/skills', "「{$t->name}」を「{$to->name}」の別名にまとめました。");
    }
}
