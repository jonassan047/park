<?php

namespace Tests\Feature;

use App\Services\CaseParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// 取り込み時の働き方（2026-11-23 TEZZO）：フルリモート→remote、一部リモート→hybrid、無ければ onsite（常駐）
class WorkStyleParseTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function ws(string $body): ?string
    {
        return CaseParser::parse("【案件名】在庫管理\n【必須】Java\n".$body)['work_style'] ?? null;
    }

    public function test_full_remote(): void
    {
        $this->assertSame('remote', $this->ws('【場所】フルリモート'));
        $this->assertSame('remote', $this->ws('【場所】完全リモート'));
        $this->assertSame('remote', $this->ws('【場所】リモート100%'));
        $this->assertSame('remote', $this->ws('【場所】ﾌﾙﾘﾓｰﾄ'));
    }

    public function test_partial_remote(): void
    {
        $this->assertSame('hybrid', $this->ws('【場所】一部リモート（週2出社）'));
        $this->assertSame('hybrid', $this->ws('【場所】品川 リモート併用'));
        $this->assertSame('hybrid', $this->ws("【場所】フルリモート不可\n一部リモート"));
    }

    public function test_otherwise_onsite(): void
    {
        $this->assertSame('onsite', $this->ws('【場所】品川'));
        $this->assertSame('onsite', $this->ws('【場所】リモート可'));
        $this->assertSame('onsite', $this->ws('【場所】フルリモート不可'));
        $this->assertSame('onsite', $this->ws('【場所】一部リモートNG'));
    }
}
