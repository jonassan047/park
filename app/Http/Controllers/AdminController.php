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

    // 「招待の状況」の行：管理者と SES の担当者、担当者がまだいない SES
    private function inviteRows(): array
    {
        $rows = [];
        $sesNames = DB::table('ses_companies')->where('kind', 'ses')->pluck('name', 'id');
        foreach (DB::table('users')->whereIn('role', ['admin', 'ses'])->orderBy('role')->orderBy('id')->get() as $u) {
            if ($u->left_at) continue; // 退会済の人は出さない
            $state = user_state($u); $until = null;
            if ($state === '招待中') {
                if ($u->token_expires && $u->token_expires > now_str()) $until = date('n/j', strtotime($u->token_expires));
                else $state = '期限切れ';
            }
            $isAdmin = $u->role === 'admin';
            $rows[] = ['role' => lbl('role', $u->role), 'name' => $u->name, 'org' => $isAdmin ? $u->company_name : ($sesNames[$u->ses_id] ?? null),
                'state' => $state, 'until' => $until, 'last_login' => $u->last_login,
                'reinvite' => $isAdmin && $u->active && !$u->password ? $u->id : null, 'open' => $isAdmin ? null : $u->ses_id];
        }
        $noUser = DB::table('ses_companies as s')->where('s.kind', 'ses')->whereIn('s.status', ['active', 'pending'])
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('users')->whereColumn('users.ses_id', 's.id')->where('users.role', 'ses')->whereNull('users.left_at'))->orderBy('s.id')->get(['s.id', 's.name']);
        foreach ($noUser as $s) {
            $rows[] = ['role' => 'SES', 'name' => '—', 'org' => $s->name, 'state' => '担当者が未登録', 'until' => null, 'last_login' => null, 'reinvite' => null, 'open' => $s->id];
        }
        return $rows;
    }

    public function dashboard()
    {
        $limit = date('Y-m-d', strtotime('+31 days'));
        $ses = fn () => DB::table('ses_companies')->where('kind', 'ses');
        $sesCount = $ses()->whereIn('status', ['active', 'pending'])->count();
        $engCount = DB::table('engineers')->count();
        $caseCount = DB::table('cases')->count();
        $adminCount = DB::table('users')->where('role', 'admin')->count();
        $invites = $this->inviteRows();
        return view('admin.dashboard', [
            'start' => $sesCount === 0 && $engCount === 0 && $caseCount === 0 ? [
                ['ほかの管理者を登録する（任意）', '/admin/admins/new', $adminCount >= 2, '管理者を登録する'],
                ['SES を登録して、ご担当者を招待する', '/admin/ses/new', $sesCount >= 1, 'SES を登録する'],
                ['エンジニアを登録する（SES を選んでから）', '/admin/ses', $engCount >= 1, 'SES を選ぶ'],
                ['案件を登録する', '/cases/new', $caseCount >= 1, '案件を登録する'],
            ] : null,
            'invites' => $invites,
            'onlyMe' => count($invites) <= 1,
            'pendingSes' => DB::table('ses_companies')->where('kind', 'ses')->where('status', 'pending')->orderBy('id')->get(),
            'counts' => [
                'ses' => $ses()->where('status', 'active')->count(),
                'sesPending' => $ses()->where('status', 'pending')->count(),
                'sesInvited' => DB::table('users')->where('role', 'ses')->where('active', 1)->whereNull('password')->count(),
                'eng' => $engCount, 'engAvail' => DB::table('engineers')->where('status', 'available')->count(),
                'cases' => DB::table('cases')->where('status', 'open')->count(), 'casesAll' => $caseCount,
                'invUnpaid' => DB::table('invoices')->where('status', 'issued')->count(),
                'invited' => DB::table('users')->whereIn('role', ['admin', 'ses', 'eg'])->where('active', 1)->whereNull('password')->count(),
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

    public function adminNew() { return view('admin.admin-new'); }

    public function sesStore(Request $r)
    {
        if (!str_in($r->input('name'))) return back()->withInput()->with('flash', ['msg' => '会社名を入れてください。', 'type' => 'bad']);
        $email = strtolower((string) str_in($r->input('email')));
        // 新しく登録するときは、メールアドレスと担当者名が必須（担当の方に招待メールを送るため）
        if ($email === '') return back()->withInput()->with('flash', ['msg' => 'メールアドレスを入れてください（担当の方に招待メールを送ります）。', 'type' => 'bad']);
        if (name_parts($r->all(), 'contact', 'contact_name')[0] === null) return back()->withInput()->with('flash', ['msg' => '担当者名を入れてください（招待メールの宛名になります）。', 'type' => 'bad']);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) return back()->withInput()->with('flash', ['msg' => 'メールアドレスを正しく入れてください。', 'type' => 'bad']);
        if (DB::table('users')->where('email', $email)->exists()) return back()->withInput()->with('flash', ['msg' => 'このメールアドレスはすでに使われています。', 'type' => 'bad']);
        $ad = W::addressCols($r->all(), '所在地');
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        // 管理者が登録した SES は、承認の手順を省いて、すぐ参加中にする（担当者に招待が届く）
        // 守秘義務への同意は、担当者が最初のログイン（招待）で行う
        $id = W::applySes($r->all(), $r->user());
        W::approveSes($id, $r->user(), str_in($r->input('name')).' を PARK に登録しました。');
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
        $users = DB::table('users')->where('ses_id', $id)->where('role', 'ses')->get();
        // 会社が参加中なのに、使える担当者がいないとき注意を出す。止めている人がいれば「再開」、いなければ「登録」を勧める
        $usable = $users->where('active', 1)->count();
        $noUsable = $s->status === 'active' && $usable === 0 && $users->count() > 0 ? ($users->whereNull('left_at')->count() > 0 ? 'stopped' : 'left') : null;
        return view('admin.ses-show', [
            's' => $s,
            'engs' => DB::table('engineers')->where('ses_id', $id)->orderBy('name')->get(),
            'users' => $users, 'noUsable' => $noUsable,
            'invs' => DB::table('invoices')->where('ses_id', $id)->orderByDesc('period')->get(),
        ]);
    }

    public function sesEdit(int $id)
    {
        return view('admin.ses-edit', ['s' => $this->sesOrFail($id)]);
    }

    public function sesUserNew(int $id)
    {
        return view('admin.ses-user-new', ['s' => $this->sesOrFail($id)]);
    }

    // SES を退会済にする（記録は残す）。所属エンジニアも退会済にし、ログインできなくする。稼働中の契約があるときはできない
    public function sesDelete(Request $r, int $id)
    {
        $s = $this->sesOrFail($id);
        if ($s->status === 'left') return $this->go("/admin/ses/$id", 'すでに退会済です。');
        if (DB::table('contracts')->where('ses_id', $id)->where('status', 'active')->exists()) return $this->go("/admin/ses/$id", '退会にできません。稼働中の契約があります。先に契約を終了してください。', 'bad');
        DB::transaction(function () use ($id) {
            $egIds = DB::table('engineers')->where('ses_id', $id)->pluck('id');
            DB::table('engineers')->where('ses_id', $id)->update(['status' => 'left']);
            DB::table('users')->where(fn ($q) => $q->where('ses_id', $id)->orWhereIn('engineer_id', $egIds))->whereIn('role', ['ses', 'eg'])->update(['active' => 0, 'left_at' => DB::raw("coalesce(left_at, '".now_str()."')")]);
            DB::table('ses_companies')->where('id', $id)->update(['status' => 'left']);
        });
        N::audit($r->user()->id, 'ses-leave', $s->name);
        return $this->go("/admin/ses/$id", 'SES「'.$s->name.'」と所属エンジニアを退会済にしました。');
    }

    public function sesUpdate(Request $r, int $id)
    {
        $this->sesOrFail($id);
        $ad = W::addressCols($r->all() + ['postal_code' => null, 'address' => null], '所在地'); // 送られなかった欄は空として扱う（この画面は全欄を送る）
        if (isset($ad['bad'])) return back()->withInput()->with('flash', ['msg' => $ad['bad'], 'type' => 'bad']);
        DB::table('ses_companies')->where('id', $id)->update([
            'name' => str_in($r->input('name')), ...W::sesPeopleCols($r->all()),
            'email' => strtolower((string) str_in($r->input('email'))) ?: null, 'phone' => str_in($r->input('phone')), ...$ad['ok'],
            'employees' => int_or_null($r->input('employees')), 'strengths' => str_in($r->input('strengths')), 'notes' => str_in($r->input('notes')), 'updated_at' => now_str(),
        ]);
        return $this->go("/admin/ses/$id", '保存しました。');
    }

    public function sesApprove(Request $r, int $id)
    {
        $this->sesOrFail($id);
        if (!W::approveSes($id, $r->user())) return $this->go("/admin/ses/$id", '承認しました。ただし、招待メールは送っていません（メールアドレスがない、担当者がすでに使っている、または停止中・退会済です）。', 'warn');
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
        if ($email && DB::table('users')->where('email', $email)->where('active', 0)->exists()) return $this->go("/admin/ses/$id", 'このメールアドレスは使えません（停止中か退会済です）。', 'bad');
        if (!$email || DB::table('users')->where('email', $email)->whereNotNull('password')->exists()) return $this->go("/admin/ses/$id", 'このメールアドレスは使えません（空か、すでに使われています）。', 'bad');
        [$full, $sei, $mei] = name_parts($r->all(), 'name', 'name');
        if (!W::invite(['email' => $email, 'name' => (string) $full, 'name_sei' => $sei, 'name_mei' => $mei, 'kana_sei' => $r->input('kana_sei'), 'kana_mei' => $r->input('kana_mei'), 'role' => 'ses', 'ses_id' => $id], $r->user())) {
            return $this->go("/admin/ses/$id", 'このメールアドレスには招待を出せません（ほかの立場の人が使っています）。', 'bad');
        }
        return $this->go("/admin/ses/$id", '担当者を登録し、招待メールを送りました。');
    }

    // 担当者の停止・再開・退会（手続きは Workflow）。管理者はどの会社でも、SES は自社の担当者だけ（ご自身は不可）。
    // 戻り先は、管理者なら SES の詳細、SES なら会社情報
    private function sesUserDo(Request $r, int $id, int $uid, string $method)
    {
        $u = $r->user();
        $isSes = $u->role === 'ses';
        abort_unless($u->role === 'admin' || $isSes, 403);
        abort_if($isSes && (int) $u->ses_id !== $id, 404); // 他社の会社は見つからない扱い
        $this->sesOrFail($id);
        $back = $isSes ? '/ses/company' : "/admin/ses/$id";
        if ($isSes && $uid === (int) $u->id && DB::table('users')->where('id', $uid)->where('ses_id', $id)->where('role', 'ses')->exists()) {
            return $this->go($back, 'ご自身は、停止・再開・退会にできません。別の担当者か管理者にお願いしてください。', 'bad');
        }
        $res = W::$method($id, $uid, $u);
        abort_unless($res, 404);
        return $this->go($back, $res['msg'], $res['type']);
    }

    public function sesUserSuspend(Request $r, int $id, int $uid) { return $this->sesUserDo($r, $id, $uid, 'suspendSesUser'); }
    public function sesUserResume(Request $r, int $id, int $uid) { return $this->sesUserDo($r, $id, $uid, 'resumeSesUser'); }
    public function sesUserDelete(Request $r, int $id, int $uid) { return $this->sesUserDo($r, $id, $uid, 'leaveSesUser'); }

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
        // 直属のエンジニアが今いるときだけ、「直属だけ」のボタンを出す（新しく直属を作る入口はない）
        $hasDirect = DB::table('engineers as e')->join('ses_companies as s', 's.id', '=', 'e.ses_id')->where('s.kind', 'direct')->exists();
        return view('admin.engineers', ['list' => $list->values(), 'q' => $q, 'st' => $st, 'direct' => $direct, 'hasDirect' => $hasDirect]);
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
        foreach ($users as $u) N::queueMail($u->email, $u->name, $subject, user_label($u)." 様\n\n$body", 'broadcast', fromName: admin_sender($r->user()));
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
        return view('admin.settings', ['canSend' => N::canSend()]);
    }

    // 管理者の一覧と登録
    public function adminsIndex()
    {
        return view('admin.admins', ['admins' => DB::table('users')->where('role', 'admin')->orderBy('id')->get()]);
    }

    public function saveSettings(Request $r)
    {
        foreach (array_keys(PARK_DEFAULT_SETTINGS) as $k) if ($r->has($k)) set_setting($k, trim((string) $r->input($k)));
        N::audit($r->user()->id, 'settings', mb_substr(json_encode($r->except('_token'), JSON_UNESCAPED_UNICODE), 0, 300));
        return $this->go('/admin/settings', '保存しました。');
    }

    public function testMail(Request $r)
    {
        $id = N::queueMail((string) $r->input('to'), $r->user()->name, 'テストメール', 'PARK からのテストメールです。届いていれば、メールの設定は正しく動いています。', 'test', fromName: admin_sender($r->user()));
        if (!N::canSend()) return $this->go('/admin/mail', 'メールの送信が設定されていないため、保存だけしました。', 'warn');
        $ok = DB::table('mail_outbox')->where('id', $id)->value('status') === 'sent';
        return $this->go('/admin/settings', $ok ? '送りました。届いたか確認してください。' : '送れませんでした。メールの画面でエラーを確認してください。', $ok ? 'ok' : 'bad');
    }

    public function admins(Request $r)
    {
        [$name, $sei, $mei] = name_parts($r->all(), 'name', 'name'); $company = clean_name($r->input('company_name'));
        $email = strtolower(trim((string) $r->input('email')));
        $bad = fn (string $msg) => back()->withInput()->with('flash', ['msg' => $msg, 'type' => 'bad']);
        if ($name === null) return $bad('担当者名（姓）を入れてください。');
        if ($company === null) return $bad('企業名を入れてください。');
        if (mb_strlen((string) $sei) > 50 || mb_strlen((string) $mei) > 50) return $bad('担当者名は、姓・名とも50文字までです。');
        if (mb_strlen((string) $r->input('kana_sei')) > 50 || mb_strlen((string) $r->input('kana_mei')) > 50) return $bad('ふりがなは、姓・名とも50文字までです。');
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return $bad('メールアドレスを正しく入れてください。');
        if (mb_strlen($company) > 100) return $bad('企業名は100文字までです。');
        if (DB::table('users')->where('email', $email)->exists()) return $bad('このメールアドレスはすでに使われています。');
        // 任意の項目：所属・役職、電話番号、郵便番号、住所
        $position = str_in($r->input('position')); $phone = str_in($r->input('phone'));
        if ($position !== null && mb_strlen($position) > 100) return $bad('所属・役職は100文字までにしてください。');
        if ($phone !== null && (mb_strlen($phone) > 30 || !preg_match('/^[0-9+\-() ]*$/', $phone))) return $bad('電話番号は30文字までの、数字・+・-・()・空白で入れてください。');
        $ad = W::addressCols($r->only(['postal_code', 'address']));
        if (isset($ad['bad'])) return $bad($ad['bad']);
        W::invite(['email' => $email, 'name' => $name, 'name_sei' => $sei, 'name_mei' => $mei, 'kana_sei' => $r->input('kana_sei'), 'kana_mei' => $r->input('kana_mei'), 'role' => 'admin', 'company_name' => $company,
            'position' => $position, 'phone' => $phone] + $ad['ok'], $r->user());
        return $this->go('/admin/admins', '登録し、招待メールを送りました。');
    }

    // 管理者の詳細（管理者だけ。住所などの個人情報が出る）
    public function adminShow(int $id)
    {
        $u = DB::table('users')->where('id', $id)->where('role', 'admin')->first();
        abort_unless($u, 404);
        return view('admin.admin-show', ['u' => $u, 'self' => $u->id === auth()->id()]);
    }

    // 管理者の編集画面
    public function adminEdit(int $id)
    {
        $u = DB::table('users')->where('id', $id)->where('role', 'admin')->first();
        abort_unless($u, 404);
        return view('admin.admin-edit', ['u' => $u, 'self' => $u->id === auth()->id()]);
    }

    public function adminUpdate(Request $r, int $id)
    {
        abort_unless(DB::table('users')->where('id', $id)->where('role', 'admin')->exists(), 404);
        $res = W::updateAdmin($id, $r->only(['name_sei', 'name_mei', 'kana_sei', 'kana_mei', 'company_name', 'email', 'position', 'phone', 'postal_code', 'address']), $r->user());
        if ($res['type'] === 'bad') return back()->withInput()->with('flash', ['msg' => $res['msg'], 'type' => 'bad']);
        return $this->go("/admin/admins/$id", $res['msg'], $res['type']);
    }

    // まだパスワードを設定していない管理者に、招待を送り直す（リンクは新しくなり、7日に戻る）
    public function reinviteAdmin(Request $r, int $id)
    {
        $u = DB::table('users')->where('id', $id)->where('role', 'admin')->where('active', 1)->whereNull('password')->first();
        if (!$u) return $this->go('/admin/admins', 'この人には送り直せません。', 'bad');
        W::invite(['email' => $u->email, 'name' => $u->name, 'role' => 'admin'], $r->user());
        // 詳細から押したら詳細へ、一覧から押したら一覧へ戻る
        $back = url()->previous();
        $to = $back === url("/admin/admins/$id") ? "/admin/admins/$id" : '/admin/admins';
        return $this->go($to, '招待を送り直しました。');
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
