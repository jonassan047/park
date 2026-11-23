<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// パートナー側の追跡（v0.17.0）：受注 SES ではない会社が、自社から案内したエンジニアを、最後（成約／不成約）まで追いかけて記録する。
// 案内を送った会社の記録なので、権限は「mode=partner で、送った会社＝自社」の行（case_mails.ses_id）。管理者は読むだけ（押せない）。
// プロバイダーとのやり取りは受注 SES が行う。パートナーは［受注 SES に提案を頼む］で頼み、結果は受注 SES から届く。
// プロバイダーの名前・原文・金額・連絡先は、ここのどこにも出さない。
class PartnerTrack
{
    const MAX_NOTE = 500;
    const OUTCOMES = ['won' => '成約', 'lost' => '不成約'];
    const KIND_HANDOFF = 'partner-handoff';
    const KIND_WON = 'partner-won';
    const KIND_LOST = 'partner-lost';

    // 追いかけられる人：SES・担当者で、パートナーの案内（mode=partner）を送った会社の人。会社の誰が開いても同じ
    public static function canTrack($u, object $m): bool
    {
        return $u && $u->role === 'ses' && ($m->mode ?? null) === CaseSearch::MODE_PARTNER && (int) $m->ses_id === (int) $u->ses_id;
    }

    // この案件で、自社から案内した人の行（新しい順）。氏名・EG-・返信メールの件数・やり取りの可否・提案の依頼・先方の結果・最後の結果
    public static function rows($u, object $c): Collection
    {
        if (!$u || $u->role !== 'ses' || !$u->ses_id) return collect();
        $list = DB::table('case_mails as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')
            ->where('m.case_id', $c->id)->where('m.mode', CaseSearch::MODE_PARTNER)->where('m.ses_id', (int) $u->ses_id)
            ->select('m.*', 'e.name', 'e.initials', 'e.code as eg_code', 'e.ses_id as eg_ses_id')->orderByDesc('m.id')->get();
        if ($list->isEmpty()) return $list;
        // 返信メールの件数（エンジニアの返信だけ。プロバイダーの返事は数えない）
        $n = DB::table('inbound_mails')->whereIn('case_mail_id', $list->pluck('id'))->where('status', 'linked')
            ->where(fn ($w) => $w->whereNull('sender_kind')->orWhere('sender_kind', 'eg'))->selectRaw('case_mail_id, count(*) as n')->groupBy('case_mail_id')->pluck('n', 'case_mail_id');
        return $list->map(function ($m) use ($u, $c, $n) {
            $m->inboundN = (int) ($n[$m->id] ?? 0);
            $m->canThread = CaseThread::canStart($u, $c, (object) ['id' => $m->engineer_id, 'ses_id' => $m->eg_ses_id], true);
            return $m;
        });
    }

    // 最後の結果が未記録の人の数
    public static function openCount(Collection $rows): int
    {
        return $rows->whereNull('outcome')->count();
    }

    // 提案の依頼・成約の報告を受け取る人：この案件で自社あての紹介の依頼（送信済み）の新しい1件の「送った人」が使える担当者ならその人、違えば受注 SES の SES。いなければ null
    public static function receiver(object $c, int $partnerSesId): ?object
    {
        $source = (int) $c->source_ses_id;
        $sentBy = DB::table('case_search_staff')->where('case_id', $c->id)->where('partner_ses_id', $partnerSesId)->whereNotNull('sent_at')->orderByDesc('id')->value('sent_by');
        if ($sentBy && EgStaff::usable($x = DB::table('users')->find($sentBy), $source)) return $x;
        return $source ? EgStaff::rep($source) : null;
    }

    // 受注 SES に、提案を頼む。戻り：['type' => 'ok'|'bad'|'warn', 'msg' => 文]。呼ぶ側で canTrack を確かめる
    public static function handoff($u, int $mid, ?string $note): array
    {
        $m = DB::table('case_mails')->find($mid);
        $c = $m ? DB::table('cases')->find($m->case_id) : null;
        if (!$m || !$c) return ['type' => 'bad', 'msg' => '案内が見つかりません。'];
        if ($m->handoff_at) return ['type' => 'warn', 'msg' => 'すでに頼んでいます。'];
        if ($c->status !== 'open') return ['type' => 'bad', 'msg' => '募集を終えた案件では、提案を頼めません。'];
        if ($m->reply !== 'interested') return ['type' => 'bad', 'msg' => '返事が「応募する」の人だけ、提案を頼めます。先に返事を記録してください。'];
        $note = str_in($note);
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) return ['type' => 'bad', 'msg' => 'ひとことは '.self::MAX_NOTE.' 字までです。'];
        // 先に印を付ける。0行なら、同時に押された（すでに頼んでいる）ので、お知らせは出さない
        $claimed = DB::table('case_mails')->where('id', $mid)->whereNull('handoff_at')->update(['handoff_at' => now_str(), 'handoff_by' => $u->id, 'handoff_note' => $note]);
        if (!$claimed) return ['type' => 'warn', 'msg' => 'すでに頼んでいます。'];
        N::audit($u->id, 'partner-handoff', ($c->code ?? $c->id).' '.(DB::table('engineers')->where('id', $m->engineer_id)->value('code') ?? '')); // 案件コードと EG コードだけ
        if ($to = self::receiver($c, (int) $m->ses_id)) {
            $e = DB::table('engineers')->find($m->engineer_id);
            $company = (string) DB::table('ses_companies')->where('id', $m->ses_id)->value('name');
            N::notify((int) $to->id, '提案の依頼：'.$c->title.Ids::tag($c->code ?? null),
                "{$company} から、".(($e->initials ?? null) ?: 'エンジニア')." さんの提案の依頼が届きました。案件の画面の「担当者への依頼」から、プロバイダーへの返信を作ってください。".($note ? "\nひとこと：".$note : ''),
                "/cases/{$c->id}#staffreq", true, self::KIND_HANDOFF, 'ses');
        } else {
            // 受け取る人がいない（受注 SES が使えないなど）：頼んだ印は付いているので、知らせが届かなかったことを伝える
            return ['type' => 'warn', 'msg' => '受注 SES に知らせが届きませんでした。管理者にご連絡ください。'];
        }
        return ['type' => 'ok', 'msg' => '受注 SES に提案を頼みました。'];
    }

    // 最後の結果（成約／不成約）を記録する。直せる。戻り：['type' => 'ok'|'bad'|'warn', 'msg' => 文]。呼ぶ側で canTrack を確かめる
    public static function outcome($u, int $mid, string $outcome, ?string $note): array
    {
        $m = DB::table('case_mails')->find($mid);
        $c = $m ? DB::table('cases')->find($m->case_id) : null;
        if (!$m || !$c) return ['type' => 'bad', 'msg' => '案内が見つかりません。'];
        if (!isset(self::OUTCOMES[$outcome])) return ['type' => 'bad', 'msg' => '結果を選んでください。'];
        $note = str_in($note);
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) return ['type' => 'bad', 'msg' => 'メモは '.self::MAX_NOTE.' 字までです。'];
        if ($m->outcome === $outcome && (string) $m->outcome_note === (string) $note) return ['type' => 'warn', 'msg' => '変わっていません。'];

        $upd = ['outcome' => $outcome, 'outcome_note' => $note, 'outcome_by' => $u->id, 'outcome_at' => now_str()];
        $q = DB::table('case_mails')->where('id', $mid);
        // 成約に変わるときだけ、報告を出す。同時に2回押されても1回にするため、「成約でない」ことを条件にして更新する
        if ($outcome === 'won' && $m->outcome !== 'won') {
            if (!$q->where(fn ($w) => $w->whereNull('outcome')->orWhere('outcome', '!=', 'won'))->update($upd)) return ['type' => 'warn', 'msg' => '変わっていません。'];
        } else {
            $q->update($upd);
        }
        N::audit($u->id, 'partner-outcome', ($c->code ?? $c->id).' '.(DB::table('engineers')->where('id', $m->engineer_id)->value('code') ?? '').' → '.self::OUTCOMES[$outcome]); // メモは書かない

        if ($m->outcome !== $outcome && ($to = self::receiver($c, (int) $m->ses_id))) {
            $e = DB::table('engineers')->find($m->engineer_id);
            $company = (string) DB::table('ses_companies')->where('id', $m->ses_id)->value('name');
            $who = (($e->initials ?? null) ?: 'エンジニア');
            if ($outcome === 'won') {
                N::notify((int) $to->id, '成約の報告：'.$c->title.Ids::tag($c->code ?? null), "{$company} の {$who} さんが成約しました。", "/cases/{$c->id}#staffreq", true, self::KIND_WON, 'ses');
            } else {
                N::notify((int) $to->id, '不成約の連絡：'.$c->title.Ids::tag($c->code ?? null), "{$company} の {$who} さんは、不成約となりました。", "/cases/{$c->id}#staffreq", false, self::KIND_LOST, 'ses');
            }
        }
        return ['type' => 'ok', 'msg' => '最後の結果を記録しました。'];
    }

    // 受注 SES の側：この案件で、パートナーから提案を頼まれた人（イニシャル・企業名［担当者名］・頼まれた日・ひとこと・プロバイダーへの返信・先方の結果・最後の結果）。
    // 返信の本文・EG の氏名は出さない。見せてよい人（受注 SES の SES・担当者）かどうかは、呼ぶ側で確かめる
    public static function handoffRows(object $c): Collection
    {
        $q = DB::table('case_mails as m')->join('engineers as e', 'e.id', '=', 'm.engineer_id')->join('ses_companies as p', 'p.id', '=', 'm.ses_id')
            ->leftJoin('users as hu', 'hu.id', '=', 'm.handoff_by')
            ->where('m.case_id', $c->id)->where('m.mode', CaseSearch::MODE_PARTNER)->whereNotNull('m.handoff_at')
            ->select('m.id', 'm.handoff_at', 'm.handoff_note', 'm.result', 'm.result_note', 'm.result_at', 'm.outcome', 'm.outcome_at', 'm.reply', 'e.initials', 'p.name as partner_name', 'hu.name as staff_name')
            ->orderByDesc('m.handoff_at')->orderByDesc('m.id');
        return $q->get();
    }
}
