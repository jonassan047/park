<?php

namespace App\Console\Commands;

use App\Services\ProjectSkills;
use App\Support\SkillCatalog;
use Illuminate\Console\Command;

// 技術マスタの最初の辞書（app/Support/SkillCatalog.php）を DB に入れる。何度実行してもよい
class SkillsSync extends Command
{
    protected $signature = 'park:skills-sync';
    protected $description = '技術マスタの辞書を DB に入れる（別名を足す・名前の変更・確認待ちの直し。行は消さない）';

    public function handle(): int
    {
        $r = SkillCatalog::syncDetail();
        $this->info("技術マスタ：{$r['added']} 件を新しく入れました。");
        foreach ($r['renamed'] as $old => $new) $this->info("名前を変えました：{$old} → {$new}");
        if ($r['fixed']) $this->info('確認待ちから確認済みにしました：'.implode('、', $r['fixed']));
        if ($r['conflicts']) {
            $this->warn('別名がほかの行とぶつかっています（言葉 → 既にある行の名前）：');
            foreach ($r['conflicts'] as $c) $this->warn('  '.$c);
            $this->warn('技術マスタの画面で統合してください（統合は行を消します。TEZZO の了承を取ってから）。');
        }
        ProjectSkills::rebuildAll();
        $this->info('経歴の索引を作り直しました。');
        return 0;
    }
}
