<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

// メール配信・アプリ内の通知（ベル）・操作の記録。
// メールはすべて mail_outbox に残す。MAIL_MAILER が log / array のときは「保存のみ」（管理者の「メール」画面で中身を確認できる）。
class Notifier
{
    public static function canSend(): bool
    {
        return !in_array(config('mail.default'), ['log', 'array', null], true);
    }

    public static function queueMail(?string $to, ?string $name, string $subject, string $body, string $kind = 'general', ?string $replyTo = null, ?string $fromName = null, bool $plain = false, bool $deliverNow = true): ?int
    {
        // $deliverNow を false にすると、行を作るだけで送らない（トランザクションの中から呼ぶとき。commit のあとに deliver を呼ぶ）
        if (!$to) return null;
        // $plain：プロバイダーなど PARK の外へ送るメール。件名の [PARK] と PARK の署名を付けない（v0.13.0）
        $footer = $plain ? '' : "\n\n――――――――――\n".($fromName ?: setting('operator_name'))."\n".url('/')."\n※このメールは PARK から自動で送っています。
※ご不明な点は、park@jona.jp までご相談ください。";
        $id = DB::table('mail_outbox')->insertGetId([
            'to_email' => $to, 'to_name' => $name ?? '', 'from_name' => $fromName ?: null, 'subject' => $plain ? $subject : '['.setting('app_name').'] '.$subject, 'body' => $body.$footer,
            'kind' => $kind, 'status' => self::canSend() ? 'queued' : 'saved', 'created_at' => now_str(), 'reply_to' => $replyTo,
            'message_id' => self::newMessageId(), // 返信から元のメールを探すため、送るメールすべてに付ける（v0.12.0）
        ]);
        if ($deliverNow && self::canSend()) self::deliver($id);
        return $id;
    }

    public static function deliver(int $id): bool
    {
        $m = DB::table('mail_outbox')->find($id);
        if (!$m || !self::canSend()) return false;
        try {
            // 見本データ（@park.example）宛ては、本物のアドレスが無いので、テスト用の受け皿（既定 park@jona.jp）に届ける。本来の宛先は件名と本文の頭に書く
            $dummy = str_ends_with(strtolower($m->to_email), '@park.example') && config('park.dummy_mailbox');
            $subject = $dummy ? '[ダミー宛：'.$m->to_email.'] '.$m->subject : $m->subject;
            $body = $dummy ? '※見本データ宛てのメールです。本来の宛先：'.($m->to_name ? $m->to_name.' ' : '').'<'.$m->to_email.">\n――――――――――\n".$m->body : $m->body;
            Mail::raw($body, function ($msg) use ($m, $dummy, $subject) {
                $dummy ? $msg->to(config('park.dummy_mailbox'), 'PARK テスト受信') : $msg->to($m->to_email, $m->to_name);
                $msg->subject($subject);
                if ($from = self::fromOf($m)) $msg->from(...$from);
                self::headers($msg, $m);
            });
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now_str(), 'error' => null]);
            return true;
        } catch (\Throwable $e) {
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            return false;
        }
    }

    // 送るメールの Message-ID（<> なし）。@ のあとは差出人のドメイン
    public static function newMessageId(): string
    {
        $domain = substr(strrchr((string) config('mail.from.address'), '@') ?: '@localhost', 1);
        return bin2hex(random_bytes(16)).'@'.$domain;
    }

    // 返信先（複数なら , で区切る）と Message-ID をメールに付ける。$msg は Mail::raw の中のメッセージ
    public static function headers($msg, object $m): void
    {
        // Symfony の Email を直接渡されたとき（テスト）と、Laravel の Message（本番）の両方に使える
        $symfony = $msg instanceof \Symfony\Component\Mime\Email ? $msg : (method_exists($msg, 'getSymfonyMessage') ? $msg->getSymfonyMessage() : null);
        if (!empty($m->reply_to)) {
            $list = array_values(array_filter(array_map('trim', explode(',', (string) $m->reply_to))));
            if ($list && $symfony === $msg) $msg->replyTo(...$list);
            elseif ($list) $msg->replyTo(count($list) === 1 ? $list[0] : $list);
        }
        // Message-ID は Symfony のメッセージにだけ付けられる（テストの偽物には無いので、あるときだけ）
        if (!empty($m->message_id) && $symfony) $symfony->getHeaders()->addIdHeader('Message-ID', $m->message_id);
    }

    // 差出人の名前があれば [アドレス, 名前]。アドレスは全メール共通のまま（送信ドメインの認証のため）。なければ null（.env の既定）
    public static function fromOf(object $m): ?array
    {
        return empty($m->from_name) ? null : [config('mail.from.address'), $m->from_name];
    }

    public static function flush(): void
    {
        foreach (DB::table('mail_outbox')->where('status', 'queued')->orderBy('id')->limit(50)->pluck('id') as $id) self::deliver($id);
    }

    // 通知（ベル）とメールを一度に出す。link は '/cases/1' のようなアプリ内のパス
    // $forRole：どの立場あてか（admin／ses／eg）。兼任の人は、立場ごとにお知らせを分けて見るため。空ならどの立場でも出す
    public static function notify(int $userId, string $title, string $body = '', string $link = '', bool $mail = true, string $kind = 'notice', ?string $forRole = null): void
    {
        $u = DB::table('users')->find($userId);
        if (!$u || !$u->active) return;
        DB::table('app_notifications')->insert(['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link, 'created_at' => now_str(), 'for_role' => $forRole]);
        if ($mail && $u->mail_opt_in) {
            $url = $link ? "\n\n▼詳しくはこちら\n".url($link) : '';
            // 兼任の人には、どの立場あてのメールかを頭に書く
            $head = $forRole && Workflow::isDual($u) ? ['ses' => '【SES 担当者あて】', 'admin' => '【管理者あて】', 'eg' => '【エンジニアあて】'][$forRole]."\n" : '';
            // 担当者の立場あては、兼任の管理者にも「会社名 氏名」で宛名を書く（管理者の企業名は出さない）
            $who = $forRole === 'ses' ? staff_label($u) : user_label($u);
            self::queueMail($u->email, $u->name, $title, $head.$who." 様\n\n".($body ?: $title).$url, $kind);
        }
    }

    // 知らせの相手は、本登録が済んだ人だけ（仮登録の人には送らない）
    public static function admins() { return DB::table('users')->where('role', 'admin')->where('active', 1)->whereNotNull('registered_at')->get(); }
    // 会社の担当者への知らせの相手。この会社の担当者を兼ねている管理者（role=admin・ses_id がこの会社）も含める（2026-11-15）
    public static function sesUsers($sesId) { return DB::table('users')->whereIn('role', ['ses', 'admin'])->where('ses_id', $sesId)->where('active', 1)->whereNotNull('registered_at')->get(); }
    // エンジニアあての相手：本人のアカウント（SES 担当者・管理者を兼ねている人も含む）。エンジニアが停止中・退会済なら届けない
    public static function egUsers($engineerId)
    {
        return DB::table('users')->where('engineer_id', $engineerId)->whereIn('role', ['eg', 'ses', 'admin'])->where('active', 1)->whereNotNull('users.registered_at')
            ->whereExists(fn ($q) => Registration::usableEngineers($q->select(DB::raw(1))->from('engineers')->whereColumn('engineers.id', 'users.engineer_id')))->get();
    }

    // $exceptUserId：この人には出さない（自分で書いたことを、自分に知らせないため）
    public static function toAdmins(string $title, string $body, string $link, bool $mail = true, string $kind = 'notice', ?int $exceptUserId = null): void
    {
        foreach (self::admins() as $u) if ((int) $u->id !== (int) $exceptUserId) self::notify($u->id, $title, $body, $link, $mail, $kind, 'admin');
    }
    public static function toSes($sesId, string $title, string $body, string $link, bool $mail = true, string $kind = 'notice', ?int $exceptUserId = null): void
    {
        foreach (self::sesUsers($sesId) as $u) if ((int) $u->id !== (int) $exceptUserId) self::notify($u->id, $title, $body, $link, $mail, $kind, 'ses');
    }
    public static function toEg($engineerId, string $title, string $body, string $link, bool $mail = true, string $kind = 'notice', ?int $exceptUserId = null): void
    {
        foreach (self::egUsers($engineerId) as $u) if ((int) $u->id !== (int) $exceptUserId) self::notify($u->id, $title, $body, $link, $mail, $kind, 'eg');
    }

    public static function audit($userId, string $action, ?string $detail = ''): void
    {
        // 管理者が、兼ねた会社の担当者の立場でした操作には、頭に印を付ける（公平性のため。あとで分かるように）
        $me = auth()->user();
        if ($me && $userId !== null && (int) $userId === (int) $me->id && $me->role === 'ses' && base_role() === 'admin') $detail = '［管理者の兼任］'.$detail;
        DB::table('audit_log')->insert(['user_id' => $userId, 'action' => $action, 'detail' => $detail, 'at' => now_str()]);
    }
}
