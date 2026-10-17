<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// 確認コード（2要素認証）がうまくいかない原因を調べるために、その人の状態を出す。見るだけで、書き換えない。
// メールの件名・本文（コードが入っている）は出さない。
// 使い方：php artisan park:twofactor {メールアドレス}
class TwoFactorStatus extends Command
{
    protected $signature = 'park:twofactor {email}';
    protected $description = '2要素認証まわりの状態を見る（書き換えない）';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $u = DB::table('users')->where('email', $email)->first();
        if (!$u) {
            $this->error('その人はいません。');
            return 1;
        }

        $this->info('人');
        $this->table(['id', 'role', 'active', 'two_factor_confirmed_at', 'last_login'], [[$u->id, $u->role, $u->active, $u->two_factor_confirmed_at, $u->last_login]]);

        $this->info('確認コードのメール（新しい順 10件）');
        $mails = DB::table('mail_outbox')->where('to_email', $u->email)->where('kind', '2fa')->orderByDesc('id')->limit(10)
            ->get(['id', 'status', 'created_at', 'sent_at', 'error'])->map(function ($m) {
                $m = (array) $m;
                $m['error'] = $this->hideEmails($m['error']);
                return $m;
            })->all();
        $this->table(['id', 'status', 'created_at', 'sent_at', 'error'], $mails);

        $this->info('記録（新しい順 20件）');
        $logs = DB::table('audit_log')->where('user_id', $u->id)
            ->where(fn ($q) => $q->where('action', 'like', '2fa%')->orWhere('action', 'like', 'login%')->orWhere('action', 'password-set'))
            ->orderByDesc('id')->limit(20)->get(['action', 'detail', 'at'])->map(function ($l) {
                $l = (array) $l;
                $l['detail'] = $this->hideEmails($l['detail']);
                return $l;
            })->all();
        $this->table(['action', 'detail', 'at'], $logs);

        $this->info('サーバー');
        $this->line('今の時刻：'.now_str().'／app.timezone：'.config('app.timezone'));

        $this->info('設定');
        $yn = fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) ($v ?? '');
        $this->table(['項目', '値'], [
            ['session.driver', $yn(config('session.driver'))],
            ['session.lifetime', $yn(config('session.lifetime'))],
            ['session.cookie', $yn(config('session.cookie'))],
            ['session.domain', $yn(config('session.domain'))],
            ['session.secure', $yn(config('session.secure'))],
            ['session.same_site', $yn(config('session.same_site'))],
            ['app.url', $yn(config('app.url'))],
            ['app.env', $yn(config('app.env'))],
            ['確認コードを省くドメイン', (string) config('app.skip_2fa_domain') !== '' ? 'あり' : 'なし'],
        ]);
        return 0;
    }

    // 文の中のメールアドレスらしい語（xxx@yyy）を伏せる。
    private function hideEmails($text): ?string
    {
        if ($text === null) {
            return null;
        }
        return preg_replace('/[^\s@,;:<>()"\']+@[^\s@,;:<>()"\']+/u', '***@***', (string) $text);
    }
}
