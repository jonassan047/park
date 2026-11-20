<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseMail;
use App\Services\CaseResult;
use App\Services\Inbound;
use App\Services\Notifier as N;
use App\Services\ProviderReply;
use App\Services\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// v0.13.0 プロバイダーへの返信（R16）と、合否の登録（R17 の最小）。PLAN.md 9章の主な確かめ。人・会社は見本データと、テストの中の架空のものだけ。
// 会社：A＝SES1（案件の持ち込み元）、B＝SES2（他社の EG の所属）。EG1＝A、EG2＝B。プロバイダーは @park.example
class V0130ProviderReplyTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';
    private const EG2 = 'eg2@park.example';
    private const FROM = 'park@example.test';
    private const PV_MAIL = 'provider-x@park.example';

    private int $eg1;
    private int $eg2;
    private int $caseId;
    private int $pvId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mail.from.address' => self::FROM]);
        DB::table('applications')->update(['status' => 'rejected']);
        $this->eg1 = $this->engId(self::EG1);
        $this->eg2 = $this->engId(self::EG2);
        DB::table('engineers')->where('id', $this->eg1)->update(['initials' => 'AB']);
        DB::table('engineers')->where('id', $this->eg2)->update(['initials' => 'CD']);
        $this->pvId = (int) DB::table('providers')->insertGetId(['name' => '架空のプロバイダー株式会社', 'sei' => '架空', 'mei' => '次郎', 'email' => self::PV_MAIL, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->caseId = (int) DB::table('cases')->insertGetId(['title' => '架空の案件V0130', 'status' => 'open', 'source_ses_id' => $this->sesId(self::SES1), 'provider_id' => $this->pvId,
            'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('cases')->where('id', $this->caseId)->update(['code' => \App\Services\Ids::next('C')]);
    }

    // ---------- 道具 ----------
    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function uid(string $email): int
    {
        return (int) $this->user($email)->id;
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    private function enableInbound(): void
    {
        config(['park.inbound.host' => 'imap.example.test', 'park.inbound.user' => 'x', 'park.inbound.password' => 'secret-pass']);
    }

    private function sendMail(string $sesEmail, int $eid): object
    {
        CaseMail::send(User::find($this->uid($sesEmail)), DB::table('cases')->find($this->caseId), [$eid => ['subject' => '案件のご案内', 'body' => '架空の本文']], 'same');
        return DB::table('case_mails')->where('case_id', $this->caseId)->where('engineer_id', $eid)->first();
    }

    private function eml(string $from, string $to, string $subject, string $body, array $extra = []): string
    {
        $h = ['From' => $from, 'To' => $to, 'Subject' => $subject, 'Date' => date('r'), 'Message-ID' => '<'.bin2hex(random_bytes(8)).'@mail.example.test>',
            'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => '8bit'];
        $lines = [];
        foreach (array_merge($h, $extra) as $k => $v) $lines[] = "$k: $v";
        return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
    }

    private function egMail(int $eid): string
    {
        return Inbound::engineerEmails($eid)[0];
    }

    // 案内 → EG の返信を取り込む。戻り：[案内メールの行, 受信メールの id]
    private function reply(int $eid, string $body): array
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $eid);
        $tok = DB::table('reply_tokens')->where('case_mail_id', $cm->id)->first();
        $r = Inbound::ingest($this->eml($this->egMail($eid), 'park+'.$tok->token.'@example.test', 'Re: 案件のご案内', $body), 'file');
        return [$cm, $r['id']];
    }

    private function draft(int $inboundId): ?object
    {
        return DB::table('provider_mails')->where('inbound_mail_id', $inboundId)->first();
    }

    private function notes(string $email, string $like = '%'): int
    {
        return DB::table('app_notifications')->where('user_id', $this->uid($email))->where('title', 'like', $like)->count();
    }

    // ---------- 段階1 ----------
    public function test_plain_mail_has_no_park_mark_or_signature(): void
    {
        $id = N::queueMail('someone@park.example', '架空', '件名', '本文', 'provider-mail', null, '架空会社 太郎', true);
        $m = DB::table('mail_outbox')->find($id);
        $this->assertSame('件名', $m->subject);
        $this->assertStringNotContainsString('PARK から自動', $m->body);
        $this->assertNotEmpty($m->message_id);
        $id2 = N::queueMail('someone@park.example', '架空', '件名', '本文');
        $this->assertStringStartsWith('[', DB::table('mail_outbox')->find($id2)->subject);
        $this->assertStringContainsString('PARK から自動', DB::table('mail_outbox')->find($id2)->body);
    }

    // ---------- 段階2：下書き ----------
    public function test_draft_is_made_from_own_eg_reply_with_initials_and_clean_quote(): void
    {
        $e = DB::table('engineers')->find($this->eg1);
        $body = "応募したいです。{$e->name} です。\n来週なら面接できます。\n\n▼ご返事はこちらから\nhttps://example.test/r/abc/interested\n\n> 前のメール\n-- \n署名";
        [$cm, $iid] = $this->reply($this->eg1, $body);
        $d = $this->draft($iid);
        $this->assertNotNull($d);
        $this->assertSame('draft', $d->status);
        $this->assertSame($this->pvId, (int) $d->provider_id);
        $this->assertSame((int) $cm->id, (int) $d->case_mail_id);
        $this->assertStringContainsString('■エンジニア：AB', $d->body);
        $this->assertStringContainsString('来週なら面接できます。', $d->body);
        $this->assertStringNotContainsString($e->name, $d->body);
        $this->assertStringNotContainsString('https://', $d->body);
        $this->assertStringNotContainsString('前のメール', $d->body);
        $this->assertStringNotContainsString('署名', $d->body);
        $this->assertStringContainsString('架空のプロバイダー株式会社', $d->body);
        // お知らせの本文に、下書きの一文
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->uid(self::SES1))->where('body', 'like', '%下書きを作りました%')->count());
        // 同じ受信メールから 2 つは作らない
        $this->assertNull(ProviderReply::draftFromInbound($iid));
        $this->assertSame(1, DB::table('provider_mails')->count());
        // 今やること
        $this->assertTrue(collect(Todo::ses($this->sesId(self::SES1)))->contains(fn ($t) => str_contains($t['text'], 'プロバイダーへの返信の下書き')));
    }

    public function test_other_company_eg_draft_hides_company_name_and_eg_name(): void
    {
        $e = DB::table('engineers')->find($this->eg2);
        [, $iid] = $this->reply($this->eg2, "{$e->name} です。参画したいです。");
        $d = $this->draft($iid);
        $this->assertNotNull($d);
        $this->assertStringContainsString('■エンジニア：CD', $d->body);
        $this->assertStringNotContainsString($e->name, $d->body);
        $this->assertStringNotContainsString((string) DB::table('ses_companies')->where('id', $e->ses_id)->value('name'), $d->body);
    }

    public function test_no_draft_for_bounce_or_unlinked(): void
    {
        $this->enableInbound();
        $r = Inbound::ingest($this->eml('stranger@example.test', 'park@example.test', '知らない人から', '案件の件です。'), 'file');
        $this->assertNull($this->draft($r['id']));
        $this->assertSame(0, DB::table('provider_mails')->count());
    }

    // ---------- 送る ----------
    public function test_send_makes_one_plain_mail_with_token_and_records(): void
    {
        $this->enableInbound();
        [$cm, $iid] = $this->reply($this->eg1, '参画したいです。');
        $d = $this->draft($iid);
        $before = DB::table('mail_outbox')->count();
        $this->as(self::SES1)->post("/provider-mails/{$d->id}/send")->assertRedirect();
        $this->assertSame($before + 1, DB::table('mail_outbox')->count());
        $mo = DB::table('mail_outbox')->where('kind', 'provider-mail')->first();
        $this->assertSame(self::PV_MAIL, $mo->to_email);
        $this->assertStringNotContainsString('[PARK]', $mo->subject);
        $tok = DB::table('reply_tokens')->where('kind', 'provider')->first();
        $this->assertStringEndsWith('[No.'.substr($tok->token, 0, 10).']', $mo->subject);
        $this->assertStringContainsString('お問い合わせ番号：No.'.substr($tok->token, 0, 10), $mo->body);
        $this->assertStringNotContainsString('PARK から自動', $mo->body);
        $this->assertSame('park+'.$tok->token.'@example.test, '.self::SES1, $mo->reply_to);
        $pm = DB::table('provider_mails')->find($d->id);
        $this->assertSame('sent', $pm->status);
        $this->assertSame((int) $d->id, (int) $tok->provider_mail_id);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'provider-mail-send')->count());
        // 案内メールの返事と結果
        $c = DB::table('case_mails')->find($cm->id);
        $this->assertSame('interested', $c->reply);
        $this->assertSame('pending', $c->result);
        $this->assertSame(1, $this->notes(self::EG1, '先方へご返事を伝えました%'));
        // 2 回目は送らない
        $this->as(self::SES1)->post("/provider-mails/{$d->id}/send");
        $this->assertSame($before + 1, DB::table('mail_outbox')->count());
    }

    public function test_send_without_inbound_replies_to_staff_only(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $id = ProviderReply::draftFromCaseMail($cm->id, User::find($this->uid(self::SES1)));
        $this->as(self::SES1)->post("/provider-mails/$id/send");
        $this->assertSame(self::SES1, DB::table('mail_outbox')->where('kind', 'provider-mail')->value('reply_to'));
    }

    public function test_send_stops_when_eg_name_remains_unless_forced(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $id = ProviderReply::draftFromCaseMail($cm->id, User::find($this->uid(self::SES1)));
        $e = DB::table('engineers')->find($this->eg1);
        DB::table('provider_mails')->where('id', $id)->update(['body' => "{$e->name} さんは参画を希望しています。"]);
        $this->as(self::SES1)->post("/provider-mails/$id/send")->assertRedirect();
        $this->assertSame('draft', DB::table('provider_mails')->find($id)->status);
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', 'provider-mail')->count());
        $this->as(self::SES1)->post("/provider-mails/$id/send", ['force' => 1, 'reply' => 'interested', 'subject' => '【ご返事】x', 'body' => "{$e->name} さんは参画を希望しています。"]);
        $this->assertSame('sent', DB::table('provider_mails')->find($id)->status);
    }

    public function test_send_blocked_without_provider(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $id = ProviderReply::draftFromCaseMail($cm->id, User::find($this->uid(self::SES1)));
        DB::table('cases')->where('id', $this->caseId)->update(['provider_id' => null]);
        $res = ProviderReply::send(User::find($this->uid(self::SES1)), $id, false);
        $this->assertSame('bad', $res['type']);
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', 'provider-mail')->count());
    }

    public function test_draft_from_case_mail_is_reused_and_redraft_resets(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $u = User::find($this->uid(self::SES1));
        $a = ProviderReply::draftFromCaseMail($cm->id, $u);
        $this->assertSame($a, ProviderReply::draftFromCaseMail($cm->id, $u));
        $orig = DB::table('provider_mails')->find($a)->body;
        DB::table('provider_mails')->where('id', $a)->update(['body' => '直した', 'status' => 'discarded']);
        $this->as(self::SES1)->post("/provider-mails/$a/redraft");
        $this->assertSame('draft', DB::table('provider_mails')->find($a)->status);
        $this->assertSame($orig, DB::table('provider_mails')->find($a)->body);
    }

    // ---------- 権限 ----------
    public function test_permissions(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $id = ProviderReply::draftFromCaseMail($cm->id, User::find($this->uid(self::SES1)));
        $this->as(self::SES1)->get("/provider-mails/$id")->assertOk();
        $this->as(self::ADMIN)->get("/provider-mails/$id")->assertOk();
        $this->as(self::ADMIN)->post("/provider-mails/$id/send")->assertForbidden();
        $this->as(self::ADMIN)->post("/provider-mails/$id/discard")->assertForbidden();
        $this->as(self::SES2)->get("/provider-mails/$id")->assertNotFound();
        $this->as(self::SES2)->post("/provider-mails/$id/send")->assertNotFound();
        $this->as(self::EG1)->get("/provider-mails/$id")->assertForbidden();
        $this->assertSame('draft', DB::table('provider_mails')->find($id)->status);
    }

    // ---------- プロバイダーの返事 ----------
    public function test_provider_reply_is_linked_and_notifies_staff(): void
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $id = ProviderReply::draftFromCaseMail($cm->id, User::find($this->uid(self::SES1)));
        $this->as(self::SES1)->post("/provider-mails/$id/send");
        $tok = DB::table('reply_tokens')->where('kind', 'provider')->first();
        $r = Inbound::ingest($this->eml(self::PV_MAIL, 'park+'.$tok->token.'@example.test', 'Re: 【ご返事】x', '合格です。'), 'file');
        $m = DB::table('inbound_mails')->find($r['id']);
        $this->assertSame(['linked', 'provider', (int) $id], [$m->status, $m->sender_kind, (int) $m->provider_mail_id]);
        $this->assertSame($this->eg1, (int) $m->engineer_id);
        $this->assertSame(1, $this->notes(self::SES1, 'プロバイダーから返信%'));
        // 件名の [No.] ＋ 差出人がプロバイダーのとき。別のアドレスなら紐付かない
        $tag = substr($tok->token, 0, 10);
        $ok = Inbound::ingest($this->eml(self::PV_MAIL, 'park@example.test', "Re: 【ご返事】x [No.$tag]", '返事です。'), 'file');
        $this->assertSame('provider', DB::table('inbound_mails')->find($ok['id'])->sender_kind);
        $no = Inbound::ingest($this->eml('other@example.test', 'park@example.test', "Re: 【ご返事】x [No.$tag]", '返事です。'), 'file');
        $this->assertSame('unlinked', DB::table('inbound_mails')->find($no['id'])->status);
        // 差出人の表示
        $this->as(self::SES1)->get('/inbound/'.$r['id'])->assertOk()->assertSee('架空のプロバイダー株式会社（プロバイダー）', false);
        $this->as(self::SES2)->get('/inbound/'.$r['id'])->assertNotFound();
    }

    public function test_unlink_discards_draft(): void
    {
        [, $iid] = $this->reply($this->eg1, '参画したいです。');
        $d = $this->draft($iid);
        Inbound::unlink($this->user(self::ADMIN), $iid);
        $this->assertSame('discarded', DB::table('provider_mails')->find($d->id)->status);
    }

    // デモのやり直し：案内・返事・下書き・送信・受信・お知らせを消し、案件とエンジニアは残す
    public function test_demo_reset_clears_the_flow_but_keeps_case_and_engineer(): void
    {
        $this->enableInbound();
        [$cm, $iid] = $this->reply($this->eg1, "参画したいです。");
        $d = $this->draft($iid);
        $this->as(self::SES1)->post("/provider-mails/{$d->id}/send")->assertRedirect();
        $caseCode = DB::table("cases")->where("id", $this->caseId)->value("code");
        $egCode = DB::table("engineers")->where("id", $this->eg1)->value("code");
        // 見るだけ：何も消えない
        $this->artisan("park:demo-reset", ["case" => $caseCode, "engineer" => $egCode])->assertExitCode(0);
        $this->assertSame(1, DB::table("provider_mails")->count());
        $this->assertSame(1, DB::table("case_mails")->where("id", $cm->id)->count());
        // 実行
        $this->artisan("park:demo-reset", ["case" => $caseCode, "engineer" => $egCode, "--yes" => true])->assertExitCode(0);
        foreach (["provider_mails", "inbound_mails", "reply_tokens", "case_mails"] as $t) $this->assertSame(0, DB::table($t)->count(), $t);
        $this->assertSame(1, DB::table("cases")->where("id", $this->caseId)->count());
        $this->assertSame(1, DB::table("engineers")->where("id", $this->eg1)->count());
        // 続けて、案内の送信からやり直せる
        $cm2 = $this->sendMail(self::SES1, $this->eg1);
        $this->assertNotNull($cm2);
        // 表示 ID が違えば、何もしない
        $this->artisan("park:demo-reset", ["case" => "CA-999999", "engineer" => $egCode, "--yes" => true])->assertExitCode(1);
    }

    // ---------- 合否 ----------
    // 登録のあと、案件の画面にメッセージが出る（画面の上に固定する toast として）
    public function test_result_registration_shows_message_on_case_page(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/result", ["result" => "passed", "note" => ""])->assertSessionHas("flash.msg", "登録しました。");
        $this->get("/cases/{$this->caseId}")->assertOk()->assertSee("flash toast ok", false)->assertSee("登録しました。");
    }

    public function test_result_is_recorded_and_notified_without_memo(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/result", ['result' => 'passed', 'note' => '架空のメモ'])->assertRedirect();
        $c = DB::table('case_mails')->find($cm->id);
        $this->assertSame(['passed', '架空のメモ'], [$c->result, $c->result_note]);
        $this->assertSame(1, $this->notes(self::EG1, '先方から合格の連絡がありました%'));
        $this->assertSame(0, DB::table('app_notifications')->where('body', 'like', '%架空のメモ%')->count());
        // 同じ値をもう一度
        $res = CaseResult::set(User::find($this->uid(self::SES1)), $cm->id, 'passed', '架空のメモ');
        $this->assertSame('warn', $res['type']);
        $this->assertSame(1, $this->notes(self::EG1, '先方から合格の連絡がありました%'));
        // 結果待ちはお知らせなし
        $cm2 = $this->sendMail(self::SES1, $this->eg2);
        CaseResult::set(User::find($this->uid(self::SES1)), $cm2->id, 'pending', null);
        $this->assertSame(0, $this->notes(self::EG2, '先方%'));
    }

    public function test_result_for_other_company_eg_notifies_their_staff(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg2);
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/result", ['result' => 'failed'])->assertRedirect();
        $this->assertSame(1, $this->notes(self::SES2, 'グループの案件の結果%'));
        $this->assertSame(1, $this->notes(self::EG2, '今回は見送りとなりました%'));
    }

    public function test_result_permissions(): void
    {
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $this->as(self::ADMIN)->post("/case-mails/{$cm->id}/result", ['result' => 'passed'])->assertForbidden();
        $this->as(self::SES2)->post("/case-mails/{$cm->id}/result", ['result' => 'passed'])->assertForbidden();
        $this->as(self::EG1)->post("/case-mails/{$cm->id}/result", ['result' => 'passed'])->assertForbidden();
        $this->assertNull(DB::table('case_mails')->find($cm->id)->result);
    }

    // ---------- レビュー指摘の直し ----------
    private function companyOf(int $eid): string
    {
        return (string) DB::table('ses_companies')->where('id', DB::table('engineers')->where('id', $eid)->value('ses_id'))->value('name');
    }

    // H1：EG の署名にある所属の会社名を、下書きから消す（他社の EG も、持ち込み元が自社の EG も）。送る前の検査にも「会社名」
    public function test_company_name_in_eg_signature_is_removed_and_checked(): void
    {
        foreach ([$this->eg2, $this->eg1] as $eid) {
            $company = $this->companyOf($eid);
            [, $iid] = $this->reply($eid, "参画したいです。\n{$company} 営業部\nよろしくお願いします。");
            $d = $this->draft($iid);
            $this->assertNotNull($d);
            $this->assertStringNotContainsString($company, preg_replace('/^いつもお世話になっております。.*$/mu', '', $d->body));
            $this->assertStringContainsString('よろしくお願いします。', $d->body);
            $e = DB::table('engineers')->find($eid);
            $this->assertContains('会社名', ProviderReply::leaks($e, "参画したいです。\n{$company}"));
            $this->assertNotContains('会社名', ProviderReply::leaks($e, $d->body)); // 送る会社の名のり（決まり文句）は数えない
        }
        // 送る前の検査：会社名が残っていれば止める
        $d = DB::table('provider_mails')->orderByDesc('id')->first();
        $company = $this->companyOf($this->eg1);
        DB::table('provider_mails')->where('id', $d->id)->update(['body' => "参画したいです。\n{$company}"]);
        $this->as(self::SES1)->post("/provider-mails/{$d->id}/send");
        $this->assertSame('draft', DB::table('provider_mails')->find($d->id)->status);
    }

    // H2・M2：登録と違うアドレスからの返信（管理者が手で紐付けたものも）は、自動の下書きを作らない
    public function test_no_draft_when_reply_is_from_another_address(): void
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $tok = DB::table('reply_tokens')->where('case_mail_id', $cm->id)->first();
        $r = Inbound::ingest($this->eml('別人 <someone-else@example.test>', 'park+'.$tok->token.'@example.test', 'Re: 案件のご案内', '参画したいです。'), 'file');
        $m = DB::table('inbound_mails')->find($r['id']);
        $this->assertSame('linked', $m->status);
        $this->assertTrue((bool) $m->from_mismatch);
        $this->assertNull($this->draft($r['id']));
        $this->assertNull(ProviderReply::draftFromInbound($r['id']));
        // 手で紐付けた（紐付いていない → 管理者が紐付け）メールも、アドレスが違えば作らない
        $r2 = Inbound::ingest($this->eml(self::PV_MAIL, 'park@example.test', '件名だけ', '合格です。'), 'file');
        $this->assertSame('unlinked', DB::table('inbound_mails')->find($r2['id'])->status);
        Inbound::link($this->user(self::ADMIN), $r2['id'], $cm->id);
        $this->assertNull($this->draft($r2['id']));
        $this->assertSame(0, DB::table('provider_mails')->count());
    }

    // H2：差出人の名前・アドレスも、取り出した文から消す
    public function test_extract_hides_sender_name_and_address(): void
    {
        $e = DB::table('engineers')->find($this->eg1);
        $in = (object) ['from_name' => '架空 別人', 'from_email' => 'someone-else@example.test'];
        $text = ProviderReply::extract("架空 別人 です。Someone-Else@example.test から送っています。", $e, $in);
        $this->assertStringNotContainsString('架空 別人', $text);
        $this->assertStringNotContainsString('example.test', $text);
    }

    // M3：日本語のメールソフトの引用の頭の行から下を捨てる
    public function test_extract_cuts_japanese_quote_headers(): void
    {
        $e = DB::table('engineers')->find($this->eg1);
        foreach (['2026/11/20 10:00、PARK <park@example.test>のメール:', '2026/11/20 10:00、PARK のメール：', '<park@example.test>:', 'PARK <park@example.test>:'] as $head) {
            $text = ProviderReply::extract("参画したいです。\n\n{$head}\n引用された元の文\n", $e);
            $this->assertSame('参画したいです。', $text, $head);
        }
    }

    // L6：伏せてから切る。切る場所に会社名がかかっても、半端に残らない
    public function test_extract_scrubs_before_cutting_length(): void
    {
        $e = DB::table('engineers')->find($this->eg2);
        $company = $this->companyOf($this->eg2);
        $text = ProviderReply::extract(str_repeat('あ', ProviderReply::MAX_QUOTE - 2).$company.'です', $e);
        $this->assertStringNotContainsString($company, $text);
        $this->assertLessThanOrEqual(ProviderReply::MAX_QUOTE + 8, mb_strlen($text));
    }

    // L1：送った・取りやめた下書きを、保存・作り直しで巻き戻さない。L3：送ったら、送った会社を記録する
    public function test_save_and_redraft_respect_status_and_send_records_company(): void
    {
        $u = User::find($this->uid(self::SES1));
        $cm = $this->sendMail(self::SES1, $this->eg1);
        $id = ProviderReply::draftFromCaseMail($cm->id, $u);
        DB::table('provider_mails')->where('id', $id)->update(['ses_id' => $this->sesId(self::SES2)]);
        $this->assertSame('ok', ProviderReply::send($u, $id, false)['type']);
        $pm = DB::table('provider_mails')->find($id);
        $this->assertSame($this->sesId(self::SES1), (int) $pm->ses_id);
        $this->assertSame('bad', ProviderReply::save($u, $id, 'interested', '件名', '本文')['type']);
        $this->assertSame('bad', ProviderReply::redraft($u, $id)['type']);
        $this->assertSame('sent', DB::table('provider_mails')->find($id)->status);
        $this->assertSame($pm->body, DB::table('provider_mails')->find($id)->body);
    }

    // M1：行を作るだけで送らない指定（トランザクションの中から呼ぶ用）。送るのは commit のあと
    public function test_queue_mail_can_skip_delivery(): void
    {
        config(['mail.default' => 'smtp']);
        $id = N::queueMail('someone@park.example', '架空', '件名', '本文', 'provider-mail', null, null, true, false);
        $m = DB::table('mail_outbox')->find($id);
        $this->assertSame('queued', $m->status);
        $this->assertNull($m->sent_at);
    }

    public function test_case_page_shows_provider_card_and_result_for_staff_and_admin(): void
    {
        $this->sendMail(self::SES1, $this->eg1);
        $this->as(self::SES1)->get("/cases/{$this->caseId}")->assertOk()->assertSee('プロバイダーへの返信')->assertSee('先方の結果');
        $this->as(self::ADMIN)->get("/cases/{$this->caseId}")->assertOk()->assertSee('先方の結果');
    }
}
