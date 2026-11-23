<?php

namespace App\Http\Controllers;

use App\Services\CaseMail as CM;
use App\Services\CaseThread;
use App\Services\Inbound;
use App\Services\ProviderReply as PR;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

// プロバイダーへの返信（v0.13.0）。
// 持ち込み元の SES・担当者：読む・直す・送る・取りやめる・作り直す。管理者：全社を読むだけ（操作は 403）。ほかの会社の SES・担当者は 404。エンジニアは入れない（ルートで 403）。
class ProviderMailController extends Controller
{
    private function load(int $id): object
    {
        $pm = DB::table('provider_mails')->find($id);
        abort_unless($pm, 404);
        return $pm;
    }

    // 見られない人には 404（あることも教えない）
    private function view(Request $r, int $id): object
    {
        $pm = $this->load($id);
        abort_unless(PR::canView($r->user(), $pm), 404);
        return $pm;
    }

    // 直せる人だけ。管理者は 403
    private function edit(Request $r, int $id): object
    {
        $pm = $this->view($r, $id);
        abort_unless(PR::canEdit($r->user(), $pm), 403);
        return $pm;
    }

    public function show(Request $r, int $id)
    {
        $u = $r->user();
        $pm = $this->view($r, $id);
        $case = DB::table('cases')->find($pm->case_id);
        $e = $pm->engineer_id ? DB::table('engineers')->find($pm->engineer_id) : null;
        $pv = $case && $case->provider_id ? DB::table('providers')->find($case->provider_id) : null;
        $canEdit = PR::canEdit($u, $pm);
        $cm = $pm->case_mail_id ? DB::table('case_mails')->find($pm->case_mail_id) : null;
        $outbox = $pm->outbox_id ? DB::table('mail_outbox')->find($pm->outbox_id) : null;
        $inboundN = DB::table('inbound_mails')->where('provider_mail_id', $pm->id)->where('status', 'linked')->count();
        return view('provider-mails.show', [
            'pm' => $pm, 'c' => $case, 'pv' => $pv, 'e' => $e, 'cm' => $cm, 'canEdit' => $canEdit, 'isAdmin' => $u->role === 'admin',
            // 画面に出す エンジニアの名前：自社は氏名、他社の SES・担当者は「イニシャル（会社名）」、管理者は氏名
            'egLabel' => $e ? CaseThread::egName($u, $e) : 'エンジニア', 'initials' => ($e->initials ?? null) ?: 'エンジニア',
            'ownEg' => $e && ($u->role !== 'ses' || (int) $e->ses_id === (int) $u->ses_id),
            'leaks' => $canEdit && $pm->status === 'draft' && $e ? PR::leaks($e, $pm->subject."\n".$pm->body) : [],
            'inboundLink' => $pm->inbound_mail_id && DB::table('inbound_mails')->where('id', $pm->inbound_mail_id)->where('status', 'linked')->exists(),
            'sentBy' => $pm->sent_by ? DB::table('users')->where('id', $pm->sent_by)->value('name') : null,
            'outbox' => $outbox, 'inboundN' => $inboundN, 'intents' => collect(CM::REPLIES)->only(array_keys(PR::INTENTS))->all(),
        ]);
    }

    public function save(Request $r, int $id)
    {
        $pm = $this->edit($r, $id);
        $res = PR::save($r->user(), $pm->id, (string) $r->input('reply'), (string) $r->input('subject'), (string) $r->input('body'));
        return $this->go("/provider-mails/$id", $res['msg'], $res['type']);
    }

    public function send(Request $r, int $id)
    {
        $pm = $this->edit($r, $id);
        // 画面の内容を保存してから送る（保存し忘れても、見えている文が送られる）
        if ($r->has('subject')) {
            $s = PR::save($r->user(), $pm->id, (string) $r->input('reply', $pm->reply), (string) $r->input('subject'), (string) $r->input('body'));
            if ($s['type'] !== 'ok') return $this->go("/provider-mails/$id", $s['msg'], $s['type']);
        }
        $res = PR::send($r->user(), $pm->id, (bool) $r->input('force'));
        return $this->go("/provider-mails/$id", $res['msg'], $res['type']);
    }

    public function redraft(Request $r, int $id)
    {
        $pm = $this->edit($r, $id);
        $res = PR::redraft($r->user(), $pm->id);
        return $this->go("/provider-mails/$id", $res['msg'], $res['type']);
    }

    public function discard(Request $r, int $id)
    {
        $pm = $this->edit($r, $id);
        $res = PR::discard($r->user(), $pm->id, str_in(mb_substr((string) $r->input('note'), 0, 500)));
        return $this->go("/provider-mails/$id", $res['msg'], $res['type']);
    }

    // 受信メール（エンジニアの返信）から下書きを作る。見える人（持ち込み元の担当者）だけ。すでにあれば、それを開く
    public function fromInbound(Request $r, int $id)
    {
        $u = $r->user();
        $row = Inbound::visible($u, $id);
        abort_unless($row, 404);
        abort_unless($u->role === 'ses', 403);
        $existing = DB::table('provider_mails')->where('inbound_mail_id', $id)->value('id');
        if (!$existing) {
            if (($row->sender_kind ?? 'eg') !== 'eg' || $row->status !== 'linked') return $this->go("/inbound/$id", 'エンジニアの返信だけ、下書きを作れます。', 'bad');
            $existing = PR::draftFromInbound($id);
        }
        if (!$existing) return $this->go("/inbound/$id", '下書きを作れませんでした（担当者からの返信・自動返信・登録と違うアドレスからの返信などは作りません）。', 'bad');
        return $this->go("/provider-mails/$existing");
    }

    // 案内メールの行から下書きを作る（受信メールが無いとき）
    public function fromCaseMail(Request $r, int $id)
    {
        $m = DB::table('case_mails')->find($id);
        abort_unless($m, 404);
        $c = DB::table('cases')->find($m->case_id);
        abort_unless($c && CM::canSend($r->user(), $c), 403);
        // パートナー経由の分は、パートナーが提案を頼んだ人（handoff_at がある人）だけ、プロバイダーへ返せる（v0.17.0）
        abort_if($m->mode === \App\Services\CaseSearch::MODE_PARTNER && !$m->handoff_at, 403);
        $pid = PR::draftFromCaseMail($id, $r->user());
        return $this->go("/provider-mails/$pid");
    }
}
