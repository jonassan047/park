<?php

namespace App\Console\Commands;

use App\Services\Inbound;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// .eml のファイル 1 つを、受信メールとして取り込む（手で確かめるとき、テスト用）。受信の設定（.env の PARK_IMAP_*）が空でも動く。
// 使い方：php artisan park:mail-ingest <.eml のファイル>
class MailIngest extends Command
{
    protected $signature = 'park:mail-ingest {path : .eml のファイル}';
    protected $description = '.eml のファイル 1 つを受信メールとして取り込み、案内メールに紐付ける';

    public function handle(): int
    {
        $path = (string) $this->argument('path');
        if (!is_file($path) || !is_readable($path)) {
            $this->error('ファイルを読めません。');
            return 1;
        }
        $r = Inbound::ingest((string) file_get_contents($path), 'file');
        if (!$r['id']) {
            $this->line(['duplicate' => 'もう取り込み済みです（記録は増やしません）。', 'self' => 'PARK 自身が送ったメールなので、記録しません。'][$r['status']] ?? $r['status']);
            return 0;
        }
        $m = DB::table('inbound_mails')->find($r['id']);
        $this->line("取り込みました：#{$m->id}／".(Inbound::STATUSES[$m->status] ?? $m->status).($m->match_by ? '（'.(Inbound::MATCH_BY[$m->match_by] ?? $m->match_by).'）' : '').($m->auto ? "／自動（{$m->auto}）" : ''));
        return 0;
    }
}
