<?php

namespace Tests\Feature;

use App\Services\Todo;
use Tests\TestCase;

// 2026-11-28 TEZZO：SES の「今やること」はダッシュボードから外し、その件が起きた過程のタブの画面に出す
class V0230SesDashboardTest extends TestCase
{
    public function test_todo_area_by_url(): void
    {
        $this->assertSame('cases', Todo::area('/cases/3#mails'));
        $this->assertSame('cases', Todo::area('/cases/3/mail'));
        $this->assertSame('cases', Todo::area('/cases/3/threads/9'));
        $this->assertSame('cases', Todo::area('/provider-mails/2'));
        $this->assertSame('apps', Todo::area('/applications/5'));
        $this->assertSame('apps', Todo::area('/interviews/7'));
        $this->assertSame('contracts', Todo::area('/ses/invoices'));
        $this->assertSame('engineers', Todo::area('/engineers/4'));
        $this->assertSame('contact', Todo::area('/inbound/1'));
        $this->assertSame('contact', Todo::area('/casesx'));
        $this->assertSame([], Todo::sesArea(null, 'cases'));
    }
}
