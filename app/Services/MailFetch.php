<?php

namespace App\Services;

use App\Contracts\MailSource;
use App\Services\Notifier as N;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// 受信箱から、前回の続き（UID）のメールを読んで、Inbound に渡す（v0.12.0）。
// 設定（.env の PARK_IMAP_*）が空なら何もしない。受信箱のメールは消さない・既読にしない。
class MailFetch
{
    const LIMIT = 50;           // 1回に読む最大の通数
    const SCREEN_LIMIT = 10;    // 画面の［今すぐ取り込む］で読む最大の通数（時間切れを避けるため）
    const LOCK_SECONDS = 300;   // 同時に 2 つ動かさないための鍵の長さ

    // 戻り：['ok' => 動いたか, 'msg' => 画面・コマンドに出す文, 'count' => 取り込んだ通数]
    public static function run(?MailSource $src = null, bool $fromStart = false, int $limit = self::LIMIT): array
    {
        if (!Inbound::enabled()) return ['ok' => false, 'msg' => '受信の設定がありません（.env の PARK_IMAP_*）。', 'count' => 0];
        $lock = Cache::lock('park-mail-fetch', self::LOCK_SECONDS);
        if (!$lock->get()) return ['ok' => false, 'msg' => '取り込み中です。しばらくしてからもう一度お試しください。', 'count' => 0];
        try {
            $src ??= app(MailSource::class);
            $validity = $src->uidvalidity();
            $savedValidity = self::get('inbound_uidvalidity');
            $last = self::get('inbound_last_uid');
            $changed = $savedValidity !== null && (int) $savedValidity !== $validity;
            if ($fromStart) $last = '0'; // 人が頼んだとき：はじめから読む（同じメールは Inbound が重複として弾く）
            elseif ($last === null || $changed) {
                // 初回、または UIDVALIDITY が変わった（UID の数え方が変わった）：今ある分は読まず、ここから先だけを読む（受信箱の過去のメールは取り込まない）
                // 変わったときに自動で読み直すと、過去のメールまで取り込んでしまうので、読み直しは人が --from-start で行う
                self::put('inbound_last_uid', $src->lastUid());
                self::put('inbound_uidvalidity', $validity);
                self::put('inbound_last_fetch_at', now_str());
                if ($changed) {
                    N::audit(null, 'mail-fetch-uidvalidity', "受信箱の UIDVALIDITY が {$savedValidity} から {$validity} に変わりました。今ある分は読まず、ここから先を取り込みます");
                    return ['ok' => true, 'msg' => '受信箱の UID の数え方が変わったので、今ある分は読まず、ここから先のメールを取り込みます。はじめから読み直すときは --from-start を付けてください。', 'count' => 0];
                }
                return ['ok' => true, 'msg' => '初回なので、今ある分は読まず、ここから先のメールを取り込みます。', 'count' => 0];
            }
            self::put('inbound_uidvalidity', $validity);
            $last = (int) $last;
            $count = $linked = $skipped = 0;
            foreach ($src->fetchSince($last, $limit) as [$uid, $raw]) {
                try {
                    if (trim((string) $raw) === '') throw new \RuntimeException('空のメール'); // 空の文字列は記録せず、先へ進む
                    $r = Inbound::ingest((string) $raw, 'imap', $validity, (int) $uid);
                    if (in_array($r['status'], ['saved', 'too_large'], true)) {
                        $count++;
                        if (DB::table('inbound_mails')->where('id', $r['id'])->value('status') === 'linked') $linked++;
                    } else {
                        $skipped++;
                    }
                } catch (\Throwable $e) {
                    // 1通が読めなくても、止まらず先へ進む（同じ1通で止まり続けないため）。中身は記録に書かない
                    N::audit(null, 'mail-fetch-error', "UID {$uid} を読めませんでした（".class_basename($e).'）');
                }
                self::put('inbound_last_uid', max($last, (int) $uid));
                $last = max($last, (int) $uid);
            }
            self::put('inbound_last_fetch_at', now_str());
            return ['ok' => true, 'count' => $count, 'msg' => $count || $skipped
                ? "{$count} 通取り込みました（案内メールに紐付いたもの {$linked} 通、紐付かなかったもの ".($count - $linked).' 通）。'
                : '新しいメールはありませんでした。'];
        } catch (\Throwable $e) {
            // 接続・ログインの失敗。パスワードは記録に書かない
            $msg = str_replace((string) config('park.inbound.password'), '***', $e->getMessage());
            N::audit(null, 'mail-fetch-error', mb_substr($msg, 0, 300));
            return ['ok' => false, 'msg' => '受信箱につながりませんでした：'.mb_substr($msg, 0, 120), 'count' => 0];
        } finally {
            $lock->release();
        }
    }

    // 設定の読み書き（setting() は同じ処理の中では古い値を返すので、直接読む）
    private static function get(string $key): ?string
    {
        $v = DB::table('settings')->where('key', $key)->value('value');
        return $v === null ? null : (string) $v;
    }

    private static function put(string $key, $value): void
    {
        set_setting($key, $value);
    }
}
