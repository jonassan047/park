<?php

namespace App\Console\Commands;

use App\Services\SamplePurge;
use Illuminate\Console\Command;
use RuntimeException;

// 見本データを消す。--yes が無ければ見るだけ（何も変えない）。名前・メールアドレスは出さず、数だけを出す。
// 使い方：php artisan park:purge-samples            （消す件数と残す件数を見る）
//         php artisan park:purge-samples --yes      （バックアップを書き出してから消す）
class PurgeSamples extends Command
{
    protected $signature = 'park:purge-samples {--yes : 実際に消す} {--move-kept-to-direct : 見本の SES に入っている見本ではない人を、管理者の直属へ移して続ける}';
    protected $description = '見本データを消す（--yes が無ければ、消す件数を見るだけ）';

    public function handle(): int
    {
        $moveKept = (bool) $this->option('move-kept-to-direct');
        $plan = SamplePurge::plan($moveKept);

        $this->line('表                       消す   残す');
        foreach ($plan['counts'] as $t => [$del, $keep]) {
            $this->line(str_pad($t, 24).str_pad((string) $del, 6, ' ', STR_PAD_LEFT).str_pad((string) $keep, 7, ' ', STR_PAD_LEFT));
        }
        if ($plan['guards']) {
            $this->newLine();
            foreach ($plan['guards'] as $g) $this->error($g);
            $this->error('何も消さずに止めました。');
            return 1;
        }
        $this->newLine();
        $this->info('守りの判定：問題ありません。');
        if ($plan['total'] === 0) {
            $this->info('消すものは0件です。何も変えません。');
            return 0;
        }
        if (!$this->option('yes')) {
            $this->line('見るだけです（何も変えていません）。消すときは --yes を付けます。');
            return 0;
        }

        try {
            $r = SamplePurge::run($moveKept);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }
        $this->info('消しました（合計 '.$r['total'].' 件）。');
        $this->line('バックアップ：'.$r['backup']);
        return 0;
    }
}
