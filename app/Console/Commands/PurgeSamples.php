<?php

namespace App\Console\Commands;

use App\Services\Ids;
use App\Services\SamplePurge;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

// 見本データを消す。--yes が無ければ見るだけ（何も変えない）。名前・メールアドレスは出さず、数だけを出す。
// 使い方：php artisan park:purge-samples            （消す件数と残す件数を見る）
//         php artisan park:purge-samples --yes      （バックアップを書き出してから消す）
class PurgeSamples extends Command
{
    protected $signature = 'park:purge-samples {--yes : 実際に消す} {--move-kept-to= : 見本の SES に入っている見本ではない人を、この会社（参加中の SES の表示 ID。例 SE-000012）へ移して続ける}';
    protected $description = '見本データを消す（--yes が無ければ、消す件数を見るだけ）';

    public function handle(): int
    {
        // 移し先の会社（表示 ID から探す）。指定が無ければ null
        $moveTo = null;
        if (($to = $this->option('move-kept-to')) !== null && $to !== '') {
            $code = Ids::normalize((string) $to);
            $moveTo = $code && Ids::kindOf($code) === 'S' ? DB::table('ses_companies')->where('code', $code)->where('kind', 'ses')->where('status', 'active')->value('id') : null;
            if (!$moveTo) {
                $this->error('移し先の会社が見つかりません（参加中の SES の表示 ID を指定してください）。何も変えていません。');
                return 1;
            }
            $moveTo = (int) $moveTo;
        }
        $plan = SamplePurge::plan($moveTo);

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
            $r = SamplePurge::run($moveTo);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            return 1;
        }
        $this->info('消しました（合計 '.$r['total'].' 件）。');
        $this->line('バックアップ：'.$r['backup']);
        return 0;
    }
}
