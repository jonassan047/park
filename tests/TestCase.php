<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;

abstract class TestCase extends BaseTestCase
{
    // テストは見本データで動かす（各テストの $seed = true で入る）
    protected $seeder = \Database\Seeders\SampleSeeder::class;

    // 案件の登録・編集（POST /cases, /cases/{id}）は、プロバイダーの選択が必須（v0.11.0）。
    // 既存のテストは案件の中身を確かめるのが目的なので、指定が無ければ見本のプロバイダーを足して送る。必須の確かめは $autoProvider = false で行う
    protected bool $autoProvider = true;

    // 管理者が案件を登録するときは、案件を持ち込んだ SES の選択が必須（v0.11.0）。
    // 既存のテストは案件の中身を確かめるのが目的なので、管理者の登録で指定が無ければ、参加中の SES を1つ足して送る。必須の確かめは $autoSource = false で行う
    protected bool $autoSource = true;

    public function post($uri, array $data = [], array $headers = [])
    {
        if ($this->autoSource && is_string($uri) && parse_url($uri, PHP_URL_PATH) === '/cases' && !array_key_exists('source_ses_id', $data) && auth()->user()?->role === 'admin') {
            $sid = DB::table('ses_companies')->where('kind', 'ses')->where('status', 'active')->orderBy('id')->value('id')
                ?: DB::table('ses_companies')->insertGetId(['name' => 'テスト持ち込み SES', 'kind' => 'ses', 'status' => 'active', 'code' => \App\Services\Ids::next('S'),
                    'agreed_at' => now_str(), 'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
            $data['source_ses_id'] = $sid;
        }
        if ($this->autoProvider && is_string($uri) && preg_match('#^/cases(/\d+)?$#', parse_url($uri, PHP_URL_PATH) ?? '') && !array_key_exists('provider_id', $data)) {
            $pid = DB::table('providers')->where('email', 'test-provider@example.test')->value('id')
                ?: DB::table('providers')->insertGetId(['name' => 'テスト提供元', 'sei' => 'テスト', 'email' => 'test-provider@example.test', 'created_at' => now_str(), 'updated_at' => now_str()]);
            $data['provider_id'] = $pid;
        }
        return parent::post($uri, $data, $headers);
    }

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
