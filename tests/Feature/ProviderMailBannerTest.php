<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 担当者の画面の上に出る「プロバイダーからメールが届きました」の帯
class ProviderMailBannerTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    const TEXT = 'プロバイダーからメールが届きました';

    private int $ses1;

    protected function setUp(): void
    {
        parent::setUp();
        $this->ses1 = (int) DB::table('users')->where('email', 'ses1@park.example')->value('ses_id');
    }

    // 案件と、プロバイダーからの返事（紐付け済み・未読）を 1 件作って、返事の id を返す
    private function mk(string $title = '架空の案件帯', array $over = []): int
    {
        $cid = (int) DB::table('cases')->insertGetId(['title' => $title, 'status' => 'open', 'source_ses_id' => $this->ses1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        return (int) DB::table('inbound_mails')->insertGetId(array_merge([
            'source' => 'file', 'raw_hash' => bin2hex(random_bytes(16)), 'fetched_at' => now_str(), 'received_at' => now_str(),
            'from_email' => 'prov@example.test', 'from_name' => '', 'subject' => 'Re: 架空', 'body_text' => '架空の返事です。',
            'status' => 'linked', 'case_id' => $cid, 'ses_id' => $this->ses1, 'sender_kind' => 'provider',
        ], $over));
    }

    public function test_banner_shows_and_goes_away_when_read(): void
    {
        $id = $this->mk();
        $this->as('ses1@park.example')->get('/ses')->assertOk()->assertSee(self::TEXT)->assertSee('1 件')->assertSee('架空の案件帯')
            ->assertSee(url('/inbound/'.$id), false)->assertDontSee('prov@example.test');
        $this->get('/inbound/'.$id)->assertOk();
        $this->get('/ses')->assertOk()->assertDontSee(self::TEXT);
    }

    public function test_many_go_to_list_and_exclude_auto_and_eg(): void
    {
        $this->mk('架空の案件A');
        $this->mk('架空の案件B');
        $this->mk('架空の自動', ['auto' => 'auto']);
        $this->mk('架空のEG', ['sender_kind' => 'eg']);
        $this->as('ses1@park.example')->get('/ses')->assertSee('2 件')->assertSee(url('/inbound'), false);
    }

    public function test_not_shown_to_other_company_engineer_or_admin(): void
    {
        $this->mk();
        $this->as('ses2@park.example')->get('/ses')->assertOk()->assertDontSee(self::TEXT);
        $this->as('eg1@park.example')->get('/eg')->assertOk()->assertDontSee(self::TEXT);
        $this->as('admin@park.example')->get('/admin')->assertOk()->assertDontSee(self::TEXT);
    }
}
