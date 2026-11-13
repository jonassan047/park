<?php

namespace App\Console\Commands;

use App\Services\Ids;
use Illuminate\Console\Command;

// 表示 ID（CA-000012 など）が空の行を、作った順に埋める。ふだんは付け漏れが無いので、0 件のはず。
// 使い方：php artisan park:fill-ids          （数えるだけ。何も変えない）
//         php artisan park:fill-ids --apply  （空の行に付ける。付いている ID は変えない）
class FillIds extends Command
{
    protected $signature = 'park:fill-ids {--apply : 空の行に ID を付ける（付けないときは数えるだけ）}';
    protected $description = '表示 ID が空の行を数える（--apply で付ける）';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $counts = Ids::fillMissing($apply);
        foreach ($counts as $kind => $n) $this->line(str_pad($kind, 3).' '.str_pad((string) $n, 6, ' ', STR_PAD_LEFT).'  '.($apply ? '件に付けました' : '件が空です'));
        $total = array_sum($counts);
        $this->info($apply ? "ID を {$total} 件に付けました。" : "ID の空の行は {$total} 件です。付けるときは --apply を付けてください。");
        return 0;
    }
}
