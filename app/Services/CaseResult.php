<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Facades\DB;

// 案件×エンジニア（案内メールの行）の、先方の結果（合否）の登録（v0.13.0。R17 の最小）。
// 登録できるのは持ち込み元の担当者だけ（権限は呼ぶ側で CaseMail::canSend を確かめる）。管理者は読むだけ。
// 合格・不合格のときだけ、エンジニア本人（他社の人ならその会社の担当者にも）にお知らせを出す。メモは出さない。メールは送らない。
class CaseResult
{
    const KIND = 'case-result';
    const RESULTS = ['pending' => '結果待ち', 'passed' => '合格', 'failed' => '不合格'];
    const MAX_NOTE = 1000;

    // 戻り：['type' => 'ok'|'bad'|'warn', 'msg' => 文]
    public static function set($u, int $caseMailId, string $result, ?string $note): array
    {
        $cm = DB::table('case_mails')->find($caseMailId);
        if (!$cm) return ['type' => 'bad', 'msg' => '案内メールが見つかりません。'];
        if (!isset(self::RESULTS[$result])) return ['type' => 'bad', 'msg' => '結果を選んでください。'];
        $note = str_in($note);
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE) return ['type' => 'bad', 'msg' => 'メモは '.self::MAX_NOTE.' 字までです。'];
        if ($cm->result === $result && (string) $cm->result_note === (string) $note) return ['type' => 'warn', 'msg' => '変わっていません。'];

        $case = DB::table('cases')->find($cm->case_id);
        $e = DB::table('engineers')->find($cm->engineer_id);
        $changedResult = $cm->result !== $result; // メモだけ直したときは、お知らせを出さない
        DB::table('case_mails')->where('id', $cm->id)->update(['result' => $result, 'result_note' => $note, 'result_by' => $u->id, 'result_at' => now_str()]);
        N::audit($u->id, 'case-result', ($case->code ?? "案件#{$cm->case_id}").' '.($e->code ?? "EG#{$cm->engineer_id}").' → '.self::RESULTS[$result]); // メモは書かない

        if ($result === 'pending' || !$changedResult) return ['type' => 'ok', 'msg' => '登録しました。'];
        $label = self::RESULTS[$result];
        // 応募があれば、記録に 1 行足す（応募の状態は変えない。メモは入れない）
        $appId = DB::table('applications')->where('case_id', $cm->case_id)->where('engineer_id', $cm->engineer_id)->value('id');
        if ($appId) Workflow::addEvent((int) $appId, 'note', "先方の結果：{$label}（担当者が登録）", $u);

        $tail = '：'.($case->title ?? '案件').Ids::tag($case->code ?? null);
        $link = '/cases/'.$cm->case_id;
        if ($e) {
            [$title, $text] = $result === 'passed'
                ? ['先方から合格の連絡がありました'.$tail, '担当者から、次のご連絡をお待ちください。']
                : ['今回は見送りとなりました'.$tail, '次の案件に活かします。'];
            N::toEg((int) $e->id, $title, $text, $link, false, self::KIND);
            if ((int) $e->ses_id !== (int) $u->ses_id) {
                // パートナーが案内した人の結果は、パートナーの追跡の欄へ（v0.17.0）
                $toLink = $cm->mode === CaseSearch::MODE_PARTNER ? $link.'#partner-mails' : $link;
                N::toSes((int) ($cm->mode === CaseSearch::MODE_PARTNER && $cm->ses_id ? $cm->ses_id : $e->ses_id), 'グループの案件の結果'.$tail, "{$e->name} さん：{$label}", $toLink, false, self::KIND);
            }
        }
        return ['type' => 'ok', 'msg' => '登録しました。'];
    }
}
