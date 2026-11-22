<?php

namespace App\Http\Controllers;

use App\Services\AiJobs;
use App\Services\CaseMail as CM;
use App\Services\CaseAi;
use App\Services\CaseParser;
use App\Services\CaseSearch;
use App\Services\CaseThread;
use App\Services\Ids;
use App\Services\Matcher;
use App\Services\Notifier as N;
use App\Services\Registration;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use App\Support\CaseExp;
use App\Support\Perms;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// 案件：登録（メール本文の取り込み）・詳細・マッチングと配信・応募
class CaseController extends Controller
{
    private function sesList(Request $r)
    {
        return $r->user()->role === 'admin' ? DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('name')->pluck('name', 'id')->all() : [];
    }

    // プロバイダーの選び肢（ID 企業名）。案件の登録・編集の先頭で必須
    private function providerList(): array
    {
        $o = [];
        foreach (DB::table('providers')->orderBy('name')->orderBy('id')->get(['id', 'code', 'name']) as $p) $o[$p->id] = trim(($p->code ? $p->code.' ' : '').$p->name);
        return $o;
    }

    // 選ばれたプロバイダーが実在するか。問題があれば文を返す
    private function providerError(Request $r): ?string
    {
        $pid = int_or_null($r->input('provider_id'));
        if (!$pid) return 'プロバイダーを選んでください。';
        return DB::table('providers')->where('id', $pid)->exists() ? null : '選んだプロバイダーが見つかりません。選び直してください。';
    }

    public function create(Request $r)
    {
        return view('cases.form', ['c' => (object) [], 'action' => '/cases', 'submit' => '登録する', 'sesList' => $this->sesList($r), 'providers' => $this->providerList(), 'import' => true]);
    }

    public function import(Request $r)
    {
        $c = (object) (CaseParser::parse($r->input('raw_text')) + ['raw_text' => $r->input('raw_text')]);
        $found = $c->detected ?? [];
        $nConfirm = count(array_filter(preg_split('/\R/u', (string) ($c->confirm_items ?? '')), fn ($x) => trim($x) !== ''));
        $expText = [];
        if (!empty($c->required_exp)) $expText[] = implode('、', CaseExp::split($c->required_exp)).'（必須）';
        if (!empty($c->preferred_exp)) $expText[] = implode('、', CaseExp::split($c->preferred_exp)).'（尚可）';
        session()->now('flash', ['msg' => '取り込みました。内容を確かめて、足りないところを直してから登録してください。'.($found ? '本文から見つかった技術：'.implode('、', $found).'（必須に無いものは尚可に入れました）。' : '')
            .($expText ? '本文の必須・尚可から見つかった業務：'.implode('、', $expText).'。' : '')
            .($nConfirm ? "マスタに当たらない文 {$nConfirm} 件は「エンジニアに確認」に入れました。" : '')
            .(CaseAi::blocked((object) ['raw_text' => $r->input('raw_text')]) === null ? '登録すると、手元の AI が必須・尚可を読み直します（1件 1分ほど）。結果は案件の画面に出ます。' : ''), 'type' => 'ok']);
        return view('cases.form', ['c' => $c, 'action' => '/cases', 'submit' => '登録する', 'sesList' => $this->sesList($r), 'providers' => $this->providerList(), 'import' => false]);
    }

    public function store(Request $r)
    {
        if ($e = $this->providerError($r)) return back()->withInput()->with('flash', ['msg' => $e, 'type' => 'bad']);
        if (!str_in($r->input('title'))) return $this->go('/cases/new', '案件名を入れてください。', 'bad');
        // 管理者は、提案元 SES を必ず選ぶ（参加中の SES だけ。管理者が自分で発信する道は無い）
        $own = null;
        if ($r->user()->role === 'admin') {
            $sid = int_or_null($r->input('source_ses_id'));
            $own = $sid ? DB::table('ses_companies')->where('id', $sid)->where('kind', 'ses')->where('status', 'active')->first(['id', 'name']) : null;
            if (!$own) return back()->withInput()->with('flash', ['msg' => '提案元 SES を選んでください。', 'type' => 'bad']);
        }
        $id = W::saveCase($r->all(), $r->user());
        $queued = CaseAi::queue($id, $r->user(), true); // 本文があれば、手元の AI に必須・尚可を読み直してもらう（入れられなくても登録は成功）
        // 知らせた相手が実際にいるかで、文を変える（登録した管理者本人を除いて、会社に担当者がいないときは、管理者に知らせている）
        $hasStaff = $own && N::sesUsers($own->id)->reject(fn ($x) => (int) $x->id === (int) $r->user()->id)->isNotEmpty();
        return $this->go("/cases/$id", ($own ? ($hasStaff ? "登録しました。案内は {$own->name} の担当者が行います（担当者にお知らせしました）。" : "登録しました。{$own->name} には、案内できる担当者がいません（管理者にお知らせしました）。") :'登録しました。続けて「エンジニアに案内する」で、合うエンジニアにメールで案内できます。')
            .($queued ? ' 手元の AI が必須・尚可を読み直しています。' : ''));
    }

    private function load(int $id) { $c = DB::table('cases')->find($id); abort_unless($c, 404); return $c; }

    public function edit(Request $r, int $id)
    {
        $c = $this->load($id);
        abort_unless(Perms::caseEdit($r->user(), $c), 403);
        return view('cases.form', ['c' => $c, 'action' => "/cases/$id", 'submit' => '保存する', 'sesList' => $this->sesList($r), 'providers' => $this->providerList(), 'import' => false]);
    }

    public function update(Request $r, int $id)
    {
        $c = $this->load($id);
        abort_unless(Perms::caseEdit($r->user(), $c), 403);
        if ($e = $this->providerError($r)) return back()->withInput()->with('flash', ['msg' => $e, 'type' => 'bad']);
        W::saveCase($r->all(), $r->user(), $id);
        $new = (string) DB::table('cases')->where('id', $id)->value('raw_text');
        if ($new !== (string) $c->raw_text) CaseAi::queue($id, $r->user(), true); // 本文を変えたときだけ、読み直しを入れ直す
        return $this->go("/cases/$id", '保存しました。');
    }

    // 「AI に読み直してもらう」ボタン
    public function aiParse(Request $r, int $id)
    {
        $c = $this->load($id);
        abort_unless(Perms::caseEdit($r->user(), $c), 403);
        if ($why = CaseAi::blocked($c)) return $this->go("/cases/$id#ai", $why, 'bad');
        if (!CaseAi::queue($id, $r->user())) return $this->go("/cases/$id#ai", 'すでに AI が読み直しています。結果が出るまでお待ちください。', 'bad');
        return $this->go("/cases/$id#ai", 'AI に頼みました。手元の PC の AI が順に作業します（1件 1分ほどかかります）。');
    }

    public function show(Request $r, int $id)
    {
        $u = $r->user();
        $c = DB::table('cases as c')->leftJoin('ses_companies as s', 's.id', '=', 'c.source_ses_id')->where('c.id', $id)->select('c.*', 's.name as ses_name')->first();
        abort_unless($c, 404);
        abort_unless(Perms::caseView($u, $c), 403);

        $apps = DB::table('applications as a')->join('engineers as e', 'e.id', '=', 'a.engineer_id')->join('ses_companies as s', 's.id', '=', 'a.ses_id')
            ->where('a.case_id', $id)->select('a.*', 'e.name as eg_name', 'e.code as eg_code', 'e.ses_id as eg_ses_id', 's.name as ses_name')->orderBy('a.id');
        if ($u->role === 'ses') $apps->where('a.ses_id', $u->ses_id);
        if ($u->role === 'eg') $apps->where('a.engineer_id', $u->engineer_id);
        // 案内メールの記録：提案元 SES の担当者は操作もでき、管理者は読むだけ（v0.11.0）
        $data = ['c' => $c, 'apps' => $apps->get(), 'canMail' => CM::canSend($u, $c)];
        $data['canSeeMails'] = $data['canMail'] || $u->role === 'admin';
        if ($data['canSeeMails']) {
            $q = DB::table('case_mails as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')->join('ses_companies as s', 's.id', '=', 'e.ses_id')
                ->where('m.case_id', $id)->select('m.*', 'e.name', 'e.initials', 'e.code as eg_code', 's.name as ses_name')->orderByDesc('m.id');
            $q->addSelect('e.ses_id as eg_ses_id');
            $data['mails'] = $q->get()->map(function ($m) use ($u, $c) {
                // 管理者が送った他社の人は、提案元 SES には名前・書類を出さない（グループの人と同じ扱い）
                if ($u->role === 'ses' && (int) $m->eg_ses_id !== (int) $u->ses_id) {
                    $m->route = 'group';
                    // 提案元 SES の自社が、案内した他社のエンジニアに、やり取りで質問を受ける・書き始められる（v0.10.1）
                    $m->canThread = CaseThread::canStart($u, $c, (object) ['id' => $m->engineer_id, 'ses_id' => $m->eg_ses_id], true); // 行がある＝案内メールの記録がある
                } elseif ($u->role === 'ses') {
                    // 自社の行も同じ判定（応募・やり取りの行き先が、ここだけになるため）
                    $m->canThread = CaseThread::canStart($u, $c, (object) ['id' => $m->engineer_id, 'ses_id' => $m->eg_ses_id], true);
                }
                return $m;
            });
            $data['mailApps'] = DB::table('applications')->where('case_id', $id)->pluck('id', 'engineer_id')->all();
            // プロバイダーへの返信（下書き・送ったもの。v0.13.0）。ここに出る人（持ち込み元の担当者・管理者）は全員が見てよい
            $data['providerMails'] = DB::table('provider_mails as p')->leftJoin('engineers as e', 'e.id', '=', 'p.engineer_id')->where('p.case_id', $id)
                ->select('p.*', 'e.name as eg_name', 'e.initials as eg_initials', 'e.ses_id as eg_ses_id')->orderByDesc('p.id')->get()
                ->map(function ($p) use ($u) {
                    $p->who = $p->eg_name === null ? 'エンジニア' : CaseThread::egName($u, (object) ['name' => $p->eg_name, 'initials' => $p->eg_initials, 'ses_id' => $p->eg_ses_id]);
                    return $p;
                });
            // 案内メールの行ごとの、いま開く下書き（なければ送ったもの）
            $data['pmByCm'] = $data['providerMails']->whereNotNull('case_mail_id')->where('status', '!=', 'discarded')->sortBy(fn ($p) => $p->status === 'draft' ? 0 : 1)->groupBy('case_mail_id')->map(fn ($g) => $g->first());
            // 案内メールへの返信メールの件数（提案元 SES の担当者だけ。v0.12.0）
            if ($data['canMail']) {
                $data['inboundN'] = DB::table('inbound_mails')->where('case_id', $id)->where('status', 'linked')->selectRaw('engineer_id, count(*) as n')->groupBy('engineer_id')->pluck('n', 'engineer_id')->all();
            }
            // 追加の宛先（PARK に登録していない人へ送ったもの。v0.15.0）：新しい順。返信の件数は紐付け済みだけ数える
            $data['extras'] = DB::table('case_search_extras')->where('case_id', $id)->whereNotNull('sent_at')->orderByDesc('sent_at')->orderByDesc('id')->get();
            $data['extraN'] = DB::table('inbound_mails')->where('case_id', $id)->where('status', 'linked')->whereNotNull('search_extra_id')
                ->selectRaw('search_extra_id, count(*) as n')->groupBy('search_extra_id')->pluck('n', 'search_extra_id')->all();
        }
        // エンジニアを検索（案内できる人の右の列。募集中だけ）：自分の検索の条件（外した印・足した項目を引き継ぐ）
        if ($data['canMail'] && $c->status === 'open') {
            $data['cond'] = CaseSearch::conditions($c, DB::table('case_searches')->where('case_id', $id)->where('created_by', $u->id)->first());
        }

        if ($u->role === 'admin') {
            $data += [
                'offers' => DB::table('case_offers as o')->join('engineers as e', 'e.id', '=', 'o.engineer_id')->where('o.case_id', $id)->select('o.*', 'e.name', 'e.code as eg_code')->orderByDesc('o.score')->get(),
            ];
        } elseif ($u->role === 'ses') {
            $engs = Registration::usableEngineers(DB::table('engineers')->where('ses_id', $u->ses_id)->whereNotIn('status', ['unavailable', 'left']))->orderBy('name')->get();
            $idxs = Matcher::skillIndexMany($engs->pluck('id')->all()); // 人数によらず、まとめて読む
            $exps = SkillChecks::expMany($engs->pluck('id')->all());
            $rows = $engs->map(fn ($e) => ['e' => $e] + Matcher::score($c, $e, $idxs[$e->id] ?? [], $exps[$e->id] ?? []))->sortByDesc('score')->values()->all();
            $data += ['rows' => $rows, 'applied' => DB::table('applications')->where('case_id', $id)->pluck('engineer_id')->all(),
                'offered' => DB::table('case_offers')->where('case_id', $id)->pluck('status', 'engineer_id')->all()];
        } else {
            $offer = DB::table('case_offers')->where('case_id', $id)->where('engineer_id', $u->engineer_id)->first();
            if ($offer && $offer->status === 'sent') DB::table('case_offers')->where('id', $offer->id)->update(['status' => 'viewed', 'viewed_at' => now_str()]);
            $e = DB::table('engineers')->find($u->engineer_id);
            $data += ['offer' => $offer, 'myApp' => DB::table('applications')->where('case_id', $id)->where('engineer_id', $u->engineer_id)->first(), 'fit' => Matcher::score($c, $e)];
            // 担当者とのやり取り（開いたら、相手の発言を既読にする）
            $threads = CaseThread::withPosts(CaseThread::threadsFor($u, $id, $e), $e);
            foreach ($threads as $t) CaseThread::markRead($u, $t);
            $data += ['me' => $e, 'threads' => $threads, 'threadWhy' => CaseThread::canPost($u, $c, $e)];
        }
        if ($u->role !== 'eg') {
            // エンジニアとのやり取り（担当者は、自社のエンジニアの1本と、自社が相手の1本。管理者はすべて）
            $tq = CaseThread::listQuery()->where('t.case_id', $id)->orderByDesc('t.last_post_at');
            if ($u->role === 'ses') $tq->where(fn ($w) => $w->where('t.ses_id', $u->ses_id)->orWhere('t.partner_ses_id', $u->ses_id));
            $data['caseThreads'] = $tq->get();
        }
        if (Perms::caseEdit($u, $c)) $data['ai'] = $this->aiView($c); // AI の読み直しの欄は、直せる人だけ
        return view('cases.show', $data);
    }

    // 案件の画面の「AI の読み直し」の欄に出すもの
    private function aiView(object $c): array
    {
        $job = AiJobs::latest('case', (int) $c->id, CaseAi::KIND);
        $v = ['job' => $job, 'block' => CaseAi::blocked($c), 'cmp' => null, 'auto' => false, 'fixed' => 0, 'same' => false, 'now' => null, 'diff' => null, 'exp' => null, 'confirm' => [], 'stale' => false];
        if (!$job) return $v;
        $audit = fn (string $action) => DB::table('audit_log')->where('action', $action)->where('detail', 'like', "#{$job->id} %")->exists();
        if ($job->status === 'applied') {
            $cmp = CaseAi::compare($job, $c);
            $v['auto'] = $audit('ai-case-auto');
            $v['fixed'] = count(array_filter([...$cmp['req'], ...$cmp['pref']], fn ($x) => $x['mark'] === 'fixed'));
        } elseif ($job->status === 'done') {
            $cmp = CaseAi::compare($job, $c);
            if ($cmp['stale']) { $v['stale'] = true; return $v; } // 本文が変わったあとの結果は、表も使うボタンも出さない
            // 「今」は、元の行があればその行どうしで比べる（無い古い案件は、必須・尚可の欄）
            $lines = fn (?string $s) => array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $s)), fn ($x) => $x !== ''));
            $hasLines = $c->required_lines !== null || $c->preferred_lines !== null;
            $now = $hasLines ? ['req' => $lines($c->required_lines), 'pref' => $lines($c->preferred_lines)]
                : ['req' => skill_items($c->required_skills), 'pref' => skill_items($c->preferred_skills)];
            $fin = CaseParser::finishSkills(array_column($cmp['req'], 'text'), array_column($cmp['pref'], 'text'), (string) $c->raw_text);
            $v['cmp'] = $cmp;
            $v['now'] = $now;
            $v['diff'] = ['req' => CaseAi::diff($now['req'], array_column($cmp['req'], 'text')), 'pref' => CaseAi::diff($now['pref'], array_column($cmp['pref'], 'text'))];
            $v['exp'] = ['req' => CaseExp::split($fin['required_exp'] ?? null), 'pref' => CaseExp::split($fin['preferred_exp'] ?? null)];
            $v['confirm'] = $lines($fin['confirm_items']); // AI の結果を使うと、「エンジニアに確認」もこの内容に置き換わる
        } elseif ($job->status === 'dropped') {
            $v['same'] = $audit('ai-case-same');
        }
        return $v;
    }

    public function distribute(Request $r, int $id)
    {
        abort_if($r->user()->role === 'admin', 403); // 管理者は案件を発信できない（v0.11.0）
        $c = $this->load($id);
        if ($c->status !== 'open') return $this->go("/cases/$id", '募集中の案件だけ配信できます。', 'bad');
        $ids = array_map('intval', (array) $r->input('engineer_id', []));
        if ($r->user()->role === 'ses') $ids = DB::table('engineers')->whereIn('id', $ids)->where('ses_id', $r->user()->ses_id)->pluck('id')->all();
        if (!$ids) return $this->go("/cases/$id", '配信するエンジニアを選んでください。', 'bad');
        $n = W::distribute($id, $ids, $r->user(), (string) $r->input('message'));
        return $this->go("/cases/$id", "{$n}名に配信しました（メールと通知）。");
    }

    // 提案元ではない SES が、自社のエンジニアを推薦する（提案元 SES／管理者に、名前を伏せて知らせる）
    public function recommend(Request $r, int $id)
    {
        $u = $r->user();
        $c = $this->load($id);
        abort_unless($u->role === 'ses' && Perms::caseView($u, $c) && !CM::canSend($u, $c), 403);
        $e = DB::table('engineers')->where('id', (int) $r->input('engineer_id'))->where('ses_id', $u->ses_id)->first();
        abort_unless($e, 403);
        if ($c->status !== 'open') return $this->go("/cases/$id", '募集を終えた案件です。', 'bad');
        if ($e->status === 'left' || $e->suspended_at || !$e->registered_at) return $this->go("/cases/$id", 'このエンジニアは仮登録・停止中・退会済のため、推薦できません。', 'bad');
        $key = "case {$id} engineer {$e->id}";
        if (DB::table('audit_log')->where('action', 'recommend')->where('detail', $key)->exists()) return $this->go("/cases/$id", 'すでに推薦しています。');
        $ses = DB::table('ses_companies')->where('id', $u->ses_id)->value('name');
        $s = Matcher::score($c, $e);
        $text = "{$ses} から、案件「{$c->title}」にエンジニアの推薦がありました。\n".($e->initials ?: '—')."（{$s['score']}点）。合う点：".(implode('、', array_slice($s['reasons'], 0, 4)) ?: '—')
            ."\n案内するときは、案件の「エンジニアに案内する」の「グループのほかの人」から選んでください。";
        if ($c->source_ses_id) N::toSes($c->source_ses_id, "エンジニアの推薦がありました：{$c->title}".Ids::tag($c->code), $text, "/cases/$id", true, 'recommend');
        // 管理者は案内できないので、知らせは案件の画面へ（提案元 SES が無い古い案件のときだけ、メールも送る）
        N::toAdmins("エンジニアの推薦がありました：{$c->title}".Ids::tag($c->code), $text, "/cases/$id", !$c->source_ses_id, 'recommend');
        N::audit($u->id, 'recommend', $key);
        return $this->go("/cases/$id", $c->source_ses_id ? '推薦しました。提案元 SES に知らせました。' : '推薦しました。提案元 SES が決まっていないので、管理者に知らせました。');
    }

    public function apply(Request $r, int $id)
    {
        $u = $r->user();
        $c = $this->load($id);
        abort_unless(Perms::caseView($u, $c), 403);
        $eid = $u->role === 'eg' ? $u->engineer_id : (int) $r->input('engineer_id');
        if ($u->role === 'ses') abort_unless(DB::table('engineers')->where('id', $eid)->where('ses_id', $u->ses_id)->exists(), 403);
        if ($u->role === 'eg' && !($r->boolean('start_ok') && $r->boolean('price_ok') && $r->boolean('style_ok')) && !str_in($r->input('condition_note'))) {
            return $this->go("/cases/$id", '条件で合わない点がある場合は、「気になること」に書いてから応募してください。', 'bad');
        }
        try {
            $appId = W::apply($id, $eid, $r->all(), $u);
            CM::reply($id, $eid, 'interested', str_in($r->input('condition_note')), $u->role === 'eg' ? 'app' : 'ses');
            return $this->go("/applications/$appId", '応募しました。管理者がスキルシートを案件に合わせて整え、提出します。');
        } catch (\RuntimeException $e) {
            return $this->go("/cases/$id", $e->getMessage(), 'bad');
        }
    }

    public function decline(Request $r, int $id)
    {
        DB::table('case_offers')->where('case_id', $id)->where('engineer_id', $r->user()->engineer_id)->update(['status' => 'declined', 'decline_reason' => str_in($r->input('reason'))]);
        N::audit($r->user()->id, 'offer-decline', "案件 {$id}：".$r->input('reason'));
        CM::reply($id, (int) $r->user()->engineer_id, 'declined', str_in($r->input('reason')), 'app');
        return $this->go('/eg/offers', '見送りました。');
    }

    public function status(Request $r, int $id)
    {
        $c = $this->load($id);
        abort_unless(Perms::caseEdit($r->user(), $c), 403);
        $st = (string) $r->input('status');
        if (!isset(PARK_LABELS['caseStatus'][$st])) return redirect("/cases/$id");
        DB::table('cases')->where('id', $id)->update(['status' => $st, 'updated_at' => now_str()]);
        N::audit($r->user()->id, 'case-status', "{$c->title} → ".lbl('caseStatus', $st));
        return $this->go("/cases/$id", '「'.lbl('caseStatus', $st).'」にしました。');
    }
}
