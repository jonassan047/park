<?php

namespace App\Console\Commands;

use App\Services\Notifier as N;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// ある SES（会社）に属するエンジニア・担当者などを、別の SES に付け替え、元の SES を退会済にする（一回限りの作業用。2026-11-16）。
// 既定は見るだけ（--yes で実行）。バックアップは storage/app/backups/move-ses-*.json。メールは送らない。
// 例：php artisan park:move-ses 21 10          （見るだけ）
//     php artisan park:move-ses 21 10 --yes    （付け替えて、21 を退会済にする）
class MoveSes extends Command
{
    protected $signature = 'park:move-ses {from : 付け替え元の SES の id} {to : 付け替え先の SES の id} {--yes : 実際に実行する} {--restore : いちばん新しいバックアップから戻す（元の SES も参加中に戻す）}';
    protected $description = 'SES の所属（エンジニア・担当者・案件・契約など）を別の SES に付け替え、元の SES を退会済みにする';

    const COLUMNS = [
        'engineers' => ['ses_id'], 'users' => ['ses_id'], 'applications' => ['ses_id'], 'contracts' => ['ses_id'],
        'case_threads' => ['ses_id', 'partner_ses_id'], 'cases' => ['source_ses_id'], 'case_mails' => ['ses_id'],
        'case_searches' => ['ses_id'], 'direct_mails' => ['from_ses_id'],
    ];

    public function handle(): int
    {
        $dir = storage_path('app/backups');
        if ($this->option('restore')) return $this->restore($dir);

        $from = (int) $this->argument('from');
        $to = (int) $this->argument('to');
        $f = DB::table('ses_companies')->find($from);
        $t = DB::table('ses_companies')->find($to);
        if (!$f || !$t || $from === $to) return $this->stopWith('付け替え元・先が見つからない、または同じです。');
        if ($f->kind !== 'ses' || $t->kind !== 'ses') return $this->stopWith('kind=ses の会社だけが対象です。');
        if ($t->status !== 'active') return $this->stopWith('付け替え先が参加中ではありません。');
        if ($f->status !== 'active') return $this->stopWith('付け替え元が参加中ではありません。');
        if (DB::table('contracts')->where('ses_id', $from)->where('status', 'active')->exists()) return $this->stopWith('付け替え元に稼働中の契約があります。止めました。');
        if (Schema::hasTable('invoices') && DB::table('invoices')->where('ses_id', $from)->exists()) return $this->stopWith('付け替え元あての請求書があります。止めました。');
        if (DB::table('users')->where('role', 'admin')->where('ses_id', $from)->exists()) return $this->stopWith('付け替え元を兼ねている管理者がいます。止めました。');
        if (DB::table('users')->where('ses_id', $from)->whereNotIn('role', ['eg', 'ses'])->exists()) return $this->stopWith('付け替え元に、エンジニア・担当者以外のアカウントがあります。止めました。');
        if (Schema::hasTable('case_threads') && DB::table('case_threads as a')->where('a.ses_id', $from)
            ->whereExists(fn ($q) => $q->select(DB::raw(1))->from('case_threads as b')->whereColumn('b.case_id', 'a.case_id')->whereColumn('b.engineer_id', 'a.engineer_id')->where('b.ses_id', $to))->exists()) {
            return $this->stopWith('案件のやり取りの1本が、元と先の両方にあるため止めました。');
        }

        $plan = [];
        foreach (self::COLUMNS as $table => $cols) {
            if (!Schema::hasTable($table)) continue;
            foreach ($cols as $col) {
                if (!Schema::hasColumn($table, $col)) continue;
                $ids = DB::table($table)->where($col, $from)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
                if ($ids) $plan["{$table}.{$col}"] = $ids;
            }
        }
        $this->info("付け替え元 #{$from} → 先 #{$to}");
        foreach ($plan as $k => $ids) $this->line(sprintf('  %-28s %d 件', $k, count($ids)));
        if (!$this->option('yes')) { $this->warn('見るだけです。実行するときは --yes を付けます。'); return self::SUCCESS; }

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) return $this->stopWith('バックアップの置き場所を作れませんでした。何も変えていません。');
        $old = [];
        foreach ($plan as $key => $ids) {
            [$table, $col] = explode('.', $key);
            $old[$key] = [];
            foreach (array_chunk($ids, 500) as $chunk) foreach (DB::table($table)->whereIn('id', $chunk)->pluck($col, 'id') as $id => $v) $old[$key][$id] = $v === null ? null : (int) $v;
        }
        $path = $dir.DIRECTORY_SEPARATOR.'move-ses-'.date('Ymd-His').'.json';
        $json = json_encode(['作成' => now_str(), '元' => $from, '先' => $to, '元の状態' => $f->status, '元の値' => $old], JSON_PRETTY_PRINT);
        if ($json === false || file_put_contents($path, $json) === false) return $this->stopWith('バックアップを書き出せませんでした。何も変えていません。');

        DB::transaction(function () use ($plan, $to, $from) {
            foreach ($plan as $key => $ids) {
                [$table, $col] = explode('.', $key);
                foreach (array_chunk($ids, 500) as $chunk) DB::table($table)->whereIn('id', $chunk)->update([$col => $to]);
            }
            DB::table('ses_companies')->where('id', $from)->update(['status' => 'left', 'updated_at' => now_str()]);
        });
        N::audit(null, 'move-ses', "#{$from} → #{$to} ".json_encode(array_map('count', $plan), JSON_UNESCAPED_UNICODE).' '.basename($path));
        $this->info('付け替えて、元の SES を退会済みにしました。バックアップ：'.basename($path));
        return self::SUCCESS;
    }

    private function restore(string $dir): int
    {
        $files = glob($dir.DIRECTORY_SEPARATOR.'move-ses-*.json') ?: [];
        if (!$files) return $this->stopWith('バックアップがありません。');
        sort($files);
        $b = json_decode((string) file_get_contents(end($files)), true);
        if (!is_array($b) || !isset($b['元の値'])) return $this->stopWith('バックアップを読めません。');
        DB::transaction(function () use ($b) {
            foreach ($b['元の値'] as $key => $rows) {
                [$table, $col] = explode('.', $key);
                $by = [];
                foreach ($rows as $id => $v) $by[$v === null ? '' : (string) $v][] = (int) $id;
                foreach ($by as $v => $ids) foreach (array_chunk($ids, 500) as $chunk) DB::table($table)->whereIn('id', $chunk)->update([$col => $v === '' ? null : (int) $v]);
            }
            DB::table('ses_companies')->where('id', $b['元'])->update(['status' => $b['元の状態'], 'updated_at' => now_str()]);
        });
        N::audit(null, 'move-ses-restore', basename(end($files)));
        $this->info('戻しました：'.basename(end($files)));
        return self::SUCCESS;
    }

    private function stopWith(string $m): int
    {
        $this->error($m);
        return self::FAILURE;
    }
}
