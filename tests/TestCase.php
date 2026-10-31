<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // テストは見本データで動かす（各テストの $seed = true で入る）
    protected $seeder = \Database\Seeders\SampleSeeder::class;

    // テストで作った人（W::createUser など）が、足りない項目の画面で止まらないよう、必須項目と同意を入れて本登録済みの形にする。
    // $userId は users の id。SES の人は担当者として入れる（会社の項目は触らない）
    protected function completeProfile(int $userId, array $extra = []): void
    {
        $u = DB::table('users')->find($userId);
        $upd = ['registered_at' => $u->registered_at ?: now_str(), 'profile_completed_at' => $u->profile_completed_at ?: now_str(), 'phone' => $u->phone ?: '03-0000-0009'];
        if ($u->role === 'admin' && !$u->company_name) $upd['company_name'] = 'テスト企業';
        if ($u->role === 'ses') $upd['secrecy_agreed_at'] = $u->secrecy_agreed_at ?: now_str();
        DB::table('users')->where('id', $userId)->update($extra + $upd);
        if ($u->role === 'eg' && $u->engineer_id) {
            $e = DB::table('engineers')->find($u->engineer_id);
            DB::table('engineers')->where('id', $e->id)->update([
                'phone' => $e->phone ?: '090-0000-0009', 'postal_code' => $e->postal_code ?: '100-0001', 'address' => $e->address ?: '東京都（テスト）',
                'kana_sei' => $e->kana_sei ?: 'てすと', 'kana_mei' => $e->kana_mei ?: 'たろう', 'name_sei' => $e->name_sei ?: 'テスト', 'name_mei' => $e->name_mei ?: '太郎',
                'consent_at' => $e->consent_at ?: now_str(), 'self_confirmed_at' => $e->self_confirmed_at ?: now_str(), 'registered_at' => $e->registered_at ?: now_str(),
            ]);
        }
    }

    // 確認コード（mail_outbox の最新の「確認コード 123456」の件名から読む）
    protected function lastTwoFactorCode(): string
    {
        $subject = (string) DB::table('mail_outbox')->where('subject', 'like', '%確認コード%')->orderByDesc('id')->value('subject');
        preg_match('/(\d{6})/', $subject, $m);
        return $m[1] ?? '';
    }
}
