<?php

namespace App\Console\Commands;

use App\Services\DummySeed;
use Illuminate\Console\Command;
use RuntimeException;

// テスト用のダミーデータ（SES・担当者・エンジニア）を入れる。--yes が無ければ、予定を見るだけ（何も変えない）。
// 名前・メールアドレス・パスワードは出さず、件数・番号の範囲・seed だけを出す。
// 使い方：php artisan park:seed-dummy                                        （予定を見る）
//         php artisan park:seed-dummy --ses=8 --staff=15 --eg=80 --seed=1 --yes   （入れる）
// 消すときは php artisan park:purge-samples
class SeedDummy extends Command
{
    protected $signature = 'park:seed-dummy {--ses=8 : SES の社数} {--staff=15 : SES の担当者の人数} {--eg=80 : エンジニアの人数} {--direct= : うち管理者の直属の人数（既定は eg の1割）} {--seed= : 乱数の種（同じなら同じ内容）} {--yes : 実際に入れる}';
    protected $description = 'テスト用のダミーデータを入れる（--yes が無ければ、予定を見るだけ）';

    public function handle(): int
    {
        $opt = ['ses' => $this->option('ses'), 'staff' => $this->option('staff'), 'eg' => $this->option('eg'), 'direct' => $this->option('direct'), 'seed' => $this->option('seed')];
        $plan = DummySeed::plan($opt);
        if ($plan['errors']) {
            foreach ($plan['errors'] as $e) $this->error($e);
            $this->error('何も入れずに止めました。');
            return 1;
        }

        $c = $plan['counts'];
        $this->line('種類                     状態                       件数');
        $this->row('SES', $c['ses'], DummySeed::SES_LABEL);
        $this->row('担当者', $c['staff'], DummySeed::STATE_LABEL);
        $this->row('エンジニア（SES 所属）', $c['eg_ses'], DummySeed::STATE_LABEL);
        $this->row('エンジニア（直属）', $c['eg_direct'], DummySeed::STATE_LABEL);
        $this->newLine();
        $this->line('番号の範囲：SES '.$plan['ranges']['ses'].' ／ エンジニア '.$plan['ranges']['eg']);
        $this->line('seed：'.$plan['seed'].'　APP_ENV：'.$plan['env']);
        $this->newLine();

        if (!$this->option('yes')) {
            $this->line('見るだけです（何も変えていません）。入れるときは --yes を付けます。');
            return 0;
        }

        try {
            $r = DummySeed::run(['seed' => $plan['seed']] + $opt);
        } catch (RuntimeException $e) {
            // DB の例外（QueryException）も RuntimeException の子なので、SQL の値を出さないよう定型文にする
            $this->error($e instanceof \PDOException ? '入れている途中で失敗しました（何も入っていません）。' : $e->getMessage());
            return 1;
        } catch (\Throwable $e) {
            // 個人情報が入るおそれがあるので、エラーの中身は出さない
            $this->error('入れている途中で失敗しました（何も入っていません）。');
            return 1;
        }
        $t = $r['total'];
        $this->info("入れました（SES {$t['ses']} 社・担当者 {$t['staff']} 人・エンジニア {$t['eg']} 人）。消すときは php artisan park:purge-samples");
        return 0;
    }

    // 種類ごとに、状態の件数を1行ずつ出す
    private function row(string $kind, array $counts, array $labels): void
    {
        foreach ($counts as $k => $n) {
            $this->line($this->pad($kind, 24).$this->pad($labels[$k], 26).str_pad((string) $n, 4, ' ', STR_PAD_LEFT));
            $kind = '';
        }
    }

    // 全角を2桁と数えて、右に空白を足す
    private function pad(string $s, int $width): string
    {
        return $s.str_repeat(' ', max(0, $width - mb_strwidth($s)));
    }
}
