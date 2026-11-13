<?php

namespace App\Console\Commands;

use App\Services\CaseThread;
use App\Services\Ids;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// PARK の状態をまとめて出す（PARK 担当のエージェントが点検に使う）。
// 名前・メールアドレスなどの個人情報は出さず、数と日時だけを出す。
// 使い方：php artisan park:status   （--json で JSON）
class Status extends Command
{
    // 表示 ID（CA-000012 など）が空のままの行の数。ふだんは 0（管理者・担当者・会社・エンジニア・案件・応募・契約・発行済みの請求書の合計）
    const ID_KEY = 'ID：ID の無い行（0 のはず）';

    protected $signature = 'park:status {--json}';
    protected $description = 'PARK の状態（メール・AI の仕事・選考・確認待ちなど）を数で出す';

    // 仮登録 → 本登録の状態ごとの人数。名前・メールアドレスは出さない（user_state() の決まりと同じ順で数える）
    private function registrationCounts(): array
    {
        $u = fn () => DB::table('users');
        $live = fn () => $u()->whereNull('left_at')->where('active', 1);
        $prov = fn () => $live()->whereNull('registered_at');
        $expired = fn ($q) => $q->where(fn ($w) => $w->whereNull('token')->orWhereNull('token_expires')->orWhere('token_expires', '<=', now_str()));
        $declined = $prov()->whereNotNull('reg_declined_at')->count();
        $expiredN = $expired($prov()->whereNull('reg_declined_at'))->count();
        return [
            'アカウント：参加中' => $live()->whereNotNull('registered_at')->count(),
            'アカウント：仮登録' => $prov()->whereNull('reg_declined_at')->count() - $expiredN,
            'アカウント：仮登録（期限切れ）' => $expiredN,
            'アカウント：仮登録（心当たりなし）' => $declined,
            'アカウント：停止' => $u()->whereNull('left_at')->where('active', 0)->count(),
            'アカウント：退会済' => $u()->whereNotNull('left_at')->count(),
            'SES：SES のいない会社（申込中を除く）' => DB::table('ses_companies')->where('kind', 'ses')->where('status', '!=', 'pending')->whereNull('rep_user_id')->count(),
            'アカウント：パスワードがあるのに本登録の日時が無い（0 のはず）' => $u()->whereNotNull('password')->whereNull('registered_at')->count(),
        ];
    }

    // ID の無い行の数。マイグレーション前（表や列が無い）は、落ちずに「未反映」と出す
    private function missingIds(): int|string
    {
        try {
            if (!Schema::hasTable('id_counters')) return '未反映';
            return array_sum(Ids::missingCounts());
        } catch (\Throwable $e) {
            return '未反映';
        }
    }

    // 案件のやり取りの数え。マイグレーション前（表が無い）は、落ちずに「未反映」と出す
    private function staleThreads(): int|string
    {
        try {
            return Schema::hasTable('case_threads') ? CaseThread::staleCount() : '未反映';
        } catch (\Throwable $e) {
            return '未反映';
        }
    }

    // v0.10.1 より前の形で続く1本の数（持ち込み元と相手が違う）。列が無い（マイグレーション前）は「未反映」
    private function legacyThreads(): int|string
    {
        try {
            return Schema::hasColumn('case_threads', 'partner_ses_id') ? CaseThread::legacyCount() : '未反映';
        } catch (\Throwable $e) {
            return '未反映';
        }
    }

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
            'SES：承認待ち' => DB::table('ses_companies')->where('kind', 'ses')->where('status', 'pending')->count(),
            'エンジニア：本人の確認がまだ' => DB::table('engineers')->whereNull('self_confirmed_at')->count(),
            'エンジニア：管理者の直属' => DB::table('engineers')->whereIn('ses_id', DB::table('ses_companies')->where('kind', 'direct')->select('id'))->count(),
            '案内メール：返事なし（3日以上）' => DB::table('case_mails')->where('reply', 'none')->where('sent_at', '<', $ago('-3 days'))->count(),
            '案件のやり取り：返事のない EG の発言（'.CaseThread::STALE_DAYS.'日以上）' => $this->staleThreads(),
            '案件のやり取り：v0.10.1 より前の形で続く1本（持ち込み元と相手が違う）' => $this->legacyThreads(),
            '案内メール：応募したいのに応募になっていない' => DB::table('case_mails as m')->where('m.reply', 'interested')
                ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('applications as a')->whereColumn('a.case_id', 'm.case_id')->whereColumn('a.engineer_id', 'm.engineer_id'))->count(),
            '選考：書類を返して3日以上' => DB::table('applications')->where('status', 'submitted')->where('updated_at', '<', $ago('-3 days'))->count(),
            '選考：面談が終わったのに結果がない' => DB::table('interviews')->where('result', 'pending')->whereNotNull('scheduled_at')->where('scheduled_at', '<', now_str())->count(),
            '契約：1か月以内に終わる' => DB::table('contracts')->where('status', 'active')->where('end_date', '<=', date('Y-m-d', strtotime('+31 days')))->count(),
            '経歴の更新待ち（成約した案件）' => DB::table('contracts')->whereNull('history_updated_at')->count(),
            '技術マスタ：確かめ待ち' => DB::table('skill_terms')->where('reviewed', false)->count(),
            '兼任（SES 担当者）' => DB::table('users')->where('role', 'ses')->whereNotNull('ses_id')->whereNotNull('engineer_id')->count(),
            '兼任（管理者）' => DB::table('users')->where('role', 'admin')->whereNotNull('engineer_id')->count(),
            self::ID_KEY => $this->missingIds(),
            '見本データ（@park.example）' => DB::table('users')->where('email', 'like', '%@park.example')->count(),
        ] + $this->registrationCounts();
        if ($this->option('json')) {
            $this->line(json_encode($d, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
            return 0;
        }
        foreach ($d as $k => $v) $this->line(str_pad((string) $v, 6, ' ', STR_PAD_LEFT).'  '.$k);
        return 0;
    }
}
