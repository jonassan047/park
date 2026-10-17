<?php

namespace App\Http\Controllers;

use App\Services\CaseMail as CM;
use App\Services\CaseParser;
use App\Services\Matcher;
use App\Services\Notifier as N;
use App\Services\Workflow as W;
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

    public function create(Request $r)
    {
        return view('cases.form', ['c' => (object) [], 'action' => '/cases', 'submit' => '登録する', 'sesList' => $this->sesList($r), 'import' => true]);
    }

    public function import(Request $r)
    {
        $c = (object) (CaseParser::parse($r->input('raw_text')) + ['raw_text' => $r->input('raw_text')]);
        $found = $c->detected ?? [];
        session()->now('flash', ['msg' => '取り込みました。内容を確かめて、足りないところを直してから登録してください。'.($found ? '本文から見つかった技術：'.implode('、', $found).'（必須に無いものは尚可に入れました）' : ''), 'type' => 'ok']);
        return view('cases.form', ['c' => $c, 'action' => '/cases', 'submit' => '登録する', 'sesList' => $this->sesList($r), 'import' => false]);
    }

    public function store(Request $r)
    {
        if (!str_in($r->input('title'))) return $this->go('/cases/new', '案件名を入れてください。', 'bad');
        $id = W::saveCase($r->all(), $r->user());
        return $this->go("/cases/$id", $r->user()->role === 'admin' ? '登録しました。マッチングで、合うエンジニアに配信してください。' : '登録しました。続けて「エンジニアに案内する」で、合うエンジニアにメールで案内できます。');
    }

    private function load(int $id) { $c = DB::table('cases')->find($id); abort_unless($c, 404); return $c; }

    public function edit(Request $r, int $id)
    {
        $c = $this->load($id);
        abort_unless(Perms::caseEdit($r->user(), $c), 403);
        return view('cases.form', ['c' => $c, 'action' => "/cases/$id", 'submit' => '保存する', 'sesList' => $this->sesList($r), 'import' => false]);
    }

    public function update(Request $r, int $id)
    {
        $c = $this->load($id);
        abort_unless(Perms::caseEdit($r->user(), $c), 403);
        W::saveCase($r->all(), $r->user(), $id);
        return $this->go("/cases/$id", '保存しました。');
    }

    public function show(Request $r, int $id)
    {
        $u = $r->user();
        $c = DB::table('cases as c')->leftJoin('ses_companies as s', 's.id', '=', 'c.source_ses_id')->where('c.id', $id)->select('c.*', 's.name as ses_name')->first();
        abort_unless($c, 404);
        abort_unless(Perms::caseView($u, $c), 403);

        $apps = DB::table('applications as a')->join('engineers as e', 'e.id', '=', 'a.engineer_id')->join('ses_companies as s', 's.id', '=', 'a.ses_id')
            ->where('a.case_id', $id)->select('a.*', 'e.name as eg_name', 's.name as ses_name')->orderBy('a.id');
        if ($u->role === 'ses') $apps->where('a.ses_id', $u->ses_id);
        if ($u->role === 'eg') $apps->where('a.engineer_id', $u->engineer_id);
        $data = ['c' => $c, 'apps' => $apps->get(), 'canMail' => CM::canSend($u, $c)];
        if ($data['canMail']) {
            $q = DB::table('case_mails as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')->join('ses_companies as s', 's.id', '=', 'e.ses_id')
                ->where('m.case_id', $id)->select('m.*', 'e.name', 'e.initials', 's.name as ses_name')->orderByDesc('m.id');
            $data['mails'] = $q->get();
            $data['mailApps'] = DB::table('applications')->where('case_id', $id)->pluck('id', 'engineer_id')->all();
        }

        if ($u->role === 'admin') {
            $data += [
                'offers' => DB::table('case_offers as o')->join('engineers as e', 'e.id', '=', 'o.engineer_id')->where('o.case_id', $id)->select('o.*', 'e.name')->orderByDesc('o.score')->get(),
            ];
        } elseif ($u->role === 'ses') {
            $engs = DB::table('engineers')->where('ses_id', $u->ses_id)->where('status', '!=', 'unavailable')->orderBy('name')->get();
            $rows = $engs->map(fn ($e) => ['e' => $e] + Matcher::score($c, $e))->sortByDesc('score')->values()->all();
            $data += ['rows' => $rows, 'applied' => DB::table('applications')->where('case_id', $id)->pluck('engineer_id')->all(),
                'offered' => DB::table('case_offers')->where('case_id', $id)->pluck('status', 'engineer_id')->all()];
        } else {
            $offer = DB::table('case_offers')->where('case_id', $id)->where('engineer_id', $u->engineer_id)->first();
            if ($offer && $offer->status === 'sent') DB::table('case_offers')->where('id', $offer->id)->update(['status' => 'viewed', 'viewed_at' => now_str()]);
            $e = DB::table('engineers')->find($u->engineer_id);
            $data += ['offer' => $offer, 'myApp' => DB::table('applications')->where('case_id', $id)->where('engineer_id', $u->engineer_id)->first(), 'fit' => Matcher::score($c, $e)];
        }
        return view('cases.show', $data);
    }

    public function distribute(Request $r, int $id)
    {
        $c = $this->load($id);
        if ($c->status !== 'open') return $this->go("/cases/$id", '募集中の案件だけ配信できます。', 'bad');
        $ids = array_map('intval', (array) $r->input('engineer_id', []));
        if ($r->user()->role === 'ses') $ids = DB::table('engineers')->whereIn('id', $ids)->where('ses_id', $r->user()->ses_id)->pluck('id')->all();
        if (!$ids) return $this->go("/cases/$id", '配信するエンジニアを選んでください。', 'bad');
        $n = W::distribute($id, $ids, $r->user(), (string) $r->input('message'));
        return $this->go("/cases/$id", "{$n}名に配信しました（メールと通知）。");
    }

    // 持ち込んでいない SES が、自社のエンジニアを推薦する（持ち込んだ SES／管理者に、名前を伏せて知らせる）
    public function recommend(Request $r, int $id)
    {
        $u = $r->user();
        $c = $this->load($id);
        abort_unless($u->role === 'ses' && Perms::caseView($u, $c) && !CM::canSend($u, $c), 403);
        $e = DB::table('engineers')->where('id', (int) $r->input('engineer_id'))->where('ses_id', $u->ses_id)->first();
        abort_unless($e, 403);
        if ($c->status !== 'open') return $this->go("/cases/$id", '募集を終えた案件です。', 'bad');
        $key = "case {$id} engineer {$e->id}";
        if (DB::table('audit_log')->where('action', 'recommend')->where('detail', $key)->exists()) return $this->go("/cases/$id", 'すでに推薦しています。');
        $ses = DB::table('ses_companies')->where('id', $u->ses_id)->value('name');
        $s = Matcher::score($c, $e);
        $text = "{$ses} から、案件「{$c->title}」にエンジニアの推薦がありました。\n".($e->initials ?: '—')."（{$s['score']}点）。合う点：".(implode('、', array_slice($s['reasons'], 0, 4)) ?: '—')
            ."\n案内するときは、案件の「エンジニアに案内する」の「グループのほかの人」から選んでください。";
        if ($c->source_ses_id) N::toSes($c->source_ses_id, "エンジニアの推薦がありました：{$c->title}", $text, "/cases/$id/mail?scope=group", true, 'recommend');
        N::toAdmins("エンジニアの推薦がありました：{$c->title}", $text, "/cases/$id/mail", !$c->source_ses_id, 'recommend');
        N::audit($u->id, 'recommend', $key);
        return $this->go("/cases/$id", '推薦しました。持ち込んだ'.($c->source_ses_id ? ' SES' : '管理者').'に知らせました。');
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
