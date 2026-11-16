<?php

namespace App\Console\Commands;

use App\Services\MailFetch as Fetch;
use Illuminate\Console\Command;

// 受信箱（park@jona.jp）から、案内メールへの返信を取り込む。公開版の cron で 5 分ごとに動かす。
// .env の PARK_IMAP_* が空なら、何もせず「受信の設定がありません」と出して終わる（エラーにしない）。
// 受信箱のメールは消さない・既読にしない。前回の続きから、最大 50 通。
// 使い方：php artisan park:mail-fetch [--from-start]
class MailFetch extends Command
{
    protected $signature = 'park:mail-fetch {--from-start : 受信箱のはじめから読む（付けないときは、前回の続き。初回は今ある分を読まない）}';
    protected $description = '受信箱から案内メールへの返信を取り込む（設定が空なら何もしない）';

    public function handle(): int
    {
        $r = Fetch::run(null, (bool) $this->option('from-start'));
        $this->line($r['msg']);
        return 0;
    }
}
