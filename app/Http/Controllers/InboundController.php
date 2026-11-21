<?php

namespace App\Http\Controllers;

use App\Services\Ids;
use App\Services\Inbound;
use App\Services\MailFetch;
use App\Services\Notifier as N;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// 案内メールへの返信メール（v0.12.0）。
// SES・担当者：自社が提案元 SES の案件への返信だけを読む（見える範囲は Inbound::query が決める。見られない人には 404）。
// 管理者：受信したメールをすべて読み、紐付かなかったものを手で紐付ける。EG は入れない（ルートで 403）。
class InboundController extends Controller
{
    const TABS = ['unlinked' => '未紐付け', 'linked' => '紐付け済み', 'ignored' => '対象外', 'all' => 'すべて'];

    // SES・担当者の一覧（管理者は管理者の一覧へ）
    public function index(Request $r)
    {
        $u = $r->user();
        if ($u->role === 'admin') return redirect('/admin/inbound');
        $q = Inbound::query($u)->orderByDesc('i.received_at')->orderByDesc('i.id');
        if ($case = (int) $r->query('case')) $q->where('i.case_id', $case);
        if ($eg = (int) $r->query('eg')) $q->where('i.engineer_id', $eg);
        if ($pm = (int) $r->query('pm')) $q->where('i.provider_mail_id', $pm); // プロバイダーへ送ったメールへの返事（v0.13.0）
        if ($x = (int) $r->query('extra')) $q->where('i.search_extra_id', $x); // 追加の宛先からの返信（v0.15.0）
        [$list, $pager] = page_slice($q->get());
        return view('inbound.index', compact('list', 'pager'));
    }

    // 管理者の一覧
    public function admin(Request $r)
    {
        $u = $r->user();
        $tab = array_key_exists((string) $r->query('tab'), self::TABS) ? (string) $r->query('tab') : 'unlinked';
        $counts = DB::table('inbound_mails')->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status')->all();
        $counts['all'] = array_sum($counts);
        $q = Inbound::query($u)->orderByDesc('i.received_at')->orderByDesc('i.id');
        if ($tab !== 'all') $q->where('i.status', $tab);
        if ($pm = (int) $r->query('pm')) $q->where('i.provider_mail_id', $pm);
        [$list, $pager] = page_slice($q->get());
        return view('admin.inbound', ['list' => $list, 'pager' => $pager, 'tab' => $tab, 'tabs' => self::TABS, 'counts' => $counts,
            'enabled' => Inbound::enabled(), 'lastFetch' => DB::table('settings')->where('key', 'inbound_last_fetch_at')->value('value')]);
    }

    // 詳細（SES・担当者・管理者で共通。管理者だけの欄は画面で分ける）
    public function show(Request $r, int $id)
    {
        $u = $r->user();
        $row = Inbound::visible($u, $id);
        abort_unless($row, 404);
        Inbound::markRead($u, $row);
        $data = ['m' => Inbound::masked($u, $row), 'isAdmin' => $u->role === 'admin', 'c' => $row->case_id ? DB::table('cases')->find($row->case_id) : null,
            'cm' => $row->case_mail_id ? DB::table('case_mails')->find($row->case_mail_id) : null, 'search' => null, 'sourceName' => null, 'notifyName' => null];
        // プロバイダーへの返信（v0.13.0）：EG の返信ならその下書き、プロバイダーの返事なら送ったメール。結果の登録は持ち込み元の担当者だけ
        $isProv = ($row->sender_kind ?? 'eg') === 'provider';
        $data['pm'] = $isProv ? ($row->provider_mail_id ? DB::table('provider_mails')->find($row->provider_mail_id) : null)
            : DB::table('provider_mails')->where('inbound_mail_id', $id)->first();
        $data['isProvider'] = $isProv;
        $data['canResult'] = $row->status === 'linked' && $data['c'] && \App\Services\CaseMail::canSend($u, $data['c']);
        $data['providerName'] = $isProv ? ($row->provider_name ?: null) : null;
        if ($u->role === 'admin' && $row->status === 'linked') {
            // 見える会社（案件の今の提案元 SES）と、知らせた会社（案内メールを送った会社）。違うことがある
            $data['sourceName'] = $row->case_source ? DB::table('ses_companies')->where('id', $row->case_source)->value('name') : null;
            $data['notifyName'] = $row->ses_id && (int) $row->ses_id !== (int) $row->case_source ? $row->ses_name : null;
        }
        if ($u->role === 'admin' && $row->status === 'unlinked') $data['search'] = $this->search($r);
        return view('inbound.show', $data);
    }

    // 管理者が、案件 ID（CA-）から案内メールを探す。選んだ案内メールがあれば、確認の画面用に会社名も付ける
    private function search(Request $r): ?array
    {
        $raw = trim((string) $r->query('code'));
        if ($raw === '') return null;
        $code = Ids::normalize($raw);
        $case = $code && Ids::kindOf($code) === 'C' ? DB::table('cases')->where('code', $code)->first() : null;
        if (!$case) return ['code' => $raw, 'case' => null, 'mails' => collect(), 'pick' => null];
        $mails = DB::table('case_mails as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')->leftJoin('ses_companies as s', 's.id', '=', 'e.ses_id')
            ->where('m.case_id', $case->id)->select('m.id', 'm.sent_at', 'm.ses_id', 'e.name as eg_name', 'e.code as eg_code', 's.name as eg_ses_name')->orderByDesc('m.id')->get();
        $pick = ($pid = (int) $r->query('pick')) ? $mails->firstWhere('id', $pid) : null;
        $sourceName = $case->source_ses_id ? DB::table('ses_companies')->where('id', $case->source_ses_id)->value('name') : null;
        // 知らせが届く会社は、選んだ案内メールを送った会社（見える会社と違うことがある）
        $notifyId = $pick ? ($pick->ses_id ?: $case->source_ses_id) : null;
        $notifyName = $notifyId && (int) $notifyId !== (int) $case->source_ses_id ? DB::table('ses_companies')->where('id', $notifyId)->value('name') : null;
        return ['code' => $raw, 'case' => $case, 'mails' => $mails, 'pick' => $pick, 'sourceName' => $sourceName, 'notifyName' => $notifyName];
    }

    // 元のメール（.eml）のダウンロード（管理者だけ）
    public function raw(Request $r, int $id)
    {
        $m = DB::table('inbound_mails')->find($id);
        abort_unless($m && $m->raw_path && Storage::disk('local')->exists($m->raw_path), 404);
        N::audit($r->user()->id, 'inbound-raw', "#$id");
        return Storage::disk('local')->download($m->raw_path, "inbound-{$id}.eml");
    }

    public function link(Request $r, int $id)
    {
        abort_unless(DB::table('inbound_mails')->where('id', $id)->exists(), 404);
        $res = Inbound::link($r->user(), $id, (int) $r->input('case_mail_id'), ((int) $r->input('case_id')) ?: null);
        return $this->go("/inbound/$id", $res['msg'], $res['type']);
    }

    public function unlink(Request $r, int $id)
    {
        abort_unless(DB::table('inbound_mails')->where('id', $id)->exists(), 404);
        $res = Inbound::unlink($r->user(), $id);
        return $this->go("/inbound/$id", $res['msg'], $res['type']);
    }

    public function ignore(Request $r, int $id)
    {
        abort_unless(DB::table('inbound_mails')->where('id', $id)->exists(), 404);
        $res = Inbound::ignore($r->user(), $id, str_in(mb_substr((string) $r->input('note'), 0, 500)));
        return $this->go("/inbound/$id", $res['msg'], $res['type']);
    }

    public function restore(Request $r, int $id)
    {
        abort_unless(DB::table('inbound_mails')->where('id', $id)->exists(), 404);
        $res = Inbound::restore($r->user(), $id);
        return $this->go("/inbound/$id", $res['msg'], $res['type']);
    }

    // ［今すぐ取り込む］。受信の設定が無ければ、何もしない
    public function fetch(Request $r)
    {
        if (!Inbound::enabled()) return $this->go('/admin/inbound', '受信の設定がまだです（.env）。', 'warn');
        // 画面からは、時間切れ（max_execution_time）にならないよう通数を減らし、待てる時間も延ばす。残りは次の取り込みで読む
        @set_time_limit(120);
        $res = MailFetch::run(null, false, MailFetch::SCREEN_LIMIT);
        return $this->go('/admin/inbound', $res['msg'], $res['ok'] ? 'ok' : 'bad');
    }
}
