<?php

namespace App\Services;

use App\Services\Notifier as N;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

// エンジニアの返事を、案件のプロバイダーに伝える（v0.13.0。R16）。
// エンジニアの返信が案内メールに紐付くと、プロバイダーあての「下書き」が自動でできる。担当者が確かめて［送信］を押したときだけ送る（勝手には送らない）。
// プロバイダー（PARK の外）に出る エンジニアは、イニシャルだけ。氏名・ふりがな・メール・電話は伏せ、残っていれば送る前に止める。
class ProviderReply
{
    const KIND = 'provider-mail';              // お知らせ・メールの種類
    const MAX_QUOTE = 2000;                    // 下書きに入れる、エンジニアの返信の字数
    const MAX_SUBJECT = 200;
    const MAX_BODY = 20000;
    const NO_COMMENT = '（本人のコメントはありません）';
    const STATUSES = ['draft' => '下書き', 'sent' => '送信済み', 'discarded' => '取りやめ'];
    // 意向 => [意向の文, 締めの文]
    const INTENTS = [
        'interested' => ['参画を希望しています', 'スキルシート（業務経歴書）は、別途お送りいたします。ご検討のほど、よろしくお願いいたします。'],
        'question' => ['条件について、確認したいことがあります', '上記について、ご回答いただけますと幸いです。'],
        'declined' => ['今回は見送りたいとのことです', 'せっかくご紹介いただいたのに申し訳ありません。またの機会によろしくお願いいたします。'],
    ];

    // ---------- 見える・直せる ----------

    // 見えるか：管理者は全社、SES・担当者は案件の今の持ち込み元が自社のとき。エンジニアは見えない
    public static function canView($u, object $pm): bool
    {
        if (!$u) return false;
        if ($u->role === 'admin') return true;
        return self::canEdit($u, $pm);
    }

    // 直す・送る・取りやめるのは、案件の今の持ち込み元の担当者だけ。管理者は読むだけ（担当者を兼ねた管理者は、担当者の立場で）
    public static function canEdit($u, object $pm): bool
    {
        if (!$u || $u->role !== 'ses' || empty($u->ses_id)) return false;
        $source = DB::table('cases')->where('id', $pm->case_id)->value('source_ses_id');
        return $source && (int) $source === (int) $u->ses_id;
    }

    // ---------- 文面 ----------

    // 件名と本文をルールで作る。$quote は、エンジニアの返信から取り出して伏せたもの（空なら「コメントなし」）
    public static function compose(object $case, ?object $provider, string $sesName, ?object $e, string $reply, string $quote): array
    {
        [$intent, $closing] = self::INTENTS[$reply] ?? self::INTENTS['interested'];
        $initials = ($e->initials ?? null) ?: 'エンジニア';
        $head = $provider ? $provider->name."\n".trim($provider->sei.($provider->mei ?? '')).' 様' : 'ご担当者 様';
        $quoted = trim($quote) === '' ? self::NO_COMMENT : implode("\n", array_map(fn ($l) => trim($l) === '' ? '' : '　'.$l, preg_split('/\r\n|\r|\n/', trim($quote))));
        $body = $head."\n\n"
            ."いつもお世話になっております。{$sesName}です。\n"
            ."ご紹介いただいた案件「{$case->title}」について、エンジニアの意向を確認しましたので、ご連絡いたします。\n\n"
            ."■エンジニア：{$initials}\n"
            ."■本人の意向：{$intent}\n"
            ."■本人からのコメント：\n{$quoted}\n\n"
            .$closing;
        return ['subject' => '【ご返事】'.$case->title, 'body' => $body];
    }

    // エンジニアの返信の本文から、本人が書いた要点だけを取り出す（引用・署名・案内メールの写し・返事のリンク・URL を取り除き、エンジニアの氏名・連絡先を伏せる）
    // $inbound があれば、その差出人の名前・アドレスも伏せる
    public static function extract(string $body, ?object $e, ?object $inbound = null): string
    {
        $keep = [];
        foreach (preg_split('/\r\n|\r|\n/', $body) as $line) {
            if (self::isCutLine($line)) break; // ここから下は、引用・署名・案内メールの写し
            $keep[] = $line;
        }
        $text = implode("\n", $keep);
        // 返事のリンクや返信先のトークンを、プロバイダーに出さない
        $text = preg_replace('~https?://\S+~u', '', $text);
        $text = preg_replace('/[A-Za-z0-9._-]+\+[a-z0-9]{'.ReplyTokens::LENGTH.'}@[A-Za-z0-9.-]+/', '', $text);
        // 先に伏せてから切る（切る場所で、伏せる言葉が半端に残らないように）
        $hide = $e ? self::companyWords($e) : [];
        if ($inbound) {
            foreach ([$inbound->from_name ?? null, $inbound->from_email ?? null] as $v) {
                $v = trim((string) $v);
                if (mb_strlen($v) >= 2) $hide[] = $v;
            }
        }
        usort($hide, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
        foreach (array_unique($hide) as $w) $text = str_ireplace($w, '', $text); // 会社名・差出人は、置き換えずに消す
        if ($e) $text = Inbound::scrubEngineer($e, $text, ($e->initials ?? null) ?: 'エンジニア');
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text));
        if (mb_strlen($text) > self::MAX_QUOTE) $text = mb_substr($text, 0, self::MAX_QUOTE).'…（以下略）';
        return $text;
    }

    // エンジニアの所属の会社名（持ち込み元が自社でも伏せる）。「株式会社」などを除いた形も含む
    private static function companyWords(object $e): array
    {
        $name = trim((string) DB::table('ses_companies')->where('id', $e->ses_id ?? 0)->value('name'));
        if (mb_strlen($name) < 2) return [];
        $words = [$name];
        $core = trim(preg_replace('/(株式会社|有限会社|合同会社|\(株\)|（株）|㈱)/u', '', $name));
        if ($core !== $name && mb_strlen($core) >= 2) $words[] = $core;
        return $words;
    }

    // 引用・署名・システム伝達項目の始まりの行か
    private static function isCutLine(string $line): bool
    {
        $t = trim($line);
        if (str_starts_with($t, '>')) return true;
        if (preg_match('/^-{2,}\s*Original Message\s*-{2,}/i', $t)) return true;
        if (preg_match('/^On .+ wrote:\s*$/i', $t)) return true;
        if (preg_match('/\d{4}年\d{1,2}月\d{1,2}日.*[:：]\s*$/u', $t) || preg_match('/のメッセージ\s*[:：]?\s*$/u', $t) || preg_match('/書きました[:：]?\s*$/u', $t)) return true;
        // 日本語のメールソフトの引用の頭：「2026/11/20 10:00、PARK <…>のメール:」「…のメール:」「<…@…>:」
        if (preg_match('/のメール\s*[:：]\s*$/u', $t) || preg_match('/<[^<>\s]+@[^<>\s]+>\s*[:：]\s*$/u', $t)) return true;
        if (preg_match('/^(差出人|From)\s*[:：]/iu', $t)) return true;
        if (str_starts_with($t, '――――――――――')) return true;
        if (rtrim($line) === '--') return true; // 署名の区切り（-- ）
        if (str_starts_with($t, '［システム伝達項目') || str_starts_with($t, '▼ご返事はこちらから')) return true;
        return false;
    }

    // 本文に残っている、エンジニアの登録の値の種類（「氏名」「ふりがな」「メール」「電話」「会社名」）
    public static function leaks(object $e, string $text): array
    {
        $kinds = Inbound::leakKinds($e, $text);
        // 会社名：決まり文句の「いつもお世話になっております。○○です。」（送る会社の名のり）は数えない
        $plain = preg_replace('/^いつもお世話になっております。.*です。\s*$/mu', '', $text);
        foreach (self::companyWords($e) as $w) {
            if (stripos($plain, $w) !== false) { $kinds[] = '企業名'; break; }
        }
        return $kinds;
    }

    // ---------- 下書きを作る ----------

    // 受信メール（エンジニアの返信）から、下書きを 1 つ作る。作らないときは null。戻り：作った行の id
    public static function draftFromInbound(int $inboundId): ?int
    {
        $m = DB::table('inbound_mails')->find($inboundId);
        if (!$m || $m->status !== 'linked' || ($m->sender_kind ?? 'eg') !== 'eg' || $m->auto || !$m->case_id || !$m->ses_id) return null;
        if ($m->from_mismatch) return null; // 登録と違うアドレス・別人からの返信（管理者が手で紐付けたプロバイダーの返事も）は、自動では作らない
        if (Inbound::fromStaff((string) $m->from_email, (int) $m->ses_id)) return null; // 担当者自身からの返信
        $case = DB::table('cases')->find($m->case_id);
        if (!$case) return null;
        if (DB::table('provider_mails')->where('inbound_mail_id', $inboundId)->exists()) return null;

        $cm = $m->case_mail_id ? DB::table('case_mails')->find($m->case_mail_id) : null;
        // パートナーの担当者が送った案内への返信は、下書きを作らない（プロバイダーとやり取りするのは受注 SES で、パートナーの会社は直せないため。v0.16.0）
        if ($cm && $cm->mode === CaseSearch::MODE_PARTNER) return null;
        $e = $m->engineer_id ? DB::table('engineers')->find($m->engineer_id) : null;
        $reply = self::intentOf($cm);
        $text = self::compose($case, self::providerOf($case), self::sesName((int) $m->ses_id), $e, $reply, self::extract((string) $m->body_text, $e, $m));
        try {
            return (int) DB::table('provider_mails')->insertGetId(self::row($case, (int) $m->ses_id, $e, $cm, $inboundId, $reply, $text, null));
        } catch (QueryException $ex) {
            // 同時に取り込まれて、同じ受信メールの行がもうあるとき（unique）は、何もしない
            if (str_contains(strtolower($ex->getMessage()), 'unique') || str_contains(strtolower($ex->getMessage()), 'duplicate')) return null;
            throw $ex;
        }
    }

    // 案内メールの行から作る（受信メールが無いとき。返事のリンクで「応募したい」が来た・電話で聞いた、など）。同じ案内メールの下書きがあれば、それを返す
    public static function draftFromCaseMail(int $caseMailId, $u): int
    {
        $cm = DB::table('case_mails')->find($caseMailId);
        $existing = DB::table('provider_mails')->where('case_mail_id', $caseMailId)->where('status', 'draft')->orderBy('id')->value('id');
        if ($existing) return (int) $existing;
        $case = DB::table('cases')->find($cm->case_id);
        $e = DB::table('engineers')->find($cm->engineer_id);
        $reply = self::intentOf($cm);
        $text = self::compose($case, self::providerOf($case), self::sesName((int) $u->ses_id), $e, $reply, '');
        return (int) DB::table('provider_mails')->insertGetId(self::row($case, (int) $u->ses_id, $e, $cm, null, $reply, $text, (int) $u->id));
    }

    // 下書き・取りやめの行を、元（受信メールか案内メール）から作り直す。取りやめた下書きを戻すのもこれ
    public static function redraft($u, int $id): array
    {
        $pm = DB::table('provider_mails')->find($id);
        if (!$pm) return ['type' => 'bad', 'msg' => '下書きが見つかりません。'];
        if ($pm->status === 'sent') return ['type' => 'bad', 'msg' => 'もう送っています。作り直せません。'];
        $case = DB::table('cases')->find($pm->case_id);
        $e = $pm->engineer_id ? DB::table('engineers')->find($pm->engineer_id) : null;
        $cm = $pm->case_mail_id ? DB::table('case_mails')->find($pm->case_mail_id) : null;
        $in = $pm->inbound_mail_id ? DB::table('inbound_mails')->find($pm->inbound_mail_id) : null;
        $reply = self::intentOf($cm);
        // 登録と違うアドレスからの返信は、本人のものと確かめられないので、中身を引かない
        $quote = $in && !$in->from_mismatch ? self::extract((string) $in->body_text, $e, $in) : '';
        $text = self::compose($case, self::providerOf($case), self::sesName((int) $u->ses_id), $e, $reply, $quote);
        // 送ったあとに作り直さないよう、状態を条件に入れる
        $done = DB::table('provider_mails')->where('id', $id)->whereIn('status', ['draft', 'discarded'])->update(['provider_id' => $case->provider_id ?? null, 'reply' => $reply, 'subject' => $text['subject'], 'body' => $text['body'],
            'status' => 'draft', 'note' => null, 'updated_by' => $u->id, 'updated_at' => now_str()]);
        if (!$done) return ['type' => 'bad', 'msg' => 'もう送っています。作り直せません。'];
        N::audit($u->id, 'provider-mail-redraft', "#$id");
        return ['type' => 'ok', 'msg' => '元から作り直しました。'];
    }

    // 意向：案内メールの返事。返事なしなら「応募したい」から始める
    private static function intentOf(?object $cm): string
    {
        return $cm && isset(self::INTENTS[$cm->reply]) ? $cm->reply : 'interested';
    }

    private static function providerOf(object $case): ?object
    {
        return !empty($case->provider_id) ? DB::table('providers')->find($case->provider_id) : null;
    }

    private static function sesName(int $sesId): string
    {
        return (string) DB::table('ses_companies')->where('id', $sesId)->value('name');
    }

    private static function row(object $case, int $sesId, ?object $e, ?object $cm, ?int $inboundId, string $reply, array $text, ?int $by): array
    {
        return ['case_id' => $case->id, 'ses_id' => $sesId, 'provider_id' => $case->provider_id ?? null, 'engineer_id' => $e->id ?? null, 'case_mail_id' => $cm->id ?? null,
            'inbound_mail_id' => $inboundId, 'reply' => $reply, 'subject' => $text['subject'], 'body' => $text['body'], 'status' => 'draft',
            'created_by' => $by, 'created_at' => now_str(), 'updated_at' => now_str()];
    }

    // ---------- 直す・取りやめる ----------

    public static function save($u, int $id, string $reply, string $subject, string $body): array
    {
        $pm = DB::table('provider_mails')->find($id);
        if (!$pm || $pm->status !== 'draft') return ['type' => 'bad', 'msg' => '下書きだけ直せます。'];
        if (!isset(self::INTENTS[$reply])) return ['type' => 'bad', 'msg' => '本人の意向を選んでください。'];
        $subject = trim($subject);
        if ($subject === '' || mb_strlen($subject) > self::MAX_SUBJECT) return ['type' => 'bad', 'msg' => '件名は 1〜'.self::MAX_SUBJECT.' 字で入れてください。'];
        if (trim($body) === '' || mb_strlen($body) > self::MAX_BODY) return ['type' => 'bad', 'msg' => '本文は 1〜'.self::MAX_BODY.' 字で入れてください。'];
        // 意向を変えても、本文は変えない（直した文を消さない）。意向の文を変えたいときは［作り直す］
        $done = DB::table('provider_mails')->where('id', $id)->where('status', 'draft')->update(['reply' => $reply, 'subject' => $subject, 'body' => $body, 'updated_by' => $u->id, 'updated_at' => now_str()]);
        if (!$done) return ['type' => 'bad', 'msg' => '下書きだけ直せます（もう送った・取りやめた可能性があります）。'];
        return ['type' => 'ok', 'msg' => '保存しました。まだ送っていません。'];
    }

    public static function discard($u, int $id, ?string $note): array
    {
        $done = DB::table('provider_mails')->where('id', $id)->where('status', 'draft')->update(['status' => 'discarded', 'note' => $note, 'updated_by' => $u->id, 'updated_at' => now_str()]);
        if (!$done) return ['type' => 'bad', 'msg' => '下書きだけ取りやめられます。'];
        N::audit($u->id, 'provider-mail-discard', "#$id");
        return ['type' => 'ok', 'msg' => '取りやめました。必要なら［作り直す］で戻せます。'];
    }

    // ---------- 送る ----------

    // 戻り：['type' => 'ok'|'bad', 'msg' => 文]。権限（canEdit）は呼ぶ側で確かめる
    public static function send($u, int $id, bool $force): array
    {
        $outbox = null;
        $res = DB::transaction(function () use ($u, $id, $force, &$outbox) {
            $pm = DB::table('provider_mails')->where('id', $id)->lockForUpdate()->first();
            if (!$pm) return ['type' => 'bad', 'msg' => '下書きが見つかりません。'];
            if ($pm->status === 'sent') return ['type' => 'bad', 'msg' => 'もう送っています。'];
            if ($pm->status === 'discarded') return ['type' => 'bad', 'msg' => '取りやめた下書きです。［作り直す］で戻してください。'];

            // 宛先は、送るときの案件のプロバイダー
            $case = DB::table('cases')->find($pm->case_id);
            $pv = $case && $case->provider_id ? DB::table('providers')->find($case->provider_id) : null;
            if (!$pv || !filter_var((string) $pv->email, FILTER_VALIDATE_EMAIL)) {
                return ['type' => 'bad', 'msg' => '案件にプロバイダーが付いていないか、メールアドレスが正しくありません。案件の編集でプロバイダーを選んでから送ってください。'];
            }
            if ($pm->subject === '' || mb_strlen($pm->subject) > self::MAX_SUBJECT || trim($pm->body) === '' || mb_strlen($pm->body) > self::MAX_BODY) {
                return ['type' => 'bad', 'msg' => '件名と本文を確かめてください（件名は '.self::MAX_SUBJECT.' 字まで、本文は '.self::MAX_BODY.' 字まで）。'];
            }
            $e = $pm->engineer_id ? DB::table('engineers')->find($pm->engineer_id) : null;
            if ($e && !$force && ($kinds = self::leaks($e, $pm->subject."\n".$pm->body))) {
                return ['type' => 'bad', 'msg' => '本文に '.implode('・', $kinds).' が残っています。直すか、「このまま送る」に印を付けてください。'];
            }

            $sesName = self::sesName((int) $u->ses_id);
            [$tokenId, $token] = ReplyTokens::issue(ReplyTokens::KIND_PROVIDER, (int) $case->id, (int) $u->ses_id, $e ? (int) $e->id : null, Inbound::enabled());
            $tag = ReplyTokens::tagOf($token);
            // 返信先：PARK（トークン）と、送った担当者。受信が使えないときは担当者だけ
            $replyTo = Inbound::enabled() ? Inbound::replyAddress($token).', '.$u->email : $u->email;
            $subject = rtrim($pm->subject).' '.ReplyTokens::providerMark($token);
            $body = rtrim($pm->body)."\n\n{$sesName}\n{$u->name}"
                ."\n\n――――――――――\nお問い合わせ番号：No.{$tag}\n（ご返信の際は、件名の番号を消さないでください）";
            $outbox = N::queueMail($pv->email, trim($pv->sei.($pv->mei ?? '')), $subject, $body, self::KIND, $replyTo, staff_label($u), true, false); // 実際の送信は、commit のあと（途中で失敗して巻き戻っても、二重に送らないため）
            $now = now_str();
            DB::table('provider_mails')->where('id', $id)->update(['ses_id' => (int) $u->ses_id, 'provider_id' => $pv->id, 'status' => 'sent', 'to_email' => $pv->email,
                'to_name' => $pv->name.' '.trim($pv->sei.($pv->mei ?? '')).' 様', 'sent_by' => $u->id, 'sent_at' => $now, 'outbox_id' => $outbox, 'reply_token_id' => $tokenId, 'updated_at' => $now]);
            ReplyTokens::attach($tokenId, ['provider_mail_id' => $id, 'outbox_id' => $outbox, 'message_id' => $outbox ? DB::table('mail_outbox')->where('id', $outbox)->value('message_id') : null]);

            // 案内メールの返事と結果
            if ($pm->case_mail_id && ($cm = DB::table('case_mails')->find($pm->case_mail_id))) {
                if ($cm->reply === 'none') CaseMail::reply((int) $cm->case_id, (int) $cm->engineer_id, $pm->reply, null, 'ses');
                if ($pm->reply === 'interested' && !$cm->result) {
                    DB::table('case_mails')->where('id', $cm->id)->update(['result' => 'pending', 'result_by' => $u->id, 'result_at' => $now]);
                }
            }

            // お知らせ（メールなし）
            $tail = '：'.$case->title.Ids::tag($case->code ?? null);
            if ($e) {
                N::toEg((int) $e->id, '先方へご返事を伝えました'.$tail, "案件「{$case->title}」について、担当者が先方へあなたのご返事を伝えました。", "/cases/{$case->id}", false, self::KIND);
                if ((int) $e->ses_id !== (int) $u->ses_id) {
                    // パートナーの案内の行なら、お知らせの行き先は「自社から案内したエンジニア」の表
                    $anchor = (isset($cm) && $cm && ($cm->mode ?? null) === CaseSearch::MODE_PARTNER) ? '#partner-mails' : '';
                    N::toSes((int) $e->ses_id, 'グループの案件で、貴社のエンジニアのご返事を先方へ伝えました'.$tail, "{$e->name} さんのご返事を、{$sesName}が先方へ伝えました。", "/cases/{$case->id}{$anchor}", false, self::KIND);
                }
            }
            N::audit($u->id, 'provider-mail-send', "#$id 案件 ".($case->code ?? $case->id).' → '.($pv->code ?? "プロバイダー#{$pv->id}").'（'.(CaseMail::REPLIES[$pm->reply] ?? $pm->reply).'）');
            return ['type' => 'ok', 'msg' => $pv->name.' '.$pv->sei.' 様へ'.(N::canSend() ? '送りました。' : '送る準備をしました（送信の設定が無いため、保存だけしました）。')];
        });
        // 状態の更新が済んでから送る。送れなかったときは、メールの記録（mail_outbox）に失敗として残る
        if ($res['type'] === 'ok' && $outbox && N::canSend()) N::deliver((int) $outbox);
        return $res;
    }
}
