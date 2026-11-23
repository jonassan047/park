<?php

namespace Tests\Feature;

use App\Contracts\MailSource;
use App\Models\User;
use App\Services\CaseMail;
use App\Services\Inbound;
use App\Services\MailFetch;
use App\Services\Notifier as N;
use App\Services\ReplyTokens;
use App\Services\Todo;
use App\Services\Workflow as W;
use App\Support\MailParser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// v0.12.0 案内メールの返信先トークンと、受信の紐付け（PLAN.md 9章 T-1〜T-38）。人・会社は見本データと、テストの中で作る架空のものだけ。
// 会社：A＝SES1（みどり情報システム。担当者 SES1 と A2）、B＝SES2（あおぞらテック）。エンジニア：EG1＝A、EG2＝B。本物の IMAP にはつながない（偽の MailSource）
class V0120InboundTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const A2 = 'staff-a2@example.test';
    private const EG1 = 'eg1@park.example';
    private const EG2 = 'eg2@park.example';
    private const FROM = 'park@example.test';

    private int $eg1;
    private int $eg2;
    private int $caseId;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mail.from.address' => self::FROM]);
        DB::table('applications')->update(['status' => 'rejected']);
        $this->eg1 = $this->engId(self::EG1);
        $this->eg2 = $this->engId(self::EG2);
        $this->caseId = $this->mkCase($this->sesId(self::SES1), '架空の案件V0120');
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

    private function mkCase(?int $source, string $title): int
    {
        $cid = (int) DB::table('cases')->insertGetId(['title' => $title, 'status' => 'open', 'source_ses_id' => $source, 'created_at' => now_str(), 'updated_at' => now_str()]);
        DB::table('cases')->where('id', $cid)->update(['code' => \App\Services\Ids::next('C')]);
        return $cid;
    }

    private function mkStaff(int $sid, string $email, string $name): int
    {
        $uid = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        return $uid;
    }

    private function enableInbound(): void
    {
        config(['park.inbound.host' => 'imap.example.test', 'park.inbound.user' => 'x', 'park.inbound.password' => 'secret-pass']);
    }

    // 案内メールを送る（担当者として）。戻り：case_mails の行
    private function sendMail(string $sesEmail, int $cid, int $eid, string $subject = '案件のご案内'): object
    {
        $n = CaseMail::send(User::find($this->uid($sesEmail)), DB::table('cases')->find($cid), [$eid => ['subject' => $subject, 'body' => '架空の本文']], 'same');
        $this->assertSame(1, $n);
        return DB::table('case_mails')->where('case_id', $cid)->where('engineer_id', $eid)->first();
    }

    private function tokenOf(object $cm): object
    {
        return DB::table('reply_tokens')->where('case_mail_id', $cm->id)->first();
    }

    private function egMail(int $eid): string
    {
        return Inbound::engineerEmails($eid)[0];
    }

    // 受信メール（.eml の生の文）を作る。$extra は、同じ名前のヘッダーを置き換える・足す
    private function eml(string $from, string $to, string $subject, string $body, array $extra = []): string
    {
        $h = ['From' => $from, 'To' => $to, 'Subject' => $subject, 'Date' => date('r'), 'Message-ID' => '<'.bin2hex(random_bytes(8)).'@mail.example.test>',
            'MIME-Version' => '1.0', 'Content-Type' => 'text/plain; charset=UTF-8', 'Content-Transfer-Encoding' => '8bit'];
        $lines = [];
        foreach (array_merge($h, $extra) as $k => $v) $lines[] = "$k: $v";
        return implode("\r\n", $lines)."\r\n\r\n".$body."\r\n";
    }

    private function inbound(int $id): object
    {
        return DB::table('inbound_mails')->find($id);
    }

    private function ingest(string $raw): array
    {
        return Inbound::ingest($raw, 'file');
    }

    private function notes(string $email, string $like = '%'): int
    {
        return DB::table('app_notifications')->where('user_id', $this->uid($email))->where('title', 'like', $like)->count();
    }

    private function inboundMails(string $email): int
    {
        return DB::table('mail_outbox')->where('to_email', $email)->where('kind', 'inbound')->count();
    }

    // 未紐付けのメールを 1 通作る（管理者の手の紐付けの確かめ用）
    private function unlinked(): int
    {
        $r = $this->ingest($this->eml('stranger@example.test', 'park@example.test', '知らない人から', '案件の件です。'));
        $this->assertSame('saved', $r['status']);
        $this->assertSame('unlinked', $this->inbound($r['id'])->status);
        return $r['id'];
    }

    // ---------- 段階1：送る側 ----------
    // T-1：自社の EG に送る（受信が使える）
    public function test_t1_token_reply_to_subject_and_body_for_own_eg(): void
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $tok = $this->tokenOf($cm);
        $this->assertNotNull($tok);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{20}$/', $tok->token);
        $this->assertSame([$this->caseId, $this->sesId(self::SES1), $this->eg1, (int) $cm->outbox_id], [(int) $tok->case_id, (int) $tok->ses_id, (int) $tok->engineer_id, (int) $tok->outbox_id]);
        $mo = DB::table('mail_outbox')->find($cm->outbox_id);
        $this->assertSame('park+'.$tok->token.'@example.test, '.self::SES1, $mo->reply_to);
        $this->assertStringEndsWith('[PARK-'.substr($tok->token, 0, 10).']', $mo->subject);
        $this->assertSame('案件のご案内', $cm->subject); // case_mails には送った件名のまま
        $this->assertStringContainsString('［システム伝達項目・消さないでください］', $mo->body);
        $this->assertStringContainsString('案内番号：PARK-'.substr($tok->token, 0, 10), $mo->body);
        $this->assertStringContainsString('案件 ID：'.DB::table('cases')->where('id', $this->caseId)->value('code'), $mo->body);
        $this->assertStringContainsString('担当者と PARK に届きます', $mo->body);
        $this->assertSame($mo->message_id, $tok->message_id);
        $this->assertNotEmpty($mo->message_id);
    }

    // T-2：他社の EG に送る
    public function test_t2_group_eg_gets_only_park_address(): void
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg2);
        $tok = $this->tokenOf($cm);
        $mo = DB::table('mail_outbox')->find($cm->outbox_id);
        $this->assertSame('park+'.$tok->token.'@example.test', $mo->reply_to);
        $this->assertStringContainsString('PARK を通して案件の担当者に届きます', $mo->body);
    }

    // T-3：受信が使えない（.env が空）
    public function test_t3_without_inbound_reply_to_is_as_before(): void
    {
        $this->assertFalse(Inbound::enabled());
        $own = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $grp = $this->sendMail(self::SES1, $this->caseId, $this->eg2);
        $this->assertSame(self::SES1, DB::table('mail_outbox')->where('id', $own->outbox_id)->value('reply_to'));
        $this->assertNull(DB::table('mail_outbox')->where('id', $grp->outbox_id)->value('reply_to'));
        $mo = DB::table('mail_outbox')->find($grp->outbox_id);
        $this->assertStringContainsString('このメールへの返信は担当者に届かない', $mo->body);
        $tok = $this->tokenOf($grp);
        $this->assertStringEndsWith('[PARK-'.substr($tok->token, 0, 10).']', $mo->subject); // 印と一文は、いつも入る
        $this->assertStringContainsString('案内番号：PARK-', $mo->body);
    }

    // T-4：2 人に送ると、トークンが別々
    public function test_t4_tokens_differ(): void
    {
        $c = DB::table('cases')->find($this->caseId);
        CaseMail::send(User::find($this->uid(self::SES1)), $c, [$this->eg1 => ['subject' => 'a', 'body' => 'b'], $this->eg2 => ['subject' => 'a', 'body' => 'b']], 'same');
        $t = DB::table('reply_tokens')->pluck('token')->all();
        $this->assertCount(2, $t);
        $this->assertNotSame($t[0], $t[1]);
        foreach ($t as $x) $this->assertMatchesRegularExpression('/^[a-z0-9]{20}$/', $x);
    }

    // T-5：ヘッダー（Message-ID と返信先 2 つ）
    public function test_t5_headers(): void
    {
        $email = new \Symfony\Component\Mime\Email();
        N::headers($email, (object) ['reply_to' => 'park+abc@example.test, ses1@example.test', 'message_id' => 'abc123@example.test']);
        $this->assertSame('<abc123@example.test>', $email->getHeaders()->get('Message-ID')->getBodyAsString());
        $this->assertCount(2, $email->getReplyTo());
        $this->assertSame('park+abc@example.test', $email->getReplyTo()[0]->getAddress());
        // 返信先も Message-ID も無ければ、何も付けない
        $plain = new \Symfony\Component\Mime\Email();
        N::headers($plain, (object) []);
        $this->assertSame([], $plain->getReplyTo());
        $this->assertFalse($plain->getHeaders()->has('Message-ID'));
    }

    // T-6：管理者は案内メールを送れない（トークンも作られない）
    public function test_t6_admin_cannot_send(): void
    {
        $this->as(self::ADMIN)->post("/cases/{$this->caseId}/mail/send", ['mode' => 'same', 'to' => [$this->eg1 => ['send' => 1, 'subject' => 'a', 'body' => 'b']]])->assertForbidden();
        $this->assertSame(0, DB::table('reply_tokens')->count());
    }

    // ---------- 段階2：受け取る側 ----------
    // T-7：宛先のトークン
    public function test_t7_match_by_token_and_saves_eml(): void
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $tok = $this->tokenOf($cm);
        $r = $this->ingest($this->eml($this->egMail($this->eg1), 'park+'.$tok->token.'@example.test', 'Re: 案件のご案内', '応募したいです。'));
        $m = $this->inbound($r['id']);
        $this->assertSame(['linked', 'token', $this->caseId, $this->eg1, $this->sesId(self::SES1), (int) $cm->id], [$m->status, $m->match_by, (int) $m->case_id, (int) $m->engineer_id, (int) $m->ses_id, (int) $m->case_mail_id]);
        $this->assertFalse((bool) $m->from_mismatch);
        $this->assertTrue(Storage::disk('local')->exists($m->raw_path));
    }

    // T-8：In-Reply-To
    public function test_t8_match_by_in_reply_to(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $tok = $this->tokenOf($cm);
        $r = $this->ingest($this->eml($this->egMail($this->eg1), 'park@example.test', 'Re: 案件のご案内', '質問です。', ['In-Reply-To' => '<'.$tok->message_id.'>']));
        $this->assertSame(['linked', 'in_reply_to'], [$this->inbound($r['id'])->status, $this->inbound($r['id'])->match_by]);
    }

    // T-9：件名の印（差出人が EG の登録アドレス）／T-10：違うアドレスなら未紐付け
    public function test_t9_t10_match_by_subject_needs_registered_sender(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $mark = ReplyTokens::subjectMark($this->tokenOf($cm)->token);
        $r = $this->ingest($this->eml($this->egMail($this->eg1), 'park@example.test', "Re: 案件のご案内 $mark", '見送ります。'));
        $this->assertSame(['linked', 'subject'], [$this->inbound($r['id'])->status, $this->inbound($r['id'])->match_by]);
        $r2 = $this->ingest($this->eml('stranger@example.test', 'park@example.test', "Re: 案件のご案内 $mark", '見送ります。'));
        $this->assertSame('unlinked', $this->inbound($r2['id'])->status);
    }

    // T-11：本文の返事のリンク（差出人は問わない）
    public function test_t11_match_by_reply_link(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $r = $this->ingest($this->eml('other@example.test', 'park@example.test', '返信', "こちらです。\n".url('/r/'.$cm->token.'/interested')));
        $m = $this->inbound($r['id']);
        $this->assertSame(['linked', 'body', (int) $cm->id], [$m->status, $m->match_by, (int) $m->case_mail_id]);
        $this->assertTrue((bool) $m->from_mismatch); // 登録と違うアドレスから
    }

    // T-12：本文の案件 ID（差出人が EG の登録アドレスで、案内が 1 件）
    public function test_t12_match_by_case_code(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $code = DB::table('cases')->where('id', $this->caseId)->value('code');
        $r = $this->ingest($this->eml($this->egMail($this->eg1), 'park@example.test', '返信', "案件 ID：$code の件です。"));
        $this->assertSame(['linked', 'body', (int) $cm->id], [$this->inbound($r['id'])->status, $this->inbound($r['id'])->match_by, (int) $this->inbound($r['id'])->case_mail_id]);
        $r2 = $this->ingest($this->eml('stranger@example.test', 'park@example.test', '返信', "案件 ID：$code の件です。"));
        $this->assertSame('unlinked', $this->inbound($r2['id'])->status);
    }

    // T-13：どれにも当たらない → 未紐付け、管理者にお知らせ（メールなし）
    public function test_t13_unlinked_notifies_admin_without_mail(): void
    {
        $before = DB::table('mail_outbox')->count();
        $id = $this->unlinked();
        $this->assertSame(1, $this->notes(self::ADMIN, '%分からない受信メール%'));
        $this->assertSame($before, DB::table('mail_outbox')->count());
        $this->assertNotNull($this->inbound($id)->notified_at);
    }

    // T-14：同じメールを 2 回 → 1 件、知らせも 1 回
    public function test_t14_duplicate_is_one(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $raw = $this->eml($this->egMail($this->eg1), 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re', '返信です。');
        $this->ingest($raw);
        $r = $this->ingest($raw);
        $this->assertSame('duplicate', $r['status']);
        $this->assertSame(1, DB::table('inbound_mails')->count());
        $this->assertSame(1, $this->notes(self::SES1, '%さんから案内メールへの返信%'));
    }

    // T-15：PARK 自身が送ったメールは記録しない
    public function test_t15_self_sent_is_ignored(): void
    {
        $r = $this->ingest($this->eml(self::FROM, 'park@example.test', 'テストメール', '本文'));
        $this->assertSame(['self', null], [$r['status'], $r['id']]);
        $this->assertSame(0, DB::table('inbound_mails')->count());
    }

    // T-16：文字コード・符号化・添付・不達・自動返信
    public function test_t16_parser_handles_encodings(): void
    {
        $jis = mb_convert_encoding('こんにちは、応募したいです。', 'ISO-2022-JP', 'UTF-8');
        $subj = '=?ISO-2022-JP?B?'.base64_encode(mb_convert_encoding('案件のご質問', 'ISO-2022-JP', 'UTF-8')).'?=';
        $p = MailParser::parse($this->eml('a@example.test', 'b@example.test', $subj, $jis, ['Content-Type' => 'text/plain; charset=ISO-2022-JP', 'Content-Transfer-Encoding' => '7bit']));
        $this->assertSame('案件のご質問', $p['subject']);
        $this->assertSame('こんにちは、応募したいです。', $p['body_text']);

        $qp = quoted_printable_encode('こんにちは');
        $p = MailParser::parse($this->eml('a@example.test', 'b@example.test', 'qp', $qp, ['Content-Transfer-Encoding' => 'quoted-printable']));
        $this->assertSame('こんにちは', $p['body_text']);

        $p = MailParser::parse($this->eml('a@example.test', 'b@example.test', 'html', '<p>ご連絡<br>ありがとう</p>', ['Content-Type' => 'text/html; charset=UTF-8']));
        $this->assertStringContainsString('ご連絡', $p['body_text']);
        $this->assertStringNotContainsString('<p>', $p['body_text']);

        $multi = "--B1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n本文です\r\n--B1\r\nContent-Type: application/pdf; name=\"resume.pdf\"\r\nContent-Disposition: attachment; filename=\"resume.pdf\"\r\nContent-Transfer-Encoding: base64\r\n\r\n".base64_encode('dummy-pdf')."\r\n--B1--\r\n";
        $p = MailParser::parse($this->eml('a@example.test', 'b@example.test', 'att', $multi, ['Content-Type' => 'multipart/mixed; boundary="B1"']));
        $this->assertSame('本文です', $p['body_text']);
        $this->assertSame([['name' => 'resume.pdf', 'type' => 'application/pdf', 'size' => strlen('dummy-pdf')]], $p['attachments']);
    }

    public function test_t16_bounce_and_auto_reply_are_marked_and_not_notified(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $tok = $this->tokenOf($cm);
        $bounce = $this->ingest($this->eml('MAILER-DAEMON@example.test', 'park+'.$tok->token.'@example.test', '配送できません', '宛先が見つかりません。'));
        $this->assertSame('bounce', $this->inbound($bounce['id'])->auto);
        $this->assertSame('linked', $this->inbound($bounce['id'])->status);
        $auto = $this->ingest($this->eml($this->egMail($this->eg1), 'park+'.$tok->token.'@example.test', '不在です', '休暇中です。', ['Auto-Submitted' => 'auto-replied']));
        $this->assertSame('auto', $this->inbound($auto['id'])->auto);
        $this->assertSame(0, $this->notes(self::SES1, '%さんから案内メールへの返信%'));
    }

    public function test_command_park_mail_ingest_reads_a_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'eml');
        file_put_contents($path, $this->eml('stranger@example.test', 'park@example.test', '手で確かめる', '本文'));
        $this->artisan('park:mail-ingest', ['path' => $path])->assertExitCode(0);
        @unlink($path);
        $this->assertSame(1, DB::table('inbound_mails')->where('source', 'file')->count());
    }

    // ---------- 段階3：知らせ・画面 ----------
    // T-17：自社の EG の返信 → 担当者全員にお知らせだけ（メールなし）
    public function test_t17_own_eg_reply_notifies_all_staff_without_mail(): void
    {
        $this->mkStaff($this->sesId(self::SES1), self::A2, '担当 次郎');
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $this->ingest($this->eml($this->egMail($this->eg1), 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re', '応募したいです。'));
        foreach ([self::SES1, self::A2] as $e) {
            $this->assertSame(1, $this->notes($e, '%さんから案内メールへの返信：架空の案件V0120%'), $e);
            $this->assertSame(0, $this->inboundMails($e), $e);
        }
    }

    // T-18：他社の EG の返信 → お知らせ＋メール。メールに本文・氏名が無い
    public function test_t18_other_company_eg_reply_mails_without_body(): void
    {
        $this->mkStaff($this->sesId(self::SES1), self::A2, '担当 次郎');
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg2);
        $name = DB::table('engineers')->where('id', $this->eg2)->value('name');
        $this->ingest($this->eml($this->egMail($this->eg2), 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re', 'ひみつの返信本文です。'));
        foreach ([self::SES1, self::A2] as $e) {
            $this->assertSame(1, $this->notes($e, '%さんから案内メールへの返信%'), $e);
            $this->assertSame(1, $this->inboundMails($e), $e);
            $mail = DB::table('mail_outbox')->where('to_email', $e)->where('kind', 'inbound')->first();
            $this->assertStringNotContainsString('ひみつの返信本文', $mail->body.$mail->subject);
            $this->assertStringNotContainsString($name, $mail->body.$mail->subject);
        }
    }

    // T-19：担当者（SES でない）が一覧・詳細を読める。開くと既読になり、今やることから消える
    public function test_t19_staff_reads_and_todo_clears(): void
    {
        $this->mkStaff($this->sesId(self::SES1), self::A2, '担当 次郎');
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $r = $this->ingest($this->eml($this->egMail($this->eg1), 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re', '応募したいです。'));
        $sid = $this->sesId(self::SES1);
        $this->assertStringContainsString('返信メール', collect(Todo::ses($sid))->pluck('text')->implode('|'));
        $this->as(self::A2)->get('/inbound')->assertOk()->assertSee('架空の案件V0120');
        $this->get('/inbound/'.$r['id'])->assertOk()->assertSee('応募したいです。');
        $this->assertNotNull($this->inbound($r['id'])->ses_read_at);
        $this->assertStringNotContainsString('返信メール', collect(Todo::ses($sid))->pluck('text')->implode('|'));
    }

    // T-20：他社の EG の返信は、氏名・メールを伏せる
    public function test_t20_other_company_eg_is_masked(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg2);
        $e = DB::table('engineers')->find($this->eg2);
        $mail = $this->egMail($this->eg2);
        $r = $this->ingest($this->eml($mail, 'park+'.$this->tokenOf($cm)->token.'@example.test', "Re: {$e->name} です", "{$e->name} です。連絡先は {$mail} です。"));
        $res = $this->as(self::SES1)->get('/inbound/'.$r['id'])->assertOk();
        $res->assertDontSee($e->name)->assertDontSee($mail)->assertSee('（伏せています）')->assertSee('あおぞらテック');
        $this->get('/inbound')->assertOk()->assertDontSee($e->name);
    }

    // T-21：EG の所属の会社の担当者でも、みなもと SES でなければ 404
    public function test_t21_other_company_staff_gets_404(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg2);
        $r = $this->ingest($this->eml($this->egMail($this->eg2), 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re', '返信です。'));
        $this->as(self::SES2)->get('/inbound/'.$r['id'])->assertNotFound();
        $this->get('/inbound')->assertOk()->assertDontSee('架空の案件V0120');
    }

    // T-22：SES は未紐付けを開けず、元のメール・紐付けは 403
    public function test_t22_ses_cannot_touch_unlinked_or_admin_actions(): void
    {
        $id = $this->unlinked();
        $this->as(self::SES1)->get('/inbound/'.$id)->assertNotFound();
        $this->get("/inbound/$id/raw")->assertForbidden();
        $this->post("/inbound/$id/link", ['case_mail_id' => 1])->assertForbidden();
        $this->post('/admin/inbound/fetch')->assertForbidden();
    }

    // T-23：EG は入れない
    public function test_t23_eg_is_forbidden(): void
    {
        $this->as(self::EG1)->get('/inbound')->assertForbidden();
        $this->get('/inbound/1')->assertForbidden();
        $this->get('/admin/inbound')->assertForbidden();
    }

    // T-24：管理者の一覧
    public function test_t24_admin_sees_all_with_counts(): void
    {
        $id = $this->unlinked();
        $this->as(self::ADMIN)->get('/admin/inbound')->assertOk()->assertSee('未紐付け（1）')->assertSee('知らない人から');
        $this->get('/inbound')->assertRedirect('/admin/inbound');
        $this->get('/inbound/'.$id)->assertOk()->assertSee('stranger@example.test');
        $this->get("/inbound/$id/raw")->assertOk();
    }

    // T-25：管理者が案件 ID で探して紐付ける
    public function test_t25_admin_links_manually(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $id = $this->unlinked();
        $code = DB::table('cases')->where('id', $this->caseId)->value('code');
        $this->as(self::ADMIN)->get("/inbound/$id?code=$code&pick={$cm->id}")->assertOk()->assertSee('みどり情報システム')->assertSee('紐付ける');
        $this->post("/inbound/$id/link", ['case_mail_id' => $cm->id])->assertSessionHas('flash.type', 'ok');
        $m = $this->inbound($id);
        $this->assertSame(['linked', 'manual', $this->uid(self::ADMIN)], [$m->status, $m->match_by, (int) $m->linked_by]);
        $this->assertSame(1, $this->notes(self::SES1, '%さんから案内メールへの返信%'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'inbound-link')->count());
    }

    // T-26：外して別の案内に付け直すと、新しい会社にだけ知らせる
    public function test_t26_relink_notifies_new_company_only(): void
    {
        $cmA = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $caseB = $this->mkCase($this->sesId(self::SES2), '架空の案件V0120B');
        $cmB = $this->sendMail(self::SES2, $caseB, $this->eg2);
        $id = $this->unlinked();
        $this->as(self::ADMIN)->post("/inbound/$id/link", ['case_mail_id' => $cmA->id]);
        $this->post("/inbound/$id/unlink")->assertSessionHas('flash.type', 'ok');
        $this->assertSame('unlinked', $this->inbound($id)->status);
        $this->post("/inbound/$id/link", ['case_mail_id' => $cmB->id])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, $this->notes(self::SES1, '%さんから案内メールへの返信%'));
        $this->assertSame(1, $this->notes(self::SES2, '%さんから案内メールへの返信%'));
        $this->as(self::SES1)->get('/inbound/'.$id)->assertNotFound();
        $this->as(self::SES2)->get('/inbound/'.$id)->assertOk();
    }

    // T-27：対象外にして戻す
    public function test_t27_ignore_and_restore(): void
    {
        $id = $this->unlinked();
        $this->as(self::ADMIN)->post("/inbound/$id/ignore", ['note' => '営業メール'])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(['ignored', '営業メール'], [$this->inbound($id)->status, $this->inbound($id)->note]);
        $this->post("/inbound/$id/restore")->assertSessionHas('flash.type', 'ok');
        $this->assertSame('unlinked', $this->inbound($id)->status);
        $this->assertSame(1, DB::table('inbound_mails')->count());
    }

    // T-28：紐付けの 2 回押し
    public function test_t28_double_link(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $id = $this->unlinked();
        $this->as(self::ADMIN)->post("/inbound/$id/link", ['case_mail_id' => $cm->id])->assertSessionHas('flash.type', 'ok');
        $this->post("/inbound/$id/link", ['case_mail_id' => $cm->id])->assertSessionHas('flash.type', 'bad')->assertSessionHas('flash.msg', 'もう紐付いています。');
        $this->assertSame(1, $this->notes(self::SES1, '%さんから案内メールへの返信%'));
    }

    // T-29：案件の画面に「返信メール n 件」
    public function test_t29_case_page_shows_count(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $this->ingest($this->eml($this->egMail($this->eg1), 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re', '返信です。'));
        $this->as(self::SES1)->get("/cases/{$this->caseId}")->assertOk()->assertSee('返信メール 1 件');
    }

    // T-30：管理者の今やること
    public function test_t30_admin_todo_has_unlinked(): void
    {
        $this->unlinked();
        $this->assertStringContainsString('分からない受信メール', collect(Todo::admin())->pluck('text')->implode('|'));
    }

    // ---------- 段階4：取り込み ----------
    // T-31：.env が空なら何もしない
    public function test_t31_disabled_does_nothing(): void
    {
        $this->artisan('park:mail-fetch')->expectsOutput('受信の設定がありません（.env の PARK_IMAP_*）。')->assertExitCode(0);
        $this->as(self::ADMIN)->post('/admin/inbound/fetch')->assertSessionHas('flash.type', 'warn')->assertSessionHas('flash.msg', '受信の設定がまだです（.env）。');
        $this->assertSame(0, DB::table('inbound_mails')->count());
    }

    private function fake(int $validity, array $uids): FakeMailSource
    {
        $msgs = [];
        foreach ($uids as $uid) $msgs[$uid] = $this->eml('stranger@example.test', 'park@example.test', "件名 $uid", "本文 $uid");
        $f = new FakeMailSource($validity, $msgs);
        $this->app->instance(MailSource::class, $f);
        return $f;
    }

    // T-32：初回は今ある分を読まず、最後の UID を保存
    public function test_t32_first_run_reads_nothing(): void
    {
        $this->enableInbound();
        $this->fake(7, [1, 2, 3]);
        $r = MailFetch::run();
        $this->assertTrue($r['ok']);
        $this->assertSame(0, DB::table('inbound_mails')->count());
        $this->assertSame('3', DB::table('settings')->where('key', 'inbound_last_uid')->value('value'));
    }

    // T-33：2 回目は新しい分だけ
    public function test_t33_second_run_reads_new_mails(): void
    {
        $this->enableInbound();
        $this->fake(7, [1, 2, 3]);
        MailFetch::run();
        $this->fake(7, [1, 2, 3, 4, 5]);
        $r = MailFetch::run();
        $this->assertSame(2, $r['count']);
        $this->assertSame(2, DB::table('inbound_mails')->count());
        $this->assertSame('5', DB::table('settings')->where('key', 'inbound_last_uid')->value('value'));
    }

    // T-34：--from-start は全部読む。重複は増えない
    public function test_t34_from_start_reads_all_without_duplicates(): void
    {
        $this->enableInbound();
        $this->fake(7, [1, 2, 3]);
        $this->assertSame(3, MailFetch::run(null, true)['count']);
        $this->assertSame(0, MailFetch::run(null, true)['count']);
        $this->assertSame(3, DB::table('inbound_mails')->count());
    }

    // T-35：UIDVALIDITY が変わったら、初回と同じく今ある分は読まず、記録に残す。読み直しは --from-start で人が行い、増えない
    public function test_t35_uidvalidity_change_saves_last_uid_and_audits(): void
    {
        $this->enableInbound();
        $first = $this->fake(7, [1, 2]);
        MailFetch::run(null, true);
        $this->assertSame(2, DB::table('inbound_mails')->count());
        $this->app->instance(MailSource::class, new FakeMailSource(8, $first->msgs + [3 => $this->eml('stranger@example.test', 'park@example.test', '件名 3', '本文 3')]));
        $r = MailFetch::run();
        $this->assertSame(0, $r['count']);
        $this->assertSame(2, DB::table('inbound_mails')->count()); // 読み直さない
        $this->assertSame('8', DB::table('settings')->where('key', 'inbound_uidvalidity')->value('value'));
        $this->assertSame('3', DB::table('settings')->where('key', 'inbound_last_uid')->value('value'));
        $this->assertSame(1, DB::table('audit_log')->where('action', 'mail-fetch-uidvalidity')->count());
        // 人が --from-start で読み直しても、重複は増えない
        $this->assertSame(1, MailFetch::run(null, true)['count']);
        $this->assertSame(3, DB::table('inbound_mails')->count());
    }

    // ---------- v0.12.0 レビューの直し ----------
    // H1：転送の形は、件名・本文の印や案内番号があっても紐付けない。返信の形でないものも同じ
    public function test_h1_forward_and_non_reply_shapes_are_not_linked_by_subject_or_body(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $tok = $this->tokenOf($cm);
        $mark = ReplyTokens::subjectMark($tok->token);
        $from = $this->egMail($this->eg1);
        $body = "転送します。\n案内番号：PARK-".substr($tok->token, 0, 10)."\n".url('/r/'.$cm->token.'/interested');
        foreach (["Fwd: 案件のご案内 $mark", "FW: 案件のご案内 $mark", "転送：案件のご案内 $mark", "案件のご案内 $mark"] as $subject) {
            $r = $this->ingest($this->eml($from, 'park@example.test', $subject, $body));
            $this->assertSame('unlinked', $this->inbound($r['id'])->status, $subject);
        }
        // 返信の形（Re: ・返信）なら紐付く
        $r = $this->ingest($this->eml($from, 'park@example.test', "RE: 案件のご案内 $mark", '見送ります。'));
        $this->assertSame('linked', $this->inbound($r['id'])->status);
        $r = $this->ingest($this->eml($from, 'park@example.test', '返信：案件のご案内', $body));
        $this->assertSame('linked', $this->inbound($r['id'])->status);
        // In-Reply-To があれば、件名が何でも返信の形
        $r = $this->ingest($this->eml($from, 'park@example.test', "案件のご案内 $mark", '質問です。', ['In-Reply-To' => '<zzz@example.test>']));
        $this->assertSame('linked', $this->inbound($r['id'])->status);
    }

    // M1：他社の EG あてで、返信先を PARK に向けていなかった案内は、In-Reply-To・/r/ でも自動で紐付けない
    public function test_m1_group_eg_without_via_park_is_not_auto_linked(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg2); // 受信が使えない間に送った（via_park が偽）
        $tok = $this->tokenOf($cm);
        $this->assertFalse((bool) $tok->via_park);
        $r = $this->ingest($this->eml($this->egMail($this->eg2), 'park@example.test', 'Re: 案件のご案内', '質問です。', ['In-Reply-To' => '<'.$tok->message_id.'>']));
        $this->assertSame('unlinked', $this->inbound($r['id'])->status);
        $r = $this->ingest($this->eml($this->egMail($this->eg2), 'park@example.test', '返信', url('/r/'.$cm->token.'/interested')));
        $this->assertSame('unlinked', $this->inbound($r['id'])->status);
        // トークンが無い（v0.12.0 より前）の他社あても同じ
        DB::table('reply_tokens')->where('id', $tok->id)->delete();
        $r = $this->ingest($this->eml($this->egMail($this->eg2), 'park@example.test', '返信', url('/r/'.$cm->token.'/interested')));
        $this->assertSame('unlinked', $this->inbound($r['id'])->status);
        // 自社の EG あては、via_park が偽でもそのまま紐付く
        $own = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $r = $this->ingest($this->eml($this->egMail($this->eg1), 'park@example.test', '返信', url('/r/'.$own->token.'/interested')));
        $this->assertSame('linked', $this->inbound($r['id'])->status);
    }

    public function test_m1_group_eg_with_via_park_is_linked(): void
    {
        $this->enableInbound();
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg2);
        $tok = $this->tokenOf($cm);
        $this->assertTrue((bool) $tok->via_park);
        $r = $this->ingest($this->eml($this->egMail($this->eg2), 'park@example.test', 'Re: 案件のご案内', '質問です。', ['In-Reply-To' => '<'.$tok->message_id.'>']));
        $this->assertSame('linked', $this->inbound($r['id'])->status);
    }

    // M2：符号化されていない 8bit のヘッダー・添付名が混ざっても、取り出す文字は全部 UTF-8 として正しい
    public function test_m2_parser_returns_valid_utf8(): void
    {
        $sjis = mb_convert_encoding('案件の質問', 'SJIS-win', 'UTF-8');
        $bad = "\xFF\xFE\x80";
        $raw = "From: ".mb_convert_encoding('山田', 'SJIS-win', 'UTF-8')." <a@example.test>\r\nTo: park@example.test\r\nSubject: $sjis\r\nIn-Reply-To: <x$bad@example.test>\r\nReferences: <y$bad@example.test>\r\n"
            ."Content-Type: multipart/mixed; boundary=\"B1\"\r\n\r\n--B1\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n本文\r\n--B1\r\nContent-Type: application/pdf; name=\"{$sjis}.pdf\"\r\nContent-Disposition: attachment; filename=\"{$sjis}$bad.pdf\"\r\n\r\nx\r\n--B1--\r\n";
        $p = MailParser::parse($raw);
        foreach (['subject', 'from_name', 'in_reply_to', 'references'] as $k) $this->assertTrue(mb_check_encoding($p[$k], 'UTF-8'), $k);
        $this->assertTrue(mb_check_encoding($p['attachments'][0]['name'], 'UTF-8'));
        $this->assertSame('案件の質問', $p['subject']);
        $this->assertSame('山田', $p['from_name']);
        $this->assertIsString(json_encode($p['attachments'], JSON_UNESCAPED_UNICODE)); // 保存の形にできる
        $this->assertSame('saved', Inbound::ingest($raw, 'file')['status']); // 保存でも落ちない
    }

    // M3：エンジニアが分からない紐付け済みの行は、SES・担当者には差出人を伏せる
    public function test_m3_unknown_engineer_is_masked_for_ses(): void
    {
        $cm = $this->sendMail(self::SES1, $this->caseId, $this->eg1);
        $r = $this->ingest($this->eml('秘密 太郎 <himitsu@example.test>', 'park+'.$this->tokenOf($cm)->token.'@example.test', 'Re: 秘密 太郎 です', "秘密 太郎 です。連絡先は himitsu@example.test です。"));
        DB::table('inbound_mails')->where('id', $r['id'])->update(['engineer_id' => null]);
        $res = $this->as(self::SES1)->get('/inbound/'.$r['id'])->assertOk();
        $res->assertDontSee('秘密 太郎')->assertDontSee('himitsu@example.test')->assertSee('（伏せています）');
        $row = Inbound::masked($this->user(self::SES1), Inbound::visible($this->user(self::SES1), $r['id']));
        $this->assertSame(['（伏せています）', '（伏せています）', true], [$row->from_name, $row->from_email, $row->masked]);
    }

    // T-36：偽の MailSource が例外 → 落ちずに文を返す。記録にパスワードが無い
    public function test_t36_failure_is_caught_and_password_not_logged(): void
    {
        $this->enableInbound();
        $f = $this->fake(7, [1]);
        $f->fail = new \RuntimeException('ログインに失敗 secret-pass');
        $r = MailFetch::run();
        $this->assertFalse($r['ok']);
        $this->assertStringNotContainsString('secret-pass', $r['msg']);
        $audit = DB::table('audit_log')->where('action', 'mail-fetch-error')->get();
        $this->assertCount(1, $audit);
        $this->assertStringNotContainsString('secret-pass', (string) $audit[0]->detail);
    }

    // T-37：park:status
    public function test_t37_status_has_inbound_lines(): void
    {
        $this->unlinked();
        Artisan::call('park:status');
        $out = Artisan::output();
        $this->assertStringContainsString('受信：設定', $out);
        $this->assertStringContainsString('受信：未紐付けの件数', $out);
    }
}

// 偽の受信箱（テスト用。本物の IMAP にはつながない）
class FakeMailSource implements MailSource
{
    public ?\Throwable $fail = null;

    public function __construct(public int $validity, public array $msgs)
    {
    }

    public function uidvalidity(): int
    {
        if ($this->fail) throw $this->fail;
        return $this->validity;
    }

    public function lastUid(): int
    {
        return $this->msgs ? max(array_keys($this->msgs)) : 0;
    }

    public function fetchSince(int $uid, int $limit): iterable
    {
        ksort($this->msgs);
        $n = 0;
        foreach ($this->msgs as $u => $raw) {
            if ($u <= $uid) continue;
            if ($n++ >= $limit) break;
            yield [$u, $raw];
        }
    }
}
