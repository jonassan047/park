<?php

namespace Tests\Feature;

use App\Services\ProviderReply;
use App\Services\Todo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.17.0 パートナー側の追跡と成約の報告（PLAN-v0170.md の D1〜D13、E1・E2）。T29〜T44。名前・アドレスはすべて架空
class V0170PartnerTrackTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;
    use V0170World;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
    }

    // 案内を送り（B 社）、返事を「応募する」にして、提案を頼むところまで進める
    private function handedOff(): array
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->as(self::BY)->post("/case-mails/{$cm->id}/reply", ['reply' => 'interested', 'note' => '架空の要点'])->assertRedirect();
        $this->as(self::BX)->post("/case-mails/{$cm->id}/handoff", ['note' => 'お願いします（架空）'])->assertRedirect();
        return $w + ['cm' => $cm];
    }

    private function mailsOfKind(string $kind)
    {
        return DB::table('mail_outbox')->where('kind', $kind)->get();
    }

    public function test_t29_partner_company_sees_the_tracking_card(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        foreach ([self::BX, self::BY] as $who) {
            $this->as($who)->get("/cases/{$w['cid']}")->assertOk()->assertSee('id="partner-mails"', false)->assertSee('自社から案内したエンジニア')->assertSee('他社 花子')->assertSee('EG-', false);
        }
        $this->assertSame('partner', $cm->mode);
    }

    public function test_t30_partner_can_record_the_reply(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->as(self::BY)->post("/case-mails/{$cm->id}/reply", ['reply' => 'interested', 'note' => '架空の要点'])->assertRedirect("/cases/{$w['cid']}#partner-mails");
        $row = DB::table('case_mails')->find($cm->id);
        $this->assertSame('interested', $row->reply);
        $this->assertSame('架空の要点', $row->reply_note);
    }

    public function test_t31_handoff_notifies_the_source_ses_once(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->as(self::BX)->post("/case-mails/{$cm->id}/reply", ['reply' => 'interested'])->assertRedirect();
        $this->as(self::BX)->post("/case-mails/{$cm->id}/handoff", ['note' => 'お願いします（架空）'])->assertRedirect("/cases/{$w['cid']}#partner-mails");
        $row = DB::table('case_mails')->find($cm->id);
        $this->assertNotNull($row->handoff_at);
        $this->assertSame((int) $this->user(self::BX)->id, (int) $row->handoff_by);
        $a = $this->user(self::SES1);
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $a->id)->where('title', 'like', '提案の依頼：%')->exists());
        $mails = $this->mailsOfKind('partner-handoff');
        $this->assertCount(1, $mails);
        $this->assertSame($a->email, $mails[0]->to_email);
        $this->assertStringContainsString('B.B', $mails[0]->body);
        $this->assertStringContainsString('お願いします（架空）', $mails[0]->body);
        $this->assertStringNotContainsString('他社 花子', $mails[0]->body, 'エンジニアの氏名は出さない');
        // 2回目は頼めない
        $this->as(self::BX)->post("/case-mails/{$cm->id}/handoff", ['note' => '2回目'])->assertRedirect()->assertSessionHas('flash.msg', 'すでに頼んでいます。');
        $this->assertCount(1, $this->mailsOfKind('partner-handoff'));
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $a->id)->where('title', 'like', '提案の依頼：%')->count());
    }

    public function test_t32_cannot_hand_off_without_interest(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->as(self::BX)->post("/case-mails/{$cm->id}/handoff", [])->assertRedirect()->assertSessionHas('flash.type', 'bad');
        $this->assertNull(DB::table('case_mails')->find($cm->id)->handoff_at);
        $this->assertCount(0, $this->mailsOfKind('partner-handoff'));
    }

    public function test_t33_source_ses_sees_the_handoff_without_names(): void
    {
        $w = $this->handedOff();
        $this->as(self::SES1)->get("/cases/{$w['cid']}")->assertOk()->assertSee('id="handoffs"', false)->assertSee('提案の依頼')->assertSee('B.B')->assertSee('お願いします（架空）')
            ->assertDontSee('他社 花子')->assertDontSee('架空の要点');
    }

    public function test_t34_provider_draft_from_handoff_has_no_leak(): void
    {
        $w = $this->handedOff();
        $this->as(self::SES1)->post("/case-mails/{$w['cm']->id}/provider-draft")->assertRedirect();
        $pm = DB::table('provider_mails')->where('case_mail_id', $w['cm']->id)->first();
        $this->assertNotNull($pm);
        $e = DB::table('engineers')->find($w['b1']);
        $this->assertSame([], ProviderReply::leaks($e, (string) $pm->body));
        $this->assertStringNotContainsString('他社 花子', (string) $pm->body);
        $bName = (string) DB::table('ses_companies')->where('id', $this->sesId(self::SES2))->value('name');
        $this->assertStringNotContainsString($bName, (string) $pm->body);
    }

    public function test_t35_only_the_anonymous_careersheet_is_available(): void
    {
        $w = $this->handedOff();
        $id = $w['cm']->id;
        $this->assertNotSame(403, $this->as(self::SES1)->get("/case-mails/$id/doc/careersheet-anon")->getStatusCode());
        $this->as(self::SES1)->get("/case-mails/$id/doc/careersheet")->assertForbidden();
        $this->as(self::SES1)->get("/case-mails/$id/doc/resume")->assertForbidden();
    }

    public function test_t36_without_handoff_the_partner_row_stays_closed(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/provider-draft")->assertForbidden();
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/result", ['result' => 'passed'])->assertForbidden();
        $this->as(self::SES1)->get("/case-mails/{$cm->id}/doc/careersheet-anon")->assertForbidden();
    }

    public function test_t37_result_notifies_partner_with_the_tracking_link(): void
    {
        $w = $this->handedOff();
        $this->as(self::SES1)->post("/case-mails/{$w['cm']->id}/result", ['result' => 'passed', 'note' => '架空メモ'])->assertRedirect();
        $n = DB::table('app_notifications')->where('user_id', $this->user(self::BX)->id)->where('title', 'like', 'グループの案件の結果%')->first();
        $this->assertNotNull($n);
        $this->assertSame("/cases/{$w['cid']}#partner-mails", $n->link);
        // パートナーには、結果は見えるが、受注 SES のメモは出ない
        $this->as(self::BX)->get("/cases/{$w['cid']}")->assertOk()->assertSee('合格')->assertDontSee('架空メモ');
    }

    public function test_t38_won_reports_once_and_same_value_changes_nothing(): void
    {
        $w = $this->handedOff();
        $id = $w['cm']->id;
        $this->as(self::BX)->post("/case-mails/$id/outcome", ['outcome' => 'won', 'note' => '架空の成約メモ'])->assertRedirect("/cases/{$w['cid']}#partner-mails");
        $row = DB::table('case_mails')->find($id);
        $this->assertSame('won', $row->outcome);
        $a = $this->user(self::SES1);
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $a->id)->where('title', 'like', '成約の報告：%')->exists());
        $mails = $this->mailsOfKind('partner-won');
        $this->assertCount(1, $mails);
        $this->assertStringContainsString('B.B', $mails[0]->body);
        $this->assertStringNotContainsString('他社 花子', $mails[0]->body);
        $this->assertStringNotContainsString('架空の成約メモ', $mails[0]->body);
        $this->as(self::BX)->post("/case-mails/$id/outcome", ['outcome' => 'won', 'note' => '架空の成約メモ'])->assertRedirect()->assertSessionHas('flash.msg', '変わっていません。');
        $this->assertCount(1, $this->mailsOfKind('partner-won'));
        // EG 本人には知らせない
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'like', '成約の報告%')->where('user_id', '!=', $a->id)->count());
    }

    public function test_t39_lost_is_notice_only_and_won_again_reports_again(): void
    {
        $w = $this->handedOff();
        $id = $w['cm']->id;
        $a = $this->user(self::SES1);
        $this->as(self::BX)->post("/case-mails/$id/outcome", ['outcome' => 'lost'])->assertRedirect();
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $a->id)->where('title', 'like', '不成約の連絡：%')->exists());
        $this->assertCount(0, $this->mailsOfKind('partner-lost'));
        $this->assertCount(0, $this->mailsOfKind('partner-won'));
        $this->as(self::BX)->post("/case-mails/$id/outcome", ['outcome' => 'won'])->assertRedirect();
        $this->assertCount(1, $this->mailsOfKind('partner-won'));
        $this->assertSame('won', DB::table('case_mails')->find($id)->outcome);
    }

    public function test_t40_double_press_of_won_sends_one_report(): void
    {
        $w = $this->handedOff();
        $id = (int) $w['cm']->id;
        $bx = $this->user(self::BX);
        $first = \App\Services\PartnerTrack::outcome($bx, $id, 'won', null);
        $second = \App\Services\PartnerTrack::outcome($bx, $id, 'won', null);
        $this->assertSame('ok', $first['type']);
        $this->assertSame('warn', $second['type']);
        $this->assertCount(1, $this->mailsOfKind('partner-won'));
        $this->assertSame(1, DB::table('app_notifications')->where('title', 'like', '成約の報告：%')->count());
    }

    public function test_t41_partner_todo_stays_until_the_outcome_is_recorded(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $b = $this->sesId(self::SES2);
        $texts = fn () => implode("\n", array_map(fn ($t) => $t['text'].' / '.$t['do'].' / '.$t['url'], Todo::ses($b)));
        $this->assertStringContainsString('他社 花子 さんの返事待ち', $texts());
        DB::table('case_mails')->where('id', $cm->id)->update(['reply' => 'interested']);
        $this->assertStringContainsString('他社 花子 さんが「応募する」', $texts());
        $this->assertStringContainsString('受注 SES に提案を頼む', $texts());
        $this->assertStringContainsString("/cases/{$w['cid']}#partner-mails", $texts());
        DB::table('case_mails')->where('id', $cm->id)->update(['result' => 'passed']);
        $this->assertStringContainsString('先方の結果が出ました', $texts());
        DB::table('case_mails')->where('id', $cm->id)->update(['result' => null]);
        DB::table('cases')->where('id', $w['cid'])->update(['status' => 'closed']);
        $this->assertStringContainsString('案件が終わりました', $texts()); // 案件が終わっても残る
        $this->as(self::BX)->post("/case-mails/{$cm->id}/outcome", ['outcome' => 'lost'])->assertRedirect();
        $this->assertStringNotContainsString('他社 花子', $texts());
    }

    public function test_t42_source_todo_for_handoff_goes_when_the_reply_is_sent(): void
    {
        $w = $this->handedOff();
        $a = $this->sesId(self::SES1);
        $texts = fn () => implode("\n", array_map(fn ($t) => $t['text'].' / '.$t['do'], Todo::ses($a)));
        $this->assertStringContainsString('提案の依頼：B.B', $texts());
        $this->assertStringNotContainsString('他社 花子', $texts());
        $this->as(self::SES1)->post("/case-mails/{$w['cm']->id}/provider-draft")->assertRedirect();
        DB::table('provider_mails')->where('case_mail_id', $w['cm']->id)->update(['status' => 'sent']);
        $this->assertStringNotContainsString('提案の依頼：B.B', $texts());
    }

    public function test_t43_other_company_and_admin_cannot_operate(): void
    {
        $w = $this->handedOff();
        $id = $w['cm']->id;
        $this->as(self::SES3)->get("/cases/{$w['cid']}")->assertOk()->assertDontSee('自社から案内したエンジニア')->assertDontSee('id="partner-mails"', false);
        foreach (['reply' => ['reply' => 'declined'], 'handoff' => [], 'outcome' => ['outcome' => 'won']] as $path => $data) {
            $this->as(self::SES3)->post("/case-mails/$id/$path", $data)->assertForbidden();
        }
        $this->as(self::SES1)->post("/case-mails/$id/reply", ['reply' => 'declined'])->assertForbidden(); // 受注 SES も、パートナーの行の返事は記録できない
        $this->as(self::ADMIN)->post("/case-mails/$id/handoff", [])->assertForbidden();
        $this->as(self::ADMIN)->post("/case-mails/$id/outcome", ['outcome' => 'won'])->assertForbidden();
        $this->assertSame('interested', DB::table('case_mails')->find($id)->reply);
        // 管理者は、案内済メールの行で提案の依頼の日と最後の結果を読める
        $this->as(self::BX)->post("/case-mails/$id/outcome", ['outcome' => 'won'])->assertRedirect();
        $this->as(self::ADMIN)->get("/cases/{$w['cid']}")->assertOk()->assertSee('提案の依頼：')->assertSee('最後の結果：成約');
    }

    public function test_t44_engineer_sees_nothing_new(): void
    {
        $w = $this->handedOff();
        $mail = $this->egLogin($w['b1'], 'eg44-17@example.test');
        $this->as(self::BX)->post("/case-mails/{$w['cm']->id}/outcome", ['outcome' => 'won'])->assertRedirect();
        $r = $this->as($mail)->get("/cases/{$w['cid']}")->assertOk();
        $r->assertDontSee('最後の結果')->assertDontSee('提案の依頼')->assertDontSee('自社から案内したエンジニア');
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->user($mail)->id)->where('title', 'like', '成約%')->count());
    }
}
