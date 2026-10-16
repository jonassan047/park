<?php

namespace App\Http\Controllers;

use App\Services\CaseMail as CM;
use App\Services\EngineerDocs;
use App\Services\Matcher;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// 案件の案内メール：候補を絞る → メールを作る → 送る前の確かめ（提案）→ 送る → 返事の記録 → 書類のダウンロード
class CaseMailController extends Controller
{
    private function load(Request $r, int $id)
    {
        $c = DB::table('cases')->find($id);
        abort_unless($c, 404);
        abort_unless(CM::canSend($r->user(), $c), 403);
        return $c;
    }

    private function scope(Request $r): string
    {
        return $r->user()->role === 'admin' ? 'all' : ($r->input('scope') === 'group' ? 'group' : 'own');
    }

    // 2. 候補（DB の得意分野から提案。チェックで足す・外す）と、3. メールの中身
    public function form(Request $r, int $id)
    {
        $c = $this->load($r, $id);
        if ($c->status !== 'open') return $this->go("/cases/$id", '募集中の案件だけ案内できます。', 'bad');
        $scope = $this->scope($r);
        return view('cases.mail', ['c' => $c, 'scope' => $scope, 'th' => Matcher::threshold(), 'cands' => CM::candidates($r->user(), $c, $scope),
            'subject' => CM::defaultSubject($c), 'body' => CM::defaultBody()]);
    }

    // 4. 送る前の確かめ：一人ずつの本文と、提案を出す。個別のときは、ここで一人ずつ直せる
    public function preview(Request $r, int $id)
    {
        $c = $this->load($r, $id);
        $u = $r->user();
        $scope = $this->scope($r);
        $allowed = collect(CM::candidates($u, $c, $scope))->keyBy(fn ($m) => $m['engineer']->id);
        $ids = array_values(array_filter(array_map('intval', (array) $r->input('engineer_id', [])), fn ($i) => $allowed->has($i) && !$allowed[$i]['sent']));
        if (!$ids) return back()->withInput()->with('flash', ['msg' => '送る相手を選んでください。', 'type' => 'bad']);
        $mode = $r->input('mode') === 'each' ? 'each' : 'same';
        $from = $u->role === 'ses' ? DB::table('ses_companies')->where('id', $u->ses_id)->value('name').' '.$u->name : setting('operator_name');
        $rows = [];
        foreach ($ids as $eid) {
            $m = $allowed[$eid];
            $rows[] = ['m' => $m, 'name' => $m['own'] ? $m['engineer']->name : ($m['engineer']->initials ?: '—').'（'.$m['engineer']->ses_name.'）',
                'subject' => (string) $r->input('subject'), 'body' => CM::render((string) $r->input('body'), $c, $m['engineer'], $m, $m['own'], $from),
                'suggest' => CM::suggestions($c, $m['engineer'], $m)];
        }
        return view('cases.mail-preview', ['c' => $c, 'scope' => $scope, 'mode' => $mode, 'rows' => $rows, 'canSend' => N::canSend()]);
    }

    public function send(Request $r, int $id)
    {
        $c = $this->load($r, $id);
        $u = $r->user();
        $allowed = collect(CM::candidates($u, $c, $this->scope($r)))->keyBy(fn ($m) => $m['engineer']->id);
        $items = [];
        foreach ((array) $r->input('to', []) as $eid => $it) {
            if (!$allowed->has((int) $eid) || empty($it['send'])) continue;
            $items[(int) $eid] = ['subject' => (string) ($it['subject'] ?? ''), 'body' => (string) ($it['body'] ?? '')];
        }
        if (!$items) return $this->go("/cases/$id/mail", '送る相手がいません。', 'bad');
        $n = CM::send($u, $c, $items, $r->input('mode') === 'each' ? 'each' : 'same');
        return $this->go("/cases/$id", "{$n}名に案内メールを送りました。返事が来たら、下の「案内メール」に記録してください。");
    }

    // 5. 返事の記録（SES に直接来た返信を入れる）
    public function reply(Request $r, int $mid)
    {
        $m = DB::table('case_mails')->find($mid);
        abort_unless($m, 404);
        $c = $this->load($r, $m->case_id);
        CM::reply($c->id, $m->engineer_id, (string) $r->input('reply'), str_in($r->input('note')), 'ses');
        N::audit($r->user()->id, 'case-mail-reply', "#$mid ".$r->input('reply'));
        return $this->go("/cases/{$c->id}#mails", '返事を記録しました。');
    }

    // 6 のあと：案件をくれた先へ書類を返した（応募した）ことを記録する。ここから面談依頼・成約へ進む
    public function submitted(Request $r, int $mid)
    {
        $m = DB::table('case_mails')->find($mid);
        abort_unless($m, 404);
        $c = $this->load($r, $m->case_id);
        abort_unless($m->route === 'own' || $r->user()->role === 'admin', 403);
        try {
            $appId = \App\Services\Workflow::apply($c->id, $m->engineer_id, ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1, 'condition_note' => $m->reply_note ?: 'SES が本人に確認のうえ応募'], $r->user());
            \App\Services\Workflow::setStatus($appId, 'submitted', $r->user(), '', '案件をくれた先へ書類を返した');
            CM::reply($c->id, $m->engineer_id, 'interested', $m->reply_note, $m->reply_via ?: 'ses');
            return $this->go("/applications/$appId", '応募したことを記録しました。面談依頼が来たら、この画面の「面談依頼が来た」に入れてください。');
        } catch (\RuntimeException $e) {
            return $this->go("/cases/{$c->id}#mails", $e->getMessage(), 'bad');
        }
    }

    // 6. 案件をくれた先へ返すための書類（最新の履歴書・業務経歴書）。グループのほかの SES の人は、名前を伏せた業務経歴書だけ
    public function doc(Request $r, int $mid, string $kind)
    {
        $m = DB::table('case_mails')->find($mid);
        abort_unless($m, 404);
        $this->load($r, $m->case_id);
        abort_unless(in_array($kind, ['resume', 'careersheet', 'careersheet-anon'], true), 404);
        if ($m->route === 'group') {
            abort_unless($kind === 'careersheet-anon' && $m->reply === 'interested', 403);
        }
        $fid = EngineerDocs::generate($m->engineer_id, $kind === 'resume' ? 'resume' : 'careersheet', $r->user(), $kind === 'careersheet-anon');
        $f = DB::table('engineer_files')->find($fid);
        N::audit($r->user()->id, 'doc-download', $f->filename);
        return Storage::disk(EngineerDocs::DISK)->download($f->path, $f->filename);
    }
}
