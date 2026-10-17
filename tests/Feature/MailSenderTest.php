<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Notifier as N;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// 管理者の企業名をメールの差出人に使う（PLAN 2026-10-17 の L）
class MailSenderTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const CO = '見本運営株式会社';

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function company(?string $name = self::CO): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['company_name' => $name]);
    }

    private function last(string $kind = null): object
    {
        $q = DB::table('mail_outbox')->orderByDesc('id');
        if ($kind) $q->where('kind', $kind);
        return $q->first();
    }

    // 末尾の署名（「――」の線のあとの1行目）
    private function signature(object $m): string
    {
        return trim(explode("\n", explode("――――――――――\n", $m->body)[1])[0]);
    }

    private function openCase(): int
    {
        $this->as('ses1@park.example')->post('/cases', ['title' => 'Java の保守案件', 'required_skills' => 'Java、SQL', 'price' => 60, 'status' => 'open'])->assertRedirect();
        return (int) DB::table('cases')->where('title', 'Java の保守案件')->value('id');
    }

    public function test_l1_column(): void
    {
        $this->assertTrue(Schema::hasColumn('mail_outbox', 'from_name'));
        $this->assertSame(0, DB::table('mail_outbox')->whereNotNull('from_name')->count());
    }

    public function test_l2_testmail_uses_company(): void
    {
        $this->company();
        $this->as('admin@park.example')->post('/admin/settings/testmail', ['to' => 'eg1@park.example'])->assertRedirect();
        $m = $this->last('test');
        $this->assertSame(self::CO, $m->from_name);
        $this->assertSame(self::CO, $this->signature($m));
        $this->assertStringNotContainsString('PARK 管理者', $m->body);
    }

    public function test_l3_broadcast_and_invite_use_company(): void
    {
        $this->company();
        $this->as('admin@park.example');
        $before = DB::table('mail_outbox')->max('id') ?? 0;
        $this->post('/admin/mail/broadcast', ['target' => 'all', 'subject' => 'お知らせ', 'body' => '本文'])->assertRedirect();
        $rows = DB::table('mail_outbox')->where('id', '>', $before)->get();
        $this->assertGreaterThan(0, $rows->count());
        foreach ($rows as $m) {
            $this->assertSame(self::CO, $m->from_name);
            $this->assertSame(self::CO, $this->signature($m));
        }
        $this->post('/admin/settings/admins', ['name' => '新任 管理', 'email' => 'new-admin@park.example'])->assertRedirect();
        $inv = $this->last('invite');
        $this->assertSame('new-admin@park.example', $inv->to_email);
        $this->assertSame(self::CO, $inv->from_name);
        $this->assertSame(self::CO, $this->signature($inv));
        // SES の招待・再招待も
        $this->post('/admin/ses', ['name' => '新SES', 'email' => 'new-ses@park.example', 'contact_name' => '担当 者'])->assertRedirect();
        $this->assertSame(self::CO, DB::table('mail_outbox')->where('to_email', 'new-ses@park.example')->orderByDesc('id')->value('from_name'));
    }

    public function test_l4_case_mail_admin_vs_ses(): void
    {
        $cid = $this->openCase();
        $this->company();
        $tpl = ['mode' => 'same', 'subject' => '件名', 'body' => '送り主：{送り主}'];
        // SES が送る案内メールは今のまま（from_name は null、署名は運営の名前、{送り主} は「SES名 氏名」）
        $this->as('ses1@park.example')->post("/cases/$cid/mail/preview", $tpl + ['engineer_id' => [1]])->assertOk()->assertSee('送り主：みどり情報システム 緑川 一郎');
        $before = DB::table('mail_outbox')->max('id');
        $this->post("/cases/$cid/mail/send", ['mode' => 'same', 'to' => [1 => ['send' => 1, 'subject' => '件名', 'body' => 'x']]])->assertRedirect();
        $s = DB::table('mail_outbox')->where('id', '>', $before)->where('kind', 'case-mail')->first();
        $this->assertNotNull($s);
        $this->assertNull($s->from_name);
        $this->assertSame('PARK 管理者', $this->signature($s));
        // 管理者が送る案内メール（まだ送っていない相手 = エンジニア 2）
        $this->as('admin@park.example')->post("/cases/$cid/mail/preview", $tpl + ['engineer_id' => [2]])->assertOk()->assertSee('送り主：'.self::CO.' 管理者 太郎');
        $this->post("/cases/$cid/mail/send", ['mode' => 'same', 'to' => [2 => ['send' => 1, 'subject' => '件名', 'body' => '送り主：'.self::CO.' 管理者 太郎']]])->assertRedirect();
        $m = $this->last('case-mail');
        $this->assertNotSame($s->id, $m->id);
        $this->assertSame(self::CO, $m->from_name);
        $this->assertSame(self::CO, $this->signature($m));
    }

    public function test_l5_no_company_is_unchanged(): void
    {
        $this->as('admin@park.example')->post('/admin/settings/testmail', ['to' => 'eg1@park.example'])->assertRedirect();
        $m = $this->last('test');
        $this->assertNull($m->from_name);
        $this->assertSame('PARK 管理者', $this->signature($m));
        // 空白だけの企業名も無いものとして扱う
        $this->company('   ');
        $this->post('/admin/settings/testmail', ['to' => 'eg1@park.example'])->assertRedirect();
        $this->assertNull($this->last('test')->from_name);
        $cid = $this->openCase();
        $this->company(null);
        $this->as('admin@park.example')->post("/cases/$cid/mail/preview", ['mode' => 'same', 'subject' => 'x', 'body' => '送り主：{送り主}', 'engineer_id' => [1]])
            ->assertOk()->assertSee('送り主：PARK 管理者');
        $this->post("/cases/$cid/mail/send", ['mode' => 'same', 'to' => [1 => ['send' => 1, 'subject' => 'x', 'body' => 'y']]])->assertRedirect();
        $m = $this->last('case-mail');
        $this->assertNull($m->from_name);
        $this->assertSame('PARK 管理者', $this->signature($m));
    }

    public function test_l6_automatic_mails_are_unchanged(): void
    {
        $this->company();
        $adminId = User::where('email', 'admin@park.example')->value('id');
        N::notify($adminId, 't', 'b');
        $m = $this->last();
        $this->assertSame('admin@park.example', $m->to_email);
        $this->assertNull($m->from_name);
        $this->assertSame('PARK 管理者', $this->signature($m));
        $this->assertStringContainsString(self::CO.' 管理者 太郎 様', $m->body); // 宛名は L8
        $egId = User::where('email', 'eg1@park.example')->value('id');
        N::notify($egId, 't', 'b');
        $this->assertNull($this->last()->from_name);
    }

    public function test_l7_from_of(): void
    {
        $this->assertSame([config('mail.from.address'), 'A社'], N::fromOf((object) ['from_name' => 'A社']));
        $this->assertNull(N::fromOf((object) ['from_name' => null]));
        $this->assertNull(N::fromOf((object) ['from_name' => '']));
        $this->assertNull(N::fromOf((object) []));
    }

    public function test_l7_deliver_uses_from_name_and_resend_keeps_it(): void
    {
        config(['mail.default' => 'array', 'mail.mailers.array' => ['transport' => 'array']]);
        // array は「保存のみ」扱いなので、配信を直接試すために transport を差し替える
        $id = DB::table('mail_outbox')->insertGetId(['to_email' => 'eg1@park.example', 'to_name' => '山田', 'from_name' => self::CO, 'subject' => 's', 'body' => 'b',
            'kind' => 'test', 'status' => 'queued', 'created_at' => now()->toDateTimeString()]);
        config(['mail.default' => 'smtp']);
        \Illuminate\Support\Facades\Mail::swap(new class {
            public $sent = [];
            public function raw($body, $cb)
            {
                $msg = new class {
                    public $from = null;
                    public function to(...$a) { return $this; }
                    public function subject(...$a) { return $this; }
                    public function replyTo(...$a) { return $this; }
                    public function from(...$a) { $this->from = $a; return $this; }
                };
                $cb($msg);
                $this->sent[] = $msg->from;
            }
        });
        $this->assertTrue(N::deliver($id));
        $sent = \Illuminate\Support\Facades\Mail::getFacadeRoot()->sent;
        $this->assertSame([config('mail.from.address'), self::CO], $sent[0]);
        // 企業名を変えたあとの再送でも、作ったときの from_name のまま
        $this->company('変更後の会社');
        N::deliver($id);
        $this->assertSame([config('mail.from.address'), self::CO], \Illuminate\Support\Facades\Mail::getFacadeRoot()->sent[1]);
        // from_name が null なら from を指定しない
        $id2 = DB::table('mail_outbox')->insertGetId(['to_email' => 'eg1@park.example', 'to_name' => '山田', 'subject' => 's', 'body' => 'b',
            'kind' => 'test', 'status' => 'queued', 'created_at' => now()->toDateTimeString()]);
        N::deliver($id2);
        $this->assertNull(\Illuminate\Support\Facades\Mail::getFacadeRoot()->sent[2]);
    }

    public function test_l8_user_label(): void
    {
        $admin = User::where('email', 'admin@park.example')->first();
        $this->assertSame('PARK 管理者 管理者 太郎', user_label($admin));
        $this->company();
        $this->assertSame(self::CO.' 管理者 太郎', user_label(User::where('email', 'admin@park.example')->first()));
        $this->as('admin@park.example')->get('/admin')->assertSee(self::CO.' 管理者 太郎');
        $this->assertSame('', admin_sender(User::where('email', 'ses1@park.example')->first()) ?? '');
        $this->assertNull(admin_sender(null));
    }

    public function test_l9_mail_screen_shows_sender(): void
    {
        $this->company();
        $this->as('admin@park.example')->post('/admin/settings/testmail', ['to' => 'eg1@park.example']);
        $id = $this->last('test')->id;
        $this->get('/admin/mail')->assertOk()->assertSee('差出人')->assertSee(self::CO);
        $this->get("/admin/mail/$id")->assertOk()->assertSee('差出人')->assertSee(self::CO);
        // from_name が null の行は「既定」
        $nid = DB::table('mail_outbox')->insertGetId(['to_email' => 'x@park.example', 'to_name' => '', 'subject' => 's', 'body' => 'b', 'kind' => 'general', 'status' => 'saved', 'created_at' => now()->toDateTimeString()]);
        $this->get("/admin/mail/$nid")->assertOk()->assertSee('既定');
    }

    public function test_l10_settings_label_and_only_admin(): void
    {
        $this->as('admin@park.example')->get('/admin/settings')->assertOk()->assertSee('運営の名前（企業名が無いとき・自動のメールの署名）');
        // ほかの立場はテストメール・一斉送信を使えない
        $this->as('ses1@park.example')->post('/admin/settings/testmail', ['to' => 'x@park.example'])->assertForbidden();
        $this->post('/admin/mail/broadcast', ['target' => 'all', 'subject' => 'a', 'body' => 'b'])->assertForbidden();
    }
}
