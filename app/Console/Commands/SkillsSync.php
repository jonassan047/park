<?php

namespace App\Console\Commands;

use App\Support\SkillCatalog;
use Illuminate\Console\Command;

// 技術マスタの最初の辞書（app/Support/SkillCatalog.php）を DB に入れる。何度実行してもよい
class SkillsSync extends Command
{
    protected $signature = 'park:skills-sync';
    protected $description = '技術マスタの辞書を DB に入れる（すでにあるものは別名だけ足す）';

    public function handle(): int
    {
        $n = SkillCatalog::sync();
        $this->info("技術マスタ：{$n} 件を新しく入れました。");
        return 0;
    }
}
