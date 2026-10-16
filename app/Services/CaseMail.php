<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Support\Facades\DB;

// SES が受けた案件を、エンジニアにメールで案内する（2026-10-16 tezzo の流れ）
// 1. 案件を登録 → 2. 候補を絞る（DB の得意分野から提案。SES が足す・外す）→ 3. メールを作る（同じ内容／個別）
// → 4. 送る（送る前に提案を出す）→ 5. 返事を記録 → 6. 書類を付けて、案件をくれた先に返す
class CaseMail
{
    const REPLIES = ['none' => '返事なし', 'interested' => '応募したい', 'question' => '質問あり', 'declined' => '見送り'];
    const PLACEHOLDERS = ['{呼び名}', '{案件名}', '{単価}', '{開始}', '{場所}', '{働き方}', '{必須}', '{合う点}', '{送り主}'];

    // 誰が案内できるか：運営と、案件を持ち込んだ SES
    public static function canSend($u, $c): bool
    {
        return $u->role === 'admin' || ($u->role === 'ses' && (int) $c->source_ses_id === (int) $u->ses_id);
    }

    // 候補：own＝自社のエンジニア（名前が見える。SES から直接送る）、group＝グループのほかの SES の人（名前は伏せる。PARK から送る）
    public static function candidates($u, object $c, string $scope): array
    {
        $list = Matcher::forCase($c->id, true);
        $sesId = $u->role === 'ses' ? (int) $u->ses_id : null;
        $sent = DB::table('case_mails')->where('case_id', $c->id)->pluck('reply', 'engineer_id')->all();
        $out = [];
        foreach ($list as $m) {
            $e = $m['engineer'];
            $own = $sesId === null || (int) $e->ses_id === $sesId;
            if ($scope === 'own' && !$own) continue;
            if ($scope === 'group' && $own) continue;
            if ($e->status === 'unavailable') continue;
            $out[] = $m + ['own' => $own, 'sent' => $sent[$e->id] ?? null, 'past' => self::pastReplies($e->id)];
        }
        return $out;
    }

    // これまでの案内への返事（絞り込みを良くするための材料）
    public static function pastReplies(int $engineerId): array
    {
        $rows = DB::table('case_mails')->where('engineer_id', $engineerId)->get();
        $declines = DB::table('case_offers')->where('engineer_id', $engineerId)->whereNotNull('decline_reason')->pluck('decline_reason')->all();
        return ['sent' => $rows->count(), 'interested' => $rows->where('reply', 'interested')->count(), 'declined' => $rows->where('reply', 'declined')->count(),
            'reasons' => array_values(array_unique(array_merge($declines, $rows->where('reply', 'declined')->pluck('reply_note')->filter()->all())))];
    }

    public static function callName(object $e, bool $own): string
    {
        return $own ? ($e->nickname ?: $e->name) : (($e->nickname ?: ($e->initials ?: 'エンジニア')).'');
    }

    public static function defaultSubject(object $c): string
    {
        return "【案件のご案内】{$c->title}";
    }

    public static function defaultBody(): string
    {
        return "{呼び名} さん\n\nお疲れさまです。{送り主}です。\nあなたに合いそうな案件が届いたので、ご案内します。\n\n■案件：{案件名}\n■単価：{単価}\n■開始：{開始}\n■場所・働き方：{場所}／{働き方}\n■必須：{必須}\n■あなたに合う点：{合う点}\n\nご興味があれば、このメールに返信してください（条件で気になる点があれば、それも一緒に）。\n見送る場合も、理由をひとこと返信いただけると、次のご案内の参考にします。";
    }

    public static function render(string $tpl, object $c, object $e, array $score, bool $own, string $from): string
    {
        return strtr($tpl, [
            '{呼び名}' => self::callName($e, $own), '{案件名}' => $c->title, '{単価}' => $c->price ? man($c->price) : '相談',
            '{開始}' => $c->start_date ? fmt_date($c->start_date) : '相談', '{場所}' => $c->location ?: '—', '{働き方}' => lbl('workStyle', $c->work_style),
            '{必須}' => implode('、', parse_list($c->required_skills)) ?: '—', '{合う点}' => implode('、', array_slice($score['reasons'], 0, 4)) ?: '—', '{送り主}' => $from,
        ]);
    }

    // 送る前の提案（送り過ぎ・条件のずれ・ほかの候補）
    public static function suggestions(object $c, object $e, array $score): array
    {
        $s = [];
        if ($score['score'] < Matcher::threshold()) $s[] = "点数が低め（{$score['score']}点）。送らないほうが、成約率は上がります";
        if ($score['missing']) $s[] = '足りない点：'.implode('、', $score['missing']).'。メールで経験を聞いてみてください';
        if ($c->price && $e->min_price && (float) $e->min_price > (float) $c->price) $s[] = '本人の下限の単価（'.man($e->min_price).'）が、案件（'.man($c->price).'）より高い';
        if ($c->start_date && $e->available_from && $e->available_from > $c->start_date) $s[] = '本人が動ける日（'.fmt_date($e->available_from).'）が、開始より後';
        if ($e->status === 'working') $s[] = '今は稼働中です';
        if (!$e->self_confirmed_at) $s[] = '本人が登録の内容をまだ確認していないので、応募は確認のあとになります';
        $p = self::pastReplies($e->id);
        if ($p['declined'] >= 2) $s[] = "これまでの案内を{$p['declined']}回見送っています".($p['reasons'] ? '（'.implode('、', array_slice($p['reasons'], 0, 3)).'）' : '');
        return $s;
    }

    // 送る。$items は [engineer_id => ['subject' => ..., 'body' => ...]]
    public static function send($u, object $c, array $items, string $mode): int
    {
        $sesId = $u->role === 'ses' ? (int) $u->ses_id : null;
        $from = $sesId ? DB::table('ses_companies')->where('id', $sesId)->value('name').' '.$u->name : setting('operator_name');
        $n = 0;
        foreach ($items as $eid => $it) {
            $e = DB::table('engineers')->find((int) $eid);
            if (!$e || DB::table('case_mails')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists()) continue;
            $own = $sesId === null || (int) $e->ses_id === $sesId;
            $score = Matcher::score($c, $e);
            $body = (string) $it['body'];
            // グループのほかの SES の人へは PARK から送り、返事は PARK の画面でもらう（その人の SES にも知らせる）
            if (!$own) $body .= "\n\n※PARK のグループの案件です。応募・見送りは、PARK の画面からお願いします。\n".url("/cases/{$c->id}");
            $to = $e->email ?: DB::table('users')->where('engineer_id', $e->id)->value('email');
            $outbox = N::queueMail($to, $e->name, (string) $it['subject'], $body, 'case-mail', $own && $sesId ? $u->email : null);
            DB::table('case_mails')->insert(['case_id' => $c->id, 'engineer_id' => $e->id, 'ses_id' => $sesId, 'sent_by' => $u->id, 'route' => $own ? 'own' : 'group', 'mode' => $mode,
                'subject' => (string) $it['subject'], 'body' => $body, 'score' => $score['score'], 'suggest' => implode(' / ', self::suggestions($c, $e, $score)), 'outbox_id' => $outbox, 'sent_at' => now_str()]);
            // 本人が PARK の画面からも見られるように（応募・見送りもできる）
            if (!DB::table('case_offers')->where('case_id', $c->id)->where('engineer_id', $e->id)->exists()) {
                DB::table('case_offers')->insert(['case_id' => $c->id, 'engineer_id' => $e->id, 'score' => $score['score'], 'reasons' => implode(' / ', $score['reasons']),
                    'missing' => implode(' / ', $score['missing']), 'sent_by' => $u->id, 'sent_at' => now_str()]);
            }
            if (!$own) N::toSes($e->ses_id, "グループの案件が、貴社のエンジニアに届きました：{$c->title}", "{$e->name} さんに、グループの案件「{$c->title}」のご案内が届きました。", "/cases/{$c->id}", true, 'offer-ses');
            $n++;
        }
        N::audit($u->id, 'case-mail', "{$c->title} → {$n}名（".($mode === 'each' ? '個別' : '同じ内容').'）');
        return $n;
    }

    // 返事を記録する（SES が受けた返信を入れる／本人が PARK の画面で応募・見送り）
    public static function reply(int $caseId, int $engineerId, string $reply, ?string $note, string $via): void
    {
        if (!isset(self::REPLIES[$reply])) return;
        DB::table('case_mails')->where('case_id', $caseId)->where('engineer_id', $engineerId)
            ->update(['reply' => $reply, 'reply_note' => $note, 'reply_via' => $via, 'replied_at' => now_str()]);
    }
}
