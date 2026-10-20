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

    public static function queueMail(?string $to, ?string $name, string $subject, string $body, string $kind = 'general', ?string $replyTo = null, ?string $fromName = null): ?int
    {
        if (!$to) return null;
        $footer = "\n\n――――――――――\n".($fromName ?: setting('operator_name'))."\n".url('/')."\n※このメールは PARK から自動で送っています。
※ご不明な点は、park@jona.jp までご相談ください。";
        $id = DB::table('mail_outbox')->insertGetId([
            'to_email' => $to, 'to_name' => $name ?? '', 'from_name' => $fromName ?: null, 'subject' => '['.setting('app_name').'] '.$subject, 'body' => $body.$footer,
            'kind' => $kind, 'status' => self::canSend() ? 'queued' : 'saved', 'created_at' => now_str(), 'reply_to' => $replyTo,
        ]);
        if (self::canSend()) self::deliver($id);
        return $id;
    }

    public static function deliver(int $id): bool
    {
        $m = DB::table('mail_outbox')->find($id);
        if (!$m || !self::canSend()) return false;
        try {
            Mail::raw($m->body, function ($msg) use ($m) {
                $msg->to($m->to_email, $m->to_name)->subject($m->subject);
                if (!empty($m->reply_to)) $msg->replyTo($m->reply_to);
                if ($from = self::fromOf($m)) $msg->from(...$from);
            });
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'sent', 'sent_at' => now_str(), 'error' => null]);
            return true;
        } catch (\Throwable $e) {
            DB::table('mail_outbox')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500)]);
            return false;
        }
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
    public static function notify(int $userId, string $title, string $body = '', string $link = '', bool $mail = true, string $kind = 'notice'): void
    {
        $u = DB::table('users')->find($userId);
        if (!$u || !$u->active) return;
        DB::table('app_notifications')->insert(['user_id' => $userId, 'title' => $title, 'body' => $body, 'link' => $link, 'created_at' => now_str()]);
        if ($mail && $u->mail_opt_in) {
            $url = $link ? "\n\n▼詳しくはこちら\n".url($link) : '';
            self::queueMail($u->email, $u->name, $title, user_label($u)." 様\n\n".($body ?: $title).$url, $kind);
        }
    }

    public static function admins() { return DB::table('users')->where('role', 'admin')->where('active', 1)->get(); }
    public static function sesUsers($sesId) { return DB::table('users')->where('role', 'ses')->where('ses_id', $sesId)->where('active', 1)->get(); }
    public static function egUsers($engineerId) { return DB::table('users')->where('role', 'eg')->where('engineer_id', $engineerId)->where('active', 1)->get(); }

    public static function toAdmins(string $title, string $body, string $link, bool $mail = true, string $kind = 'notice'): void
    {
        foreach (self::admins() as $u) self::notify($u->id, $title, $body, $link, $mail, $kind);
    }
    public static function toSes($sesId, string $title, string $body, string $link, bool $mail = true, string $kind = 'notice'): void
    {
        // 管理者の直属には SES の担当者がいないので、管理者全員に届ける
        if (Workflow::isDirect($sesId ? (int) $sesId : null)) { self::toAdmins($title, $body, $link, $mail, $kind); return; }
        foreach (self::sesUsers($sesId) as $u) self::notify($u->id, $title, $body, $link, $mail, $kind);
    }
    public static function toEg($engineerId, string $title, string $body, string $link, bool $mail = true, string $kind = 'notice'): void
    {
        foreach (self::egUsers($engineerId) as $u) self::notify($u->id, $title, $body, $link, $mail, $kind);
    }

    public static function audit($userId, string $action, ?string $detail = ''): void
    {
        DB::table('audit_log')->insert(['user_id' => $userId, 'action' => $action, 'detail' => $detail, 'at' => now_str()]);
    }
}
