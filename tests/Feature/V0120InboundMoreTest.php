<?php

namespace Tests\Feature;

use App\Contracts\MailSource;
use App\Models\User;
use App\Services\CaseMail;
use App\Services\Inbound;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// v0.12.0 の追加の確かめ（tester。V0120InboundTest の補い）：L1 担当者の差出人／L2 伏せ字の揺れ／L8 別案件での紐付け／コマンド経路／IMAP 空
class V0120InboundMoreTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private int $eg1;
    private int $eg2;
    private int $caseId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mail.from.address' => 'park@example.test']);
        DB::table('applications')->update(['status' => 'rejected']);
        $this->eg1 = $this->engId('eg1@park.example');
        $this->eg2 = $this->engId('eg2@park.example');
        $this->caseId = $this->mkCase((int) DB::table('users')->where('email', 'ses1@park.example')->value('ses_id'), '架空の案件追加');
    }

    private function mkCase(int $source, string $title): int
    {
        $cid = (int) DB::table('cases')->insertGetId(['title' => $title, 'status' => 'open', 'source_ses_id' => $source, 'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('cases')->where('id', $cid)->update(['code' => \App\Services\Ids::next('C')]);
        return $cid;
    }

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function send(string $sesEmail, int $cid, int $eid): object
    {
        CaseMail::send(User::find($this->uid($sesEmail)), DB::table('cases')->find($cid), [$eid => ['subject' => '案件のご案内', 'body' => '架空の本文']], 'same');
        return DB::table('case_mails')->where('case_id', $cid)->where('engineer_id', $eid)->first();
    }

    private function tok(object $cm): string
    {
        return DB::table('reply_tokens')->where('case_mail_id', $cm->id)->value('token');
    }

    private function eml(string $from, string $to, string $subject, string $body): string
    {
        $h = ['From' => $from, 'To' => $to, 'Subject' => $subject, 'Date' => date('r'), 'Message-ID' => '<'.bin2hex(random_bytes(8)).'@mail.example.test>',
            'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => '8bit'];
        $lines = [];
        foreach ($h as $k => $v) $lines[] = "$k: $v";
        return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
    }

    private function notes(string $email, string $like): int
    {
        return DB::table('app_notifications')->where('user_id', $this->uid($email))->where('title', 'like', $like)->count();
    }

    // L1：差出人がその会社の担当者（SES 本人・別の担当者）のとき、題は「担当者から…」。EG からのときは EG の名前
    public function test_l1_sender_is_staff_of_the_company(): void
    {
        $sid = (int) DB::table('users')->where('email', 'ses1@park.example')->value('ses_id');
        $uid = W::createUser('staff-l1@example.test', '担当 三郎', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        $cm = $this->send('ses1@park.example', $this->caseId, $this->eg1);
        $to = 'park+'.$this->tok($cm).'@example.test';
        Inbound::ingest($this->eml('staff-l1@example.test', $to, 'Re: 案内', '全員に返信しました。'), 'file');
        Inbound::ingest($this->eml('ses1@park.example', $to, 'Re: 案内 2', '私も返信しました。'), 'file');
        $this->assertSame(2, $this->notes('ses1@park.example', '担当者から案内メールへの返信%'));
        $this->assertSame(2, $this->notes('staff-l1@example.test', '担当者から案内メールへの返信%'));
        $egName = DB::table('engineers')->where('id', $this->eg1)->value('name');
        $this->assertSame(0, $this->notes('ses1@park.example', "{$egName} さんから%"));
        // EG 本人から来たものは、EG の名前の題（取り違えない）
        $egMail = Inbound::engineerEmails($this->eg1)[0];
        Inbound::ingest($this->eml($egMail, $to, 'Re: 案内 3', '応募したいです。'), 'file');
        $this->assertSame(1, $this->notes('ses1@park.example', "{$egName} さんから案内メールへの返信%"));
    }

    // L2：1 字の姓＋さん、大文字のメール、電話の形違い（他社の SES から見ると伏せる）。1 字だけでは他の語を壊さない
    public function test_l2_masking_variants(): void
    {
        DB::table('engineers')->where('id', $this->eg2)->update(['name' => '林 太郎', 'name_sei' => '林', 'name_mei' => '太郎', 'email' => 'taro.hayashi@example.test', 'phone' => '090-1111-2222']);
        $cm = $this->send('ses1@park.example', $this->caseId, $this->eg2);
        $body = "林さんです。林檎が好きです。\nMAIL: TARO.HAYASHI@EXAMPLE.TEST\nTEL1: 09011112222\nTEL2: 090 1111 2222\nTEL3: +81 90-1111-2222\nTEL4: ０９０－１１１１－２２２２";
        $r = Inbound::ingest($this->eml('taro.hayashi@example.test', 'park+'.$this->tok($cm).'@example.test', 'Re: 案内', $body), 'file');
        $res = $this->as('ses1@park.example')->get('/inbound/'.$r['id'])->assertOk();
        $res->assertSee('林檎');
        foreach (['林さん', 'TARO.HAYASHI', 'taro.hayashi', '09011112222', '090 1111 2222', '90-1111-2222', '０９０－１１１１－２２２２'] as $leak) $res->assertDontSee($leak);
        // 一覧にも漏れない
        $this->get('/inbound')->assertOk()->assertDontSee('taro.hayashi')->assertDontSee('林 太郎');
    }

    // L8：探した案件と違う案件の案内メールでの紐付けを断る（管理者の操作）
    public function test_l8_link_to_other_case_mail_is_refused(): void
    {
        $cm = $this->send('ses1@park.example', $this->caseId, $this->eg1);
        $other = $this->mkCase((int) DB::table('users')->where('email', 'ses2@park.example')->value('ses_id'), '架空の別案件');
        $cmOther = $this->send('ses2@park.example', $other, $this->eg2);
        $r = Inbound::ingest($this->eml('stranger@example.test', 'park@example.test', '知らない', '本文'), 'file');
        $this->as('admin@park.example')->post('/inbound/'.$r['id'].'/link', ['case_mail_id' => $cmOther->id, 'case_id' => $this->caseId])
            ->assertSessionHas('flash.type', 'bad');
        $this->assertSame('unlinked', DB::table('inbound_mails')->find($r['id'])->status);
        $this->assertNull(DB::table('inbound_mails')->find($r['id'])->case_mail_id);
        $this->assertSame(0, $this->notes('ses2@park.example', '%案内メールへの返信%'));
        // 存在しない案内メールも断る
        $this->post('/inbound/'.$r['id'].'/link', ['case_mail_id' => 999999])->assertSessionHas('flash.type', 'bad');
        // 正しい組み合わせは通る（対照）
        $this->post('/inbound/'.$r['id'].'/link', ['case_mail_id' => $cm->id, 'case_id' => $this->caseId])->assertSessionHas('flash.type', 'ok');
    }

    // コマンド経路：park:mail-ingest で .eml を渡すと紐付き、知らせも出る
    public function test_command_ingest_links_by_token(): void
    {
        $cm = $this->send('ses1@park.example', $this->caseId, $this->eg1);
        $path = tempnam(sys_get_temp_dir(), 'eml');
        file_put_contents($path, $this->eml(Inbound::engineerEmails($this->eg1)[0], 'park+'.$this->tok($cm).'@example.test', 'Re: 案内', 'コマンドから。'));
        $this->artisan('park:mail-ingest', ['path' => $path])->assertExitCode(0);
        $m = DB::table('inbound_mails')->first();
        @unlink($path);
        $this->assertSame(['linked', 'token', (int) $cm->id], [$m->status, $m->match_by, (int) $m->case_mail_id]);
        $this->assertSame(1, $this->notes('ses1@park.example', '%さんから案内メールへの返信%'));
        // 同じ .eml をもう一度渡しても 1 件のまま
        $path = tempnam(sys_get_temp_dir(), 'eml');
        file_put_contents($path, $m->raw_path ? Storage::disk('local')->get($m->raw_path) : '');
        $this->artisan('park:mail-ingest', ['path' => $path])->assertExitCode(0);
        @unlink($path);
        $this->assertSame(1, DB::table('inbound_mails')->count());
        // 無いファイルはエラー終了（落ちない）
        $this->artisan('park:mail-ingest', ['path' => '/no/such/file.eml'])->assertExitCode(1);
    }

    // IMAP の .env が空：park:mail-fetch は MailSource に触れず、何も記録しない
    public function test_mail_fetch_with_empty_imap_touches_nothing(): void
    {
        config(['park.inbound.host' => '', 'park.inbound.user' => '', 'park.inbound.password' => '']);
        $this->app->bind(MailSource::class, fn () => throw new \RuntimeException('IMAP に触れてはいけない'));
        $this->artisan('park:mail-fetch')->expectsOutput('受信の設定がありません（.env の PARK_IMAP_*）。')->assertExitCode(0);
        $this->artisan('park:mail-fetch', ['--help' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('inbound_mails')->count());
        $this->assertNull(DB::table('settings')->where('key', 'inbound_last_uid')->value('value'));
    }

    // 立場ごとの権限（管理者・SES・担当者・EG・他社 SES）
    public function test_roles_matrix(): void
    {
        $sid = (int) DB::table('users')->where('email', 'ses1@park.example')->value('ses_id');
        $uid = W::createUser('staff-m@example.test', '担当 四郎', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        $cm = $this->send('ses1@park.example', $this->caseId, $this->eg1);
        $r = Inbound::ingest($this->eml(Inbound::engineerEmails($this->eg1)[0], 'park+'.$this->tok($cm).'@example.test', 'Re', '返信です。'), 'file');
        $id = $r['id'];
        $un = Inbound::ingest($this->eml('stranger@example.test', 'park@example.test', '未紐付け', '本文'), 'file')['id'];
        // 管理者
        $this->as('admin@park.example')->get('/admin/inbound')->assertOk();
        $this->get("/inbound/$id/raw")->assertOk();
        // 持ち込み元の SES・担当者
        foreach (['ses1@park.example', 'staff-m@example.test'] as $e) {
            $this->as($e)->get('/inbound')->assertOk();
            $this->get("/inbound/$id")->assertOk();
            $this->get("/inbound/$un")->assertNotFound();
            $this->get('/admin/inbound')->assertForbidden();
            $this->get("/inbound/$id/raw")->assertForbidden();
            $this->post("/inbound/$un/link", ['case_mail_id' => $cm->id])->assertForbidden();
            $this->post("/inbound/$id/unlink")->assertForbidden();
            $this->post("/inbound/$un/ignore")->assertForbidden();
            $this->post("/inbound/$un/restore")->assertForbidden();
            $this->post('/admin/inbound/fetch')->assertForbidden();
        }
        // 他社 SES
        $this->as('ses2@park.example')->get("/inbound/$id")->assertNotFound();
        $this->post("/inbound/$id/unlink")->assertForbidden();
        $this->get('/admin/inbound')->assertForbidden();
        // EG
        $this->as('eg1@park.example')->get('/inbound')->assertForbidden();
        $this->get("/inbound/$id")->assertForbidden();
        $this->post("/inbound/$un/link", ['case_mail_id' => $cm->id])->assertForbidden();
        // 操作が通っていない（状態は変わっていない）
        $this->assertSame('linked', DB::table('inbound_mails')->find($id)->status);
        $this->assertSame('unlinked', DB::table('inbound_mails')->find($un)->status);
        // ログインなし
        auth()->logout();
        $this->flushSession();
        $this->get('/inbound')->assertRedirect();
    }
}
