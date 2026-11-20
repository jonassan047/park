<?php

namespace App\Console\Commands;

use App\Services\Ids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

// デモのやり直し用：1 つの案件と 1 人のエンジニアについて、案内メール・返事・プロバイダー宛の下書きと送信・受信メール・合否・お知らせの記録を消し、
// 案内の送信から通しをやり直せる状態に戻す。案件・エンジニア・プロバイダー自体は消さない。--yes が無ければ、見るだけ（何も変えない）。
// 使い方：php artisan park:demo-reset CA-000002 EG-000001            （消す件数を見る）
//         php artisan park:demo-reset CA-000002 EG-000001 --yes      （バックアップを書き出してから消す）
class DemoReset extends Command
{
    protected $signature = 'park:demo-reset {case : 案件の表示 ID（例 CA-000002）} {engineer : エンジニアの表示 ID（例 EG-000001）} {--yes : 実際に消す}';
    protected $description = 'デモ用に、1 つの案件×1 人のエンジニアの案内・返事・送信・受信・合否の記録を消す（--yes が無ければ、件数を見るだけ）';

    // 取り違え防止：この件数より多く消すことになるときは止まる
    private const MAX_CASE_MAILS = 5;

    public function handle(): int
    {
        $caseCode = Ids::normalize((string) $this->argument('case'));
        $egCode = Ids::normalize((string) $this->argument('engineer'));
        $case = $caseCode ? DB::table('cases')->where('code', $caseCode)->first() : null;
        $eg = $egCode ? DB::table('engineers')->where('code', $egCode)->first() : null;
        if (!$case || !$eg) {
            $this->error('案件かエンジニアの表示 ID が見つかりません。何も変えていません。');
            return 1;
        }

        $cmIds = DB::table('case_mails')->where('case_id', $case->id)->where('engineer_id', $eg->id)->pluck('id')->all();
        if (count($cmIds) > self::MAX_CASE_MAILS) {
            $this->error('案内メールが '.count($cmIds).' 件あり、デモ用としては多すぎます。取り違えを防ぐため、何も変えていません。');
            return 1;
        }
        $pmIds = DB::table('provider_mails')->where('case_id', $case->id)->where('engineer_id', $eg->id)->pluck('id')->all();
        $inbound = DB::table('inbound_mails')->where('case_id', $case->id)->where('engineer_id', $eg->id)->get(['id', 'raw_path']);
        $inIds = $inbound->pluck('id')->all();
        $tokens = DB::table('reply_tokens')->where('case_id', $case->id)->where('engineer_id', $eg->id)->pluck('id')->all();
        $outIds = array_values(array_unique(array_filter(array_merge(
            $cmIds ? DB::table('case_mails')->whereIn('id', $cmIds)->pluck('outbox_id')->all() : [],
            $pmIds ? DB::table('provider_mails')->whereIn('id', $pmIds)->pluck('outbox_id')->all() : [],
        ))));
        $offerIds = DB::table('case_offers')->where('case_id', $case->id)->where('engineer_id', $eg->id)->pluck('id')->all();

        // お知らせ：その案件の画面か、この案件の受信メール・下書きへのリンクを持つもの
        $notes = DB::table('app_notifications')->where(function ($q) use ($case, $inIds, $pmIds) {
            $q->where('link', 'like', '/cases/'.$case->id.'%');
            foreach ($inIds as $i) $q->orWhere('link', 'like', '/inbound/'.$i.'%');
            foreach ($pmIds as $i) $q->orWhere('link', 'like', '/provider-mails/'.$i.'%');
        })->pluck('id')->all();

        $plan = [
            'case_mails' => $cmIds, 'case_offers' => $offerIds, 'provider_mails' => $pmIds, 'inbound_mails' => $inIds,
            'reply_tokens' => $tokens, 'mail_outbox' => $outIds, 'app_notifications' => $notes,
        ];
        $this->line('案件 '.$case->code.' × エンジニア '.$eg->code.'：消す件数');
        foreach ($plan as $t => $ids) $this->line('  '.str_pad($t, 20).count($ids));
        $this->line('  （案件・エンジニア・プロバイダー・監査記録は消しません）');

        if (!$this->option('yes')) {
            $this->info('見るだけです。実際に消すときは --yes を付けてください。');
            return 0;
        }

        // バックアップ（消す行をそのまま JSON に残す）
        $backup = [];
        foreach ($plan as $t => $ids) $backup[$t] = $ids ? DB::table($t)->whereIn('id', $ids)->get()->all() : [];
        $path = 'backups/demo-reset-'.date('Ymd-His').'.json';
        Storage::disk('local')->put($path, json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        DB::transaction(function () use ($plan) {
            foreach (['reply_tokens', 'app_notifications', 'inbound_mails', 'provider_mails', 'case_mails', 'case_offers', 'mail_outbox'] as $t) {
                if ($plan[$t]) DB::table($t)->whereIn('id', $plan[$t])->delete();
            }
        });
        foreach ($inbound as $m) if ($m->raw_path) Storage::disk('local')->delete($m->raw_path);

        $this->info('消しました。バックアップ：storage/app/'.$path);
        return 0;
    }
}
