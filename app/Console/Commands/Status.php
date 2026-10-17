<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

// PARK の状態をまとめて出す（PARK 担当のエージェントが点検に使う）。
// 名前・メールアドレスなどの個人情報は出さず、数と日時だけを出す。
// 使い方：php artisan park:status   （--json で JSON）
class Status extends Command
{
    protected $signature = 'park:status {--json}';
    protected $description = 'PARK の状態（メール・AI の仕事・選考・確認待ちなど）を数で出す';

    public function handle(): int
    {
        $ago = fn (string $s) => date('Y-m-d H:i:s', strtotime($s));
        $d = [
            '環境' => app()->environment().'／メール：'.config('mail.default').'／AI の合言葉：'.((string) config('services.park_ai.token') !== '' ? 'あり' : 'なし'),
            'メール：送れなかった（7日）' => DB::table('mail_outbox')->where('status', 'failed')->where('created_at', '>=', $ago('-7 days'))->count(),
            'メール：送信待ちのまま' => DB::table('mail_outbox')->where('status', 'queued')->count(),
            'AI：順番待ち' => DB::table('ai_jobs')->where('status', 'queued')->count(),
            'AI：順番待ちで30分以上' => DB::table('ai_jobs')->where('status', 'queued')->where('created_at', '<', $ago('-30 minutes'))->count(),
            'AI：失敗（7日）' => DB::table('ai_jobs')->where('status', 'failed')->where('created_at', '>=', $ago('-7 days'))->count(),
            'AI：下書きが確かめ待ち' => DB::table('ai_jobs')->where('status', 'done')->count(),
            'AI：最後に取りに来た' => DB::table('ai_jobs')->max('picked_at') ?? 'まだ',
            'SES：承認待ち' => DB::table('ses_companies')->where('status', 'pending')->count(),
            'SES：会社の情報の確認がまだ' => DB::table('ses_companies')->where('status', 'active')->whereNull('self_confirmed_at')->count(),
            'エンジニア：本人の確認がまだ' => DB::table('engineers')->whereNull('self_confirmed_at')->count(),
            '案内メール：返事なし（3日以上）' => DB::table('case_mails')->where('reply', 'none')->where('sent_at', '<', $ago('-3 days'))->count(),
            '案内メール：応募したいのに応募になっていない' => DB::table('case_mails as m')->where('m.reply', 'interested')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('applications as a')->whereColumn('a.case_id', 'm.case_id')->whereColumn('a.engineer_id', 'm.engineer_id'))->count(),
            '選考：書類を返して3日以上' => DB::table('applications')->where('status', 'submitted')->where('updated_at', '<', $ago('-3 days'))->count(),
            '選考：面談が終わったのに結果がない' => DB::table('interviews')->where('result', 'pending')->whereNotNull('scheduled_at')->where('scheduled_at', '<', now_str())->count(),
            '契約：1か月以内に終わる' => DB::table('contracts')->where('status', 'active')->where('end_date', '<=', date('Y-m-d', strtotime('+31 days')))->count(),
            '経歴の更新待ち（成約した案件）' => DB::table('contracts')->whereNull('history_updated_at')->count(),
            '技術マスタ：確かめ待ち' => DB::table('skill_terms')->where('reviewed', false)->count(),
            '見本データ（@park.example）' => DB::table('users')->where('email', 'like', '%@park.example')->count(),
        ];
        if ($this->option('json')) {
            $this->line(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return 0;
        }
        foreach ($d as $k => $v) $this->line(str_pad((string) $v, 6, ' ', STR_PAD_LEFT).'  '.$k);
        return 0;
    }
}
