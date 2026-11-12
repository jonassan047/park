<?php

namespace Tests\Feature;

use App\Services\Notifier as N;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// 見本データ（@park.example）宛てのメールは、受け皿のアドレスに届け、本来の宛先を件名と本文に書く
class DummyMailboxTest extends TestCase
{
    use RefreshDatabase;

    private function sent(string $to): array
    {
        config(['mail.default' => 'smtp', 'park.dummy_mailbox' => 'box@example.test']);
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $cb) use (&$cap) {
            $msg = new class {
                public $to; public $subject;
                public function to($a, $n = null) { $this->to = $a; return $this; }
                public function subject($s) { $this->subject = $s; return $this; }
                public function replyTo($a) { return $this; }
                public function from($a, $n = null) { return $this; }
            };
            $cb($msg);
            $cap = ['to' => $msg->to, 'subject' => $msg->subject, 'body' => $body];
        });
        N::queueMail($to, '見本 太郎', '件名', '本文です');
        return $cap;
    }

    public function test_dummy_address_goes_to_the_mailbox(): void
    {
        $r = $this->sent('taro@park.example');
        $this->assertSame('box@example.test', $r['to']);
        $this->assertStringContainsString('[ダミー宛：taro@park.example]', $r['subject']);
        $this->assertStringContainsString('本来の宛先：見本 太郎 <taro@park.example>', $r['body']);
        $this->assertStringContainsString('本文です', $r['body']);
    }

    public function test_real_address_is_untouched(): void
    {
        $r = $this->sent('real@example.com');
        $this->assertSame('real@example.com', $r['to']);
        $this->assertStringNotContainsString('ダミー宛', $r['subject']);
    }
}
