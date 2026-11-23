<?php

namespace Tests\Feature;

use App\Services\CaseMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.17.0 プロバイダーを伏せる（B1〜B5）と、「プロバイダー」タブの原文の一覧（C1〜C6）。T17〜T28。名前・アドレスはすべて架空
class V0170HideProviderTest extends TestCase
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

    // パートナーに見せてはいけない文字
    private function secrets(int $cid): array
    {
        return [self::PROV_NAME, "p{$cid}@example.test", self::PROV_TEL, 'PROVRAW-MARKER-17'];
    }

    private function assertNoSecrets(string $html, int $cid, string $where): void
    {
        foreach ($this->secrets($cid) as $s) $this->assertStringNotContainsString($s, $html, "{$where} にプロバイダーの情報（{$s}）が出ている");
    }

    public function test_t17_partner_case_card_hides_provider_money_and_client(): void
    {
        $w = $this->world();
        $r = $this->as(self::BX)->get("/cases/{$w['cid']}")->assertOk();
        $r->assertDontSee('精算幅あり架空')->assertDontSee('元請け架空ZZ')->assertDontSee('60万円')->assertSee('受注 SES から伝えられます');
        $r->assertSee(DB::table('ses_companies')->where('id', $this->sesId(self::SES1))->value('name')); // 依頼元（受注 SES）は分かる
    }

    public function test_t18_partner_sees_the_told_price(): void
    {
        $w = $this->world();
        $this->sendReferral($w, '50万円');
        $this->as(self::BX)->get("/cases/{$w['cid']}")->assertOk()->assertSee('50万円')->assertDontSee('60万円');
        $this->assertSame('50万円', CaseMail::priceForSes($this->asUser(self::BX), DB::table('cases')->find($w['cid'])));
    }

    private function asUser(string $email): object
    {
        return $this->user($email);
    }

    public function test_t19_lists_show_provider_price_only_for_own_cases(): void
    {
        $w = $this->world();
        $this->sendReferral($w, '50万円');
        $own = $this->mkCase(['title' => 'Ｂ社の案件十七', 'price' => 70], self::SES2);
        foreach (['/ses/cases', '/ses'] as $path) {
            $this->as(self::BX)->get($path)->assertOk()->assertSee('70万円')->assertSee('50万円');
        }
        $this->as(self::BX)->get('/ses/cases?mine=1')->assertOk()->assertSee('70万円');
        // 受注 SES の A 社には、今までどおり金額が出る
        $this->as(self::SES1)->get('/ses/cases')->assertOk()->assertSee('60万円');
        // 一覧の金額の決め方：自社が受注 SES でなければ、伝えられた単価（無ければ「—」）。プロバイダーからの金額は出さない
        $bx = $this->user(self::BX);
        $caseA = DB::table('cases')->find($w['cid']);
        $this->assertSame('50万円', \App\Http\Controllers\SesController::priceDisp($bx, $caseA, CaseMail::priceTextsForSes((int) $bx->ses_id, [$w['cid']])));
        $this->assertSame('—', \App\Http\Controllers\SesController::priceDisp($bx, $caseA, []));
        $this->assertSame('70万円', \App\Http\Controllers\SesController::priceDisp($bx, DB::table('cases')->find($own), []));
    }

    public function test_t20_partner_screens_never_show_the_provider(): void
    {
        $w = $this->world();
        $this->partnerMail($w);
        $bsid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user(self::BX)->id)->value('id');
        $pages = ["/cases/{$w['cid']}", "/searches/$bsid", '/inbound', '/ses', '/ses/cases', '/notifications'];
        foreach ($pages as $p) $this->assertNoSecrets($this->as(self::BX)->get($p)->getContent(), $w['cid'], $p);
        $this->assertNoSecrets($this->as(self::BY)->get("/cases/{$w['cid']}")->getContent(), $w['cid'], 'Y の案件の画面');
        // 届いたメールとお知らせにも出ない
        $texts = DB::table('mail_outbox')->whereIn('to_email', [$this->user(self::BX)->email, $this->user(self::BY)->email, DB::table('engineers')->where('id', $w['b1'])->value('email')])->get()
            ->map(fn ($m) => $m->subject."\n".$m->body)->implode("\n");
        $texts .= "\n".DB::table('app_notifications')->whereIn('user_id', [$this->user(self::BX)->id, $this->user(self::BY)->id])->get()->map(fn ($n) => $n->title."\n".$n->body)->implode("\n");
        $this->assertNoSecrets($texts, $w['cid'], 'メール・お知らせ');
    }

    public function test_t21_provider_reply_is_only_for_source_ses_and_admin(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $id = (int) DB::table('inbound_mails')->insertGetId(['source' => 'file', 'raw_hash' => hash('sha256', 'prov17'), 'fetched_at' => now_str(), 'received_at' => now_str(),
            'from_email' => 'prov17@example.test', 'from_name' => '架空 提供', 'subject' => 'PROVREPLY-17 ご返事', 'body_text' => 'PROVREPLY-17 本文', 'status' => 'linked', 'match_by' => 'manual',
            'case_mail_id' => $cm->id, 'case_id' => $w['cid'], 'ses_id' => $this->sesId(self::SES2), 'engineer_id' => $w['b1'], 'sender_kind' => 'provider']);
        $this->as(self::BX)->get('/inbound')->assertOk()->assertDontSee('PROVREPLY-17');
        $this->as(self::BX)->get("/inbound/$id")->assertNotFound();
        $this->as(self::BY)->get("/inbound/$id")->assertNotFound();
        $this->as(self::SES1)->get('/inbound')->assertOk()->assertSee('PROVREPLY-17');
        $this->as(self::ADMIN)->get("/inbound/$id")->assertOk();
    }

    public function test_t22_source_ses_still_sees_everything(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->get("/cases/{$w['cid']}")->assertOk()->assertSee('PROVRAW-MARKER-17')->assertSee('60万円')->assertSee('元請け架空ZZ')->assertSee('精算幅あり架空');
    }

    // 単価が合わない点の文に、プロバイダーの金額を入れない（EG の画面にも出る文なので）
    public function test_t28b_match_missing_price_has_no_provider_money(): void
    {
        $w = $this->world();
        DB::table('engineers')->where('id', $w['b1'])->update(['desired_price' => 95, 'min_price' => 90]);
        $m = \App\Services\Matcher::score(DB::table('cases')->find($w['cid']), DB::table('engineers')->find($w['b1']));
        $this->assertContains('単価（希望が案件より高い）', $m['missing']);
        $this->assertStringNotContainsString('60', implode('、', $m['missing']));
        // 点数の計算に出てくる文字（EG の画面にも出る）に、60 も 60万円 も入らない
        $this->assertStringNotContainsString('60万円', json_encode($m, JSON_UNESCAPED_UNICODE));
    }

    // パートナーの EG の応募：提示の金額の既定値は案内メールの単価の文。選考の画面にもプロバイダーの金額を出さない
    public function test_t28c_partner_application_does_not_use_provider_price(): void
    {
        $w = $this->world();
        $this->partnerMail($w);
        $appId = \App\Services\Workflow::apply($w['cid'], $w['b1'], ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1], $this->user(self::BX));
        $price = DB::table('applications')->where('id', $appId)->value('proposed_price');
        $this->assertNotSame(60.0, $price === null ? null : (float) $price);
        $r = $this->as(self::BX)->get("/applications/$appId")->assertOk();
        $r->assertDontSee('60万円');
        // 応募はパートナー B 社のもの（受注 SES の A 社は開けない。応募の持ち主は応募した EG の SES）
        $this->as(self::SES1)->get("/applications/$appId")->assertForbidden();
        // A 社自身の EG の応募なら、A 社の担当者に提示の金額が出る
        $aApp = \App\Services\Workflow::apply($w['cid'], $w['a1'], ['start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1], $this->user(self::SES1));
        $this->as(self::SES1)->get("/applications/$aApp")->assertOk()->assertSee('提示の金額');
    }

    // 手で紐付けたとき、差出人がその案件のプロバイダーなら、パートナーの案内でも受注 SES のプロバイダーの返事になる
    public function test_t28d_manual_link_of_provider_reply_goes_to_source_ses(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $mk = fn (string $from, string $subject) => (int) DB::table('inbound_mails')->insertGetId(['source' => 'file', 'raw_hash' => hash('sha256', $subject), 'fetched_at' => now_str(), 'received_at' => now_str(),
            'from_email' => $from, 'from_name' => '架空', 'subject' => $subject, 'body_text' => $subject.' 本文', 'status' => 'unlinked']);
        $id = $mk("p{$w['cid']}@example.test", 'PROVMANUAL-17');
        $this->assertSame('ok', \App\Services\Inbound::link($this->user(self::ADMIN), $id, (int) $cm->id)['type']);
        $row = DB::table('inbound_mails')->find($id);
        $this->assertSame('provider', $row->sender_kind);
        $this->assertSame($this->sesId(self::SES1), (int) $row->ses_id);
        $this->as(self::BX)->get('/inbound')->assertOk()->assertDontSee('PROVMANUAL-17');
        $this->as(self::SES1)->get('/inbound')->assertOk()->assertSee('PROVMANUAL-17');
        // 差出人がプロバイダーではない（エンジニアなど）ときは、今までどおりパートナーの行
        $id2 = $mk('other17@example.test', 'EGMANUAL-17');
        \App\Services\Inbound::link($this->user(self::ADMIN), $id2, (int) $cm->id);
        $row2 = DB::table('inbound_mails')->find($id2);
        $this->assertNotSame('provider', $row2->sender_kind);
        $this->assertSame($this->sesId(self::SES2), (int) $row2->ses_id);
    }

    // ---------- C：プロバイダーのタブ ----------

    private function providerWorld(): array
    {
        $p1 = $this->mkProvider('架空プロバイダー甲', 'pa17@example.test');
        $p2 = $this->mkProvider('架空プロバイダー乙', 'pb17@example.test');
        $a1 = $this->mkCase(['title' => 'ケースＡ一', 'raw_text' => 'RAW-A1 原文'], self::SES1, $p1);
        $a2 = $this->mkCase(['title' => 'ケースＡ二', 'raw_text' => 'RAW-A2 原文', 'status' => 'draft'], self::SES1, $p2);
        $b1 = $this->mkCase(['title' => 'ケースＢ一', 'raw_text' => 'RAW-B1 原文'], self::SES2, $p1);
        return compact('p1', 'p2', 'a1', 'a2', 'b1');
    }

    public function test_t23_source_ses_sees_own_cases_with_raw_text(): void
    {
        $this->providerWorld();
        $this->as(self::SES1)->get('/ses/providers/cases')->assertOk()->assertSee('受けた案件（原文）')->assertSee('プロバイダーの一覧')
            ->assertSee('ケースＡ一')->assertSee('ケースＡ二')->assertSee('RAW-A1')->assertSee('RAW-A2')->assertSee('架空プロバイダー甲')->assertSee('60万円')
            ->assertDontSee('ケースＢ一')->assertDontSee('RAW-B1');
        $this->as(self::SES1)->get('/ses/providers')->assertOk()->assertSee('受けた案件（原文）');
    }

    public function test_t24_filters(): void
    {
        $x = $this->providerWorld();
        $this->as(self::SES1)->get('/ses/providers/cases?provider='.$x['p1'])->assertOk()->assertSee('ケースＡ一')->assertDontSee('ケースＡ二');
        $this->as(self::SES1)->get('/ses/providers/cases?status=draft')->assertOk()->assertSee('ケースＡ二')->assertDontSee('ケースＡ一');
        $this->as(self::SES1)->get('/ses/providers/cases?q='.urlencode('Ａ二'))->assertOk()->assertSee('ケースＡ二')->assertDontSee('ケースＡ一');
    }

    public function test_t25_partner_does_not_see_the_source_cases(): void
    {
        $x = $this->providerWorld();
        $w = $this->world();
        $this->sendReferral($w); // A 社から B 社へ紹介の依頼
        $this->as(self::BX)->get('/ses/providers/cases?provider='.$x['p1'])->assertOk()->assertSee('ケースＢ一')->assertDontSee('ケースＡ一')->assertDontSee('RAW-A1')->assertDontSee('PROVRAW-MARKER-17');
    }

    public function test_t26_provider_detail_lists_own_cases(): void
    {
        $x = $this->providerWorld();
        $this->as(self::SES1)->get('/ses/providers/'.$x['p1'])->assertOk()->assertSee('この会社から受けた案件（自社）')->assertSee('ケースＡ一')->assertDontSee('ケースＢ一');
    }

    public function test_t27_admin_sees_all_with_source_ses_column(): void
    {
        $this->providerWorld();
        $this->as(self::ADMIN)->get('/admin/providers/cases')->assertOk()->assertSee('ケースＡ一')->assertSee('ケースＢ一')->assertSee('受注 SES')->assertSee('RAW-B1');
    }

    public function test_t28_engineer_cannot_open_either(): void
    {
        $this->providerWorld();
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $mail = $this->egLogin($b1, 'eg28-17@example.test');
        $this->as($mail)->get('/ses/providers/cases')->assertForbidden();
        $this->as($mail)->get('/admin/providers/cases')->assertForbidden();
    }
}
