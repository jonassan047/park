<?php

namespace Tests\Feature;

use App\Services\CaseMail;
use App\Services\CaseSearch as CS;
use App\Services\Inbound;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// v0.16.0 EG検索の結果をプロパー／パートナーに分け、エンジニア・担当者へのメール作成画面を作る（PLAN.md の A1〜G4）。
// 名前・本文・アドレスはすべて架空（@park.example / @example.test）。
// プレーヤー：管理者・SES・担当者（A 社＝受注 SES、B 社＝パートナー）・EG
class V0160PartnerComposeTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';   // A 社（受注 SES）
    private const SES2 = 'ses2@park.example';   // B 社（パートナー）
    private const SES3 = 'ses3@park.example';   // 関係のない会社
    private const BX = 'bx16@example.test';     // B 社の担当者 X（依頼先）
    private const BY = 'by16@example.test';     // B 社の担当者 Y（同じ会社の別の人）
    private const AS2 = 'as16@example.test';    // A 社の別の担当者
    private const RAW = "架空のメール本文ABC\n■必須\n・Java\n・SQL";

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        config(['mail.from.address' => 'park@example.test']);
        DB::table('engineers')->update(['status' => 'unavailable']); // テストで作る人だけで確かめる
        $b = $this->sesId(self::SES2);
        foreach ([[self::BX, '架空 担当X', $b], [self::BY, '架空 担当Y', $b], [self::AS2, '架空 担当A', $this->sesId(self::SES1)]] as [$mail, $name, $ses]) {
            $this->completeProfile(W::createUser($mail, $name, 'ses', 'park1234', ['ses_id' => $ses]));
        }
        DB::table('ses_companies')->where('id', $b)->update(['rep_user_id' => $this->user(self::BX)->id]); // B 社の SES（代表者）は X
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    private function mkCase(array $over = []): int
    {
        return W::saveCase($over + ['title' => '十六テスト案件', 'price' => 60, 'required_skills' => 'Java、SQL', 'preferred_skills' => 'AWS',
            'description' => '架空の作業内容', 'status' => 'open', 'raw_text' => self::RAW], $this->user(self::SES1));
    }

    private function mkEng(string $sesEmail, string $name, string $initials, array $over = []): int
    {
        $id = W::createEngineer($this->sesId($sesEmail), ['name' => $name, 'kana' => 'てすと たろう', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str(), 'initials' => $initials,
            'status' => 'available', 'email' => "eg{$id}@example.test"]);
        SkillChecks::saveFromNames($id, [['Java', 'lang', 12], ['SQL', 'db', 12]], 'seed');
        return $id;
    }

    private function search(int $cid, string $as = self::SES1): int
    {
        $this->as($as)->post("/cases/$cid/search", [])->assertRedirect();
        return (int) DB::table('case_searches')->where('case_id', $cid)->where('created_by', $this->user($as)->id)->value('id');
    }

    // A 社の EG 2名（A1・A2）と B 社の EG 1名（B1）がいる、募集中の案件と検索
    private function world(): array
    {
        $cid = $this->mkCase();
        $a1 = $this->mkEng(self::SES1, '自社 太郎', 'A.A');
        $a2 = $this->mkEng(self::SES1, '自社 次郎', 'A.B');
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $sid = $this->search($cid);
        return compact('cid', 'a1', 'a2', 'b1', 'sid');
    }

    // 作成画面の本文欄（textarea）の中身
    private function bodyOf(string $as, int $sid): string
    {
        $html = $this->as($as)->get("/searches/$sid/compose")->assertOk()->getContent();
        preg_match('/<textarea name="body"[^>]*>(.*?)<\/textarea>/s', $html, $m);
        return html_entity_decode($m[1] ?? '', ENT_QUOTES);
    }

    // 担当者の作成画面まで進めて、担当者 X への依頼を送る（単価を $price に直す）。case_search_staff の id を返す
    private function sendReferral(array $w, string $price = '50万円'): int
    {
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['b1']]])->assertRedirect("/searches/{$w['sid']}/compose");
        $stid = (int) DB::table('case_search_staff')->where('search_id', $w['sid'])->value('id');
        $body = str_replace('■単価：60万円', '■単価：'.$price, $this->bodyOf(self::SES1, $w['sid']));
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/staff/$stid", ['subject' => '【紹介のお願い】十六テスト案件', 'body' => $body])->assertRedirect();
        return $stid;
    }

    // ---------- A：一覧 ----------
    public function test_a1_a2_two_tables_and_partner_row_label(): void
    {
        $w = $this->world();
        $label = CS::staffLabel($this->user(self::BX));
        $r = $this->as(self::SES1)->get("/searches/{$w['sid']}")->assertOk();
        $r->assertSee('プロパー（自社のエンジニア）')->assertSee('パートナー（他社のエンジニア）')->assertSee('自社 太郎')
            ->assertSee('B.B（'.$label.'）')->assertDontSee('他社 花子');
        $r->assertSee('name="pick[]" value="'.$w['a1'].'"', false)->assertSee('name="pick[]" value="'.$w['b1'].'"', false);
        $this->assertStringNotContainsString('EG-', explode('パートナー（他社のエンジニア）', $r->getContent())[1] ?? '', 'パートナーの行に EG- は出さない');
    }

    public function test_a3_staff_decision_order(): void
    {
        $w = $this->world();
        // v0.17.0：担当者は EG ごとの担当者（engineers.staff_user_id）。使えない人が入っていれば会社の SES。それも使えなければ空
        DB::table('engineers')->where('id', $w['b1'])->update(['staff_user_id' => $this->user(self::BY)->id]);
        $e = DB::table('engineers')->find($w['b1']);
        $this->assertSame(self::BY, CS::staffFor($e)->email);
        DB::table('users')->where('email', self::BY)->update(['active' => 0]);
        $this->assertSame(self::BX, CS::staffFor($e)->email); // 会社の SES（代表者）
        DB::table('users')->where('email', self::BX)->update(['active' => 0]);
        $this->assertNull(CS::staffFor($e)); // SES も使えなければ、担当者はいない
    }

    public function test_a3_no_staff_cannot_be_checked(): void
    {
        $w = $this->world();
        DB::table('users')->where('ses_id', $this->sesId(self::SES2))->update(['active' => 0]);
        $r = $this->as(self::SES1)->get("/searches/{$w['sid']}")->assertOk();
        $r->assertSee('担当者がいません')->assertDontSee('name="pick[]" value="'.$w['b1'].'"', false);
    }

    public function test_a4_a6_texts_and_empty_compose(): void
    {
        $w = $this->world();
        $r = $this->as(self::SES1)->get("/searches/{$w['sid']}")->assertOk();
        $r->assertDontSee('全員へのメッセージ')->assertDontSee('name="message_all"', false)->assertSee('エンジニア・担当者を追加')->assertSee('エンジニア・担当者へメールを作成')
            ->assertSee('チェックを保存')->assertDontSee('エンジニアへメールを送信');
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => []])->assertRedirect("/searches/{$w['sid']}")
            ->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '送る相手を選んでください'));
    }

    public function test_a5_find_own_engineer_and_other_company_staff_but_not_other_engineers(): void
    {
        $w = $this->world();
        $x = $this->user(self::BX);
        $found = CS::findTargets($this->user(self::SES1), DB::table('cases')->find($w['cid']), 'あおぞらテック');
        $this->assertContains('staff', array_column($found, 'type'));
        $this->assertNotContains('engineer', array_column($found, 'type'));
        $this->assertSame([], CS::findTargets($this->user(self::SES1), DB::table('cases')->find($w['cid']), '他社 花子'));
        $this->assertCount(1, CS::findTargets($this->user(self::SES1), DB::table('cases')->find($w['cid']), '自社 次郎'));
        $this->as(self::SES1)->post("/searches/{$w['sid']}/staff", ['user_id' => $x->id])->assertRedirect();
        $row = DB::table('case_search_staff')->where('search_id', $w['sid'])->first();
        $this->assertSame([1, (int) $x->id], [(int) $row->by_hand, (int) $row->staff_user_id]);
        $this->as(self::SES1)->get("/searches/{$w['sid']}")->assertOk()->assertSee('（エンジニアの指定なし）')->assertSee('name="spick[]" value="'.$row->id.'"', false);
        // 自社の担当者は足せない
        $this->as(self::SES1)->post("/searches/{$w['sid']}/staff", ['user_id' => $this->user(self::AS2)->id])->assertRedirect();
        $this->assertSame(1, DB::table('case_search_staff')->where('search_id', $w['sid'])->count());
    }

    // ---------- B：作成画面（プロパー） ----------
    public function test_b1_b3_proper_compose_and_send_with_price(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['a1'], $w['a2'], $w['b1']]])->assertRedirect("/searches/{$w['sid']}/compose");
        $body = $this->bodyOf(self::SES1, $w['sid']);
        $this->assertStringContainsString('■単価：60万円', $body);
        $this->assertStringContainsString('{呼び名}', $body);
        $this->as(self::SES1)->get("/searches/{$w['sid']}/compose")->assertOk()->assertSee('送るエンジニア（2 名）')->assertSee('エンジニアへメールを送信')->assertSee('エンジニアを選択に戻る');
    }

    public function test_b3_send_proper_sets_price_text_and_skips_partner_engineers(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['a1'], $w['a2'], $w['b1']]])->assertRedirect();
        $body = str_replace('■単価：60万円', '■単価：55万円', $this->bodyOf(self::SES1, $w['sid']));
        // 次は担当者の画面（B4）
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/proper", ['subject' => '件名', 'body' => $body])->assertRedirect("/searches/{$w['sid']}/compose");
        $rows = DB::table('case_mails')->where('case_id', $w['cid'])->get()->keyBy('engineer_id');
        $this->assertSame([$w['a1'], $w['a2']], $rows->keys()->sort()->values()->all());
        $this->assertSame(['55万円', '55万円'], [$rows[$w['a1']]->price_text, $rows[$w['a2']]->price_text]);
        foreach ($rows as $m) {
            $this->assertStringNotContainsString('{', $m->body);
            $this->assertStringContainsString('■単価：55万円', $m->body);
        }
        $this->assertStringContainsString('自社 太郎', $rows[$w['a1']]->body);
        $this->assertStringContainsString('自社 次郎', $rows[$w['a2']]->body);
        $this->assertFalse(DB::table('case_mails')->where('engineer_id', $w['b1'])->exists(), '他社のエンジニアには、直接送らない');
        $this->assertSame(1, DB::table('case_search_staff')->where('search_id', $w['sid'])->where('in_batch', 1)->count());
    }

    public function test_b4_b5_next_screen_and_partner_only_start(): void
    {
        $w = $this->world();
        // B5：プロパーを選ばず B の EG だけ → 担当者の画面から
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['b1']]])->assertRedirect("/searches/{$w['sid']}/compose");
        $this->as(self::SES1)->get("/searches/{$w['sid']}/compose")->assertOk()->assertSee('担当者へメールを送信')->assertDontSee('エンジニアへメールを送信');
        // B4：未送信の担当者がいなければ案件の画面へ
        $w2 = $this->world2();
        $this->as(self::SES1)->post("/searches/{$w2['sid']}/compose", ['pick' => [$w2['a1']]])->assertRedirect();
        $body = $this->bodyOf(self::SES1, $w2['sid']);
        $this->as(self::SES1)->post("/searches/{$w2['sid']}/compose/proper", ['subject' => '件名', 'body' => $body])->assertRedirect("/cases/{$w2['cid']}#mails");
    }

    // 担当者のいない世界（プロパーだけ）
    private function world2(): array
    {
        $cid = $this->mkCase(['title' => '十六テスト案件その2']);
        $a1 = $this->mkEng(self::SES1, '自社 三郎', 'A.C');
        return ['cid' => $cid, 'a1' => $a1, 'sid' => $this->search($cid)];
    }

    public function test_b2_confirm_dialog_texts_are_in_the_page(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['a1']]])->assertRedirect();
        $this->as(self::SES1)->get("/searches/{$w['sid']}/compose")->assertOk()
            ->assertSee('のままです。', false)->assertSee('に変えましたか？', false)->assertSee('エンジニア向けの金額', false)->assertSee('プロバイダーからの金額', false)
            ->assertSee('「■単価：」の行がありません', false)->assertSee('」に変えました。', false)->assertSee('data-n="1"', false);
    }

    public function test_g1_double_post_sends_once(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['a1']]])->assertRedirect();
        $body = $this->bodyOf(self::SES1, $w['sid']);
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/proper", ['subject' => '件名', 'body' => $body])->assertRedirect();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/proper", ['subject' => '件名', 'body' => $body])->assertRedirect();
        $this->assertSame(1, DB::table('case_mails')->where('case_id', $w['cid'])->where('engineer_id', $w['a1'])->count());
    }

    public function test_old_send_route_moves_to_compose(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/send", ['pick' => [$w['a1']]])->assertRedirect("/searches/{$w['sid']}/compose");
        $this->assertSame(0, DB::table('case_mails')->count(), '古い /send では直接送らない');
    }

    // ---------- C：作成画面（担当者） ----------
    public function test_c1_c2_staff_screen_and_send(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['b1']]])->assertRedirect();
        $stid = (int) DB::table('case_search_staff')->where('search_id', $w['sid'])->value('id');
        $this->as(self::SES1)->get("/searches/{$w['sid']}/compose")->assertOk()->assertSee('すべてを選択')->assertSee('同じ条件で送信する際はチェックを入れてください')
            ->assertSee('<input type="checkbox" checked disabled>', false)->assertSee('担当者へメールを送信')->assertSee('この担当者には今回は送らない');
        $body = $this->bodyOf(self::SES1, $w['sid']);
        $this->assertStringContainsString('{宛名}', $body);
        $this->assertStringContainsString('{依頼するエンジニア}', $body);
        $body = str_replace('■単価：60万円', '■単価：50万円', $body);
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/staff/$stid", ['subject' => '【紹介のお願い】十六テスト案件', 'body' => $body])
            ->assertRedirect("/cases/{$w['cid']}#mails");
        $x = $this->user(self::BX);
        $st = DB::table('case_search_staff')->find($stid);
        $this->assertNotNull($st->sent_at);
        $this->assertSame('50万円', $st->price_text);
        $mail = DB::table('mail_outbox')->where('kind', 'case-referral')->first();
        $this->assertSame($x->email, $mail->to_email);
        $this->assertNull($mail->reply_to, '他社の人に個人のアドレスを出さない');
        $this->assertStringContainsString('B.B', $mail->body);
        $this->assertStringNotContainsString('他社 花子', $mail->body, 'エンジニアの氏名は入れない');
        $this->assertStringContainsString("/cases/{$w['cid']}#referral", $mail->body);
        $this->assertStringNotContainsString('{', $mail->body);
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $x->id)->where('link', "/cases/{$w['cid']}#referral")->where('title', 'like', 'エンジニアの紹介の依頼：%')->exists());
        $this->assertTrue(DB::table('audit_log')->where('action', 'case-referral-send')->exists());
        $this->assertFalse(DB::table('audit_log')->where('action', 'case-referral-send')->where('detail', 'like', '%@%')->exists());
    }

    public function test_c3_c4_next_staff_and_skip_and_c5_resume(): void
    {
        $w = $this->world();
        $b2 = $this->mkEng(self::SES2, '他社 次郎', 'B.C');
        // 別の担当者（B 社の Y）が担当の EG は、Y が担当者になる（v0.17.0：EG ごとの担当者）
        DB::table('engineers')->where('id', $b2)->update(['staff_user_id' => $this->user(self::BY)->id]);
        $sid = $this->search($w['cid']);
        $this->as(self::SES1)->post("/searches/$sid/compose", ['pick' => [$w['b1'], $b2]])->assertRedirect("/searches/$sid/compose");
        $this->assertSame(2, DB::table('case_search_staff')->where('search_id', $sid)->where('in_batch', 1)->count());
        // C5：途中で一覧に戻ると、続きの案内が出る
        $this->as(self::SES1)->get("/searches/$sid")->assertOk()->assertSee('まだ送っていない担当者が 2 名います')->assertSee('続きを作成する');
        // C3：一番上（id が小さい行）だけ送ると、次の担当者の画面
        $first = DB::table('case_search_staff')->where('search_id', $sid)->orderBy('id')->first();
        $this->as(self::SES1)->post("/searches/$sid/compose/staff/{$first->id}", ['subject' => '件名', 'body' => $this->bodyOf(self::SES1, $sid)])->assertRedirect("/searches/$sid/compose");
        $this->assertSame(1, DB::table('case_search_staff')->where('search_id', $sid)->whereNotNull('sent_at')->count());
        // C4：残りは今回は送らない → 案件の画面へ。送られていない
        $second = DB::table('case_search_staff')->where('search_id', $sid)->where('id', '!=', $first->id)->first();
        $this->as(self::SES1)->post("/searches/$sid/compose/staff/{$second->id}/skip")->assertRedirect("/cases/{$w['cid']}#mails");
        $this->assertNull(DB::table('case_search_staff')->find($second->id)->sent_at);
        $this->assertSame(0, (int) DB::table('case_search_staff')->find($second->id)->in_batch);
    }

    public function test_c2_others_checked_get_same_body(): void
    {
        $w = $this->world();
        $b2 = $this->mkEng(self::SES2, '他社 次郎', 'B.C');
        DB::table('engineers')->where('id', $b2)->update(['staff_user_id' => $this->user(self::BY)->id]);
        $sid = $this->search($w['cid']);
        $this->as(self::SES1)->post("/searches/$sid/compose", ['pick' => [$w['b1'], $b2]])->assertRedirect();
        $rows = DB::table('case_search_staff')->where('search_id', $sid)->orderBy('id')->get();
        $this->as(self::SES1)->post("/searches/$sid/compose/staff/{$rows[0]->id}", ['subject' => '件名', 'body' => $this->bodyOf(self::SES1, $sid), 'others' => [$rows[1]->id]])
            ->assertRedirect("/cases/{$w['cid']}#mails");
        $this->assertSame(2, DB::table('mail_outbox')->where('kind', 'case-referral')->count());
        $this->assertSame(2, DB::table('case_search_staff')->where('search_id', $sid)->whereNotNull('sent_at')->count());
    }

    public function test_g1_same_staff_not_sent_twice_from_a_colleague(): void
    {
        $w = $this->world();
        $this->sendReferral($w);
        // A 社の別の担当者の検索：同じ B の担当者 X には送られず、一覧では「依頼済み」
        $sid2 = $this->search($w['cid'], self::AS2);
        $r = $this->as(self::AS2)->get("/searches/$sid2")->assertOk();
        $r->assertSee('依頼済み')->assertDontSee('name="pick[]" value="'.$w['b1'].'"', false);
        $this->as(self::AS2)->post("/searches/$sid2/compose", ['pick' => [$w['b1']]])->assertRedirect("/searches/$sid2")
            ->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '送る相手を選んでください'));
        $this->assertSame(1, DB::table('mail_outbox')->where('kind', 'case-referral')->count());
    }

    // ---------- D：パートナーの担当者 ----------
    public function test_d1_d2_referral_card_for_the_company(): void
    {
        $w = $this->world();
        $this->sendReferral($w);
        foreach ([self::BX, self::BY] as $who) { // 会社の誰が開いても見える
            $this->as($who)->get("/cases/{$w['cid']}")->assertOk()->assertSee('id="referral"', false)->assertSee('紹介の依頼')->assertSee('50万円')->assertSee('他社 花子')
                ->assertSee('みどり情報システム［', false);
        }
        // お知らせは依頼先の1人（X）だけ
        $this->assertSame(1, DB::table('app_notifications')->where('user_id', $this->user(self::BX)->id)->where('title', 'like', 'エンジニアの紹介の依頼：%')->count());
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $this->user(self::BY)->id)->where('title', 'like', 'エンジニアの紹介の依頼：%')->count());
        // 関係のない会社には出ない（F2）
        $this->as(self::SES3)->get("/cases/{$w['cid']}")->assertOk()->assertDontSee('id="referral"', false);
    }

    public function test_d3_d4_search_own_engineers_only_then_send_as_partner(): void
    {
        $w = $this->world();
        $stid = $this->sendReferral($w);
        $this->as(self::BX)->post("/cases/{$w['cid']}/referral/$stid/search")->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user(self::BX)->id)->value('id');
        $this->assertSame($stid, (int) DB::table('case_searches')->find($sid)->request_id);
        $r = $this->as(self::BX)->get("/searches/$sid")->assertOk();
        $r->assertSee('他社 花子')->assertDontSee('自社 太郎')->assertDontSee('パートナー（他社のエンジニア）')->assertDontSee('メールアドレスを追加')
            ->assertSee('name="pick[]" value="'.$w['b1'].'" checked', false)->assertSee('伝えられた単価：50万円');
        // 「エンジニア・担当者を追加」で A 社の担当者・エンジニアは探せない
        $this->as(self::BX)->get("/searches/$sid?q=".urlencode('みどり情報システム'))->assertOk()->assertSee('見つかりませんでした');
        $this->as(self::BX)->post("/searches/$sid/extras", ['extra_text' => 'x@example.test'])->assertRedirect()
            ->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
        // D4：作成画面（プロパーだけ）。単価ははじめから伝えられた単価
        $this->as(self::BX)->post("/searches/$sid/compose", ['pick' => [$w['b1']]])->assertRedirect("/searches/$sid/compose");
        $body = $this->bodyOf(self::BX, $sid);
        $this->assertStringContainsString('■単価：50万円', $body);
        $this->as(self::BX)->post("/searches/$sid/compose/proper", ['subject' => '件名', 'body' => $body])->assertRedirect("/cases/{$w['cid']}#mails");
        $m = DB::table('case_mails')->where('engineer_id', $w['b1'])->first();
        $this->assertSame(['partner', $this->sesId(self::SES2), '50万円', 'own'], [$m->mode, (int) $m->ses_id, $m->price_text, $m->route]);
    }

    public function test_d5_decline_notifies_requester(): void
    {
        $w = $this->world();
        $stid = $this->sendReferral($w);
        $this->as(self::BX)->post("/cases/{$w['cid']}/referral/$stid/decline", ['note' => '今回は難しいです'])->assertRedirect();
        $st = DB::table('case_search_staff')->find($stid);
        $this->assertNotNull($st->declined_at);
        $this->assertSame('今回は難しいです', $st->declined_note);
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $this->user(self::SES1)->id)->where('title', 'like', '紹介の依頼の辞退：%')->exists());
        $this->as(self::BX)->get("/cases/{$w['cid']}")->assertOk()->assertSee('辞退しました')->assertDontSee('自社のエンジニアを検索する');
        $this->assertTrue(DB::table('audit_log')->where('action', 'case-referral-decline')->exists());
        // 受注 SES の側：辞退と出る
        $this->as(self::SES1)->get("/cases/{$w['cid']}")->assertOk()->assertSee('辞退');
    }

    public function test_d6_d7_partner_replies_stay_inside_partner_company(): void
    {
        config(['park.inbound.host' => 'imap.example.test', 'park.inbound.user' => 'x', 'park.inbound.password' => 'secret-pass']);
        $w = $this->world();
        $stid = $this->sendReferral($w);
        $this->as(self::BX)->post("/cases/{$w['cid']}/referral/$stid/search")->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user(self::BX)->id)->value('id');
        $this->as(self::BX)->post("/searches/$sid/compose", ['pick' => [$w['b1']]])->assertRedirect();
        $this->as(self::BX)->post("/searches/$sid/compose/proper", ['subject' => '件名', 'body' => $this->bodyOf(self::BX, $sid)])->assertRedirect();
        $cm = DB::table('case_mails')->where('engineer_id', $w['b1'])->first();
        $tok = DB::table('reply_tokens')->where('case_mail_id', $cm->id)->first();
        $eml = implode("\r\n", ['From: eg'.$w['b1'].'@example.test', 'To: park+'.$tok->token.'@example.test', 'Subject: Re: 案件', 'Date: '.date('r'), 'Message-ID: <'.bin2hex(random_bytes(8)).'@mail.example.test>',
            'MIME-Version: 1.0', 'Content-Type: text/plain; charset=UTF-8', 'Content-Transfer-Encoding: 8bit'])."\r\n\r\n興味があります。\r\n";
        $r = Inbound::ingest($eml, 'file');
        $m = DB::table('inbound_mails')->find($r['id']);
        $this->assertSame('linked', $m->status);
        $this->assertSame($this->sesId(self::SES2), (int) $m->ses_id);
        // B 社の担当者は見られる。お知らせも届く
        $this->as(self::BX)->get('/inbound/'.$r['id'])->assertOk()->assertSee('興味があります。');
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $this->user(self::BX)->id)->where('title', 'like', '%案内メールへの返信%')->exists());
        // 受注 SES（A 社）は見られない。プロバイダー宛の下書きもできない
        $this->as(self::SES1)->get('/inbound/'.$r['id'])->assertNotFound();
        $this->assertFalse(DB::table('provider_mails')->where('inbound_mail_id', $r['id'])->exists());
        // 案件の画面：案内済メールには partner の行が出ず、「担当者への依頼」に出る
        $page = $this->as(self::SES1)->get("/cases/{$w['cid']}")->assertOk();
        $page->assertSee('担当者への依頼')->assertSee('あおぞらテック［', false)->assertDontSee('興味があります。');
        // やり取りの相手は自社（所属の会社）
        $this->assertSame($this->sesId(self::SES2), \App\Services\CaseThread::partnerFor(DB::table('cases')->find($w['cid']), DB::table('engineers')->find($w['b1'])));
    }

    // ---------- F：管理者・権限 ----------
    public function test_f1_admin_reads_only_and_f2_others_forbidden(): void
    {
        $w = $this->world();
        $this->sendReferral($w);
        $this->as(self::ADMIN)->get("/cases/{$w['cid']}")->assertOk()->assertSee('担当者への依頼');
        $this->as(self::ADMIN)->get("/searches/{$w['sid']}")->assertForbidden();
        $this->as(self::ADMIN)->get("/searches/{$w['sid']}/compose")->assertForbidden();
        $this->as(self::SES3)->get("/searches/{$w['sid']}")->assertForbidden();
        $this->as(self::SES3)->get("/searches/{$w['sid']}/compose")->assertForbidden();
        $this->as(self::SES3)->post("/searches/{$w['sid']}/compose/proper", ['subject' => 'x', 'body' => 'y'])->assertForbidden();
        $this->as('eg1@park.example')->get("/searches/{$w['sid']}/compose")->assertForbidden();
        // 依頼先の担当者でも、依頼元の検索は開けない（作った会社だけ）
        $this->as(self::BX)->get("/searches/{$w['sid']}")->assertForbidden();
    }

    // ---------- E：EG の金額 ----------
    public function test_e1_eg_sees_the_price_text_not_the_provider_price(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['a1']]])->assertRedirect();
        $body = str_replace('■単価：60万円', '■単価：55万円', $this->bodyOf(self::SES1, $w['sid']));
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/proper", ['subject' => '件名', 'body' => $body])->assertRedirect();
        $uid = W::createUser('eg16a1@example.test', '架空 EG甲', 'eg', 'park1234', ['engineer_id' => $w['a1']]);
        $this->completeProfile($uid);
        foreach (['/eg/offers', '/eg', "/cases/{$w['cid']}"] as $path) {
            $this->as('eg16a1@example.test')->get($path)->assertOk()->assertSee('55万円')->assertDontSee('60万円');
        }
    }

    public function test_e1_no_mail_means_ask_the_staff(): void
    {
        $w = $this->world();
        $c = DB::table('cases')->find($w['cid']);
        $this->assertSame(CaseMail::NO_PRICE, CaseMail::priceFor($c, $w['a2']));
        $this->assertSame('担当者にご確認ください', CaseMail::NO_PRICE);
    }

    public function test_price_text_helper(): void
    {
        $this->assertSame('55万円', CS::priceTextOf("あ\n■単価：  55万円  \nい"));
        $this->assertNull(CS::priceTextOf("あ\nい"));
        $this->assertSame(100, mb_strlen(CS::priceTextOf('■単価：'.str_repeat('あ', 150))));
    }

    // ---------- レビュー指摘の再発防止 ----------

    // パートナー経由の案内メール（B 社の X が自社の b1 に送った分）まで進める。case_mails の行を返す
    private function partnerMail(array $w): object
    {
        $stid = $this->sendReferral($w);
        $this->as(self::BX)->post("/cases/{$w['cid']}/referral/$stid/search")->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user(self::BX)->id)->value('id');
        $this->as(self::BX)->post("/searches/$sid/compose", ['pick' => [$w['b1']]])->assertRedirect();
        $this->as(self::BX)->post("/searches/$sid/compose/proper", ['subject' => '件名', 'body' => $this->bodyOf(self::BX, $sid)])->assertRedirect();
        return DB::table('case_mails')->where('engineer_id', $w['b1'])->first();
    }

    // H1：追加の宛先に2件同時に送っても、2通目に1人目の名前は入らず、案内番号は1つだけ
    public function test_h1_two_extras_each_get_own_name_and_one_tag(): void
    {
        $w = $this->world();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/extras", ['extra_text' => "架空一 <x1@example.test>\n架空二 <x2@example.test>"])->assertRedirect();
        $this->assertSame(2, DB::table('case_search_extras')->where('search_id', $w['sid'])->count());
        $xp = DB::table('case_search_extras')->where('search_id', $w['sid'])->pluck('id')->all();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['a1']], 'xpick' => $xp])->assertRedirect();
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/proper", ['subject' => '件名', 'body' => $this->bodyOf(self::SES1, $w['sid'])])->assertRedirect();
        $mails = DB::table('mail_outbox')->where('kind', 'case-search-extra')->orderBy('id')->get();
        $this->assertCount(2, $mails);
        $this->assertStringContainsString('架空一 様', $mails[0]->body);
        $this->assertStringContainsString('架空二 様', $mails[1]->body);
        $this->assertStringNotContainsString('架空一', $mails[1]->body, '2通目に1人目の名前を入れない');
        foreach ($mails as $m) $this->assertSame(1, substr_count($m->body, '案内番号：'), '案内番号は1つだけ');
        $this->assertNotSame($mails[0]->subject, $mails[1]->subject, '件名の案内の印は宛先ごとに別');
    }

    // H2：パートナー経由の返事は、受注 SES（A 社）の「今やること」に出ず、B 社に出る
    public function test_h2_partner_reply_todo_only_for_partner_company(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        CaseMail::reply($w['cid'], $w['b1'], 'interested', null, 'link');
        $texts = fn (int $ses) => array_column(\App\Services\Todo::ses($ses), 'text');
        $a = implode("\n", $texts($this->sesId(self::SES1)));
        $this->assertStringNotContainsString('応募したい', $a);
        $this->assertStringNotContainsString('他社 花子', $a);
        $this->assertStringNotContainsString('B.B', $a);
        $b = implode("\n", $texts($this->sesId(self::SES2)));
        $this->assertStringContainsString('他社 花子 さんが「応募する」', $b, '自社の EG なので氏名で出る');
        $this->assertSame('partner', $cm->mode);
    }

    // L2：パートナー経由の行は、受注 SES が書類・結果・プロバイダー宛の下書きに使えない
    public function test_l2_partner_row_actions_are_closed_to_source_ses(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->as(self::SES1)->get("/case-mails/{$cm->id}/doc/careersheet-anon")->assertForbidden();
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/result", ['result' => 'waiting'])->assertForbidden();
        $this->as(self::SES1)->post("/case-mails/{$cm->id}/provider-draft")->assertForbidden();
    }

    // L6：管理者の「案内済メール」には partner の行も出る。受注 SES には出ない
    public function test_l6_admin_sees_partner_mail_row_but_source_ses_does_not(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->assertNotNull($cm);
        $this->as(self::ADMIN)->get("/cases/{$w['cid']}")->assertOk()->assertSee('案内済メール')->assertSee('他社 花子');
        $this->as(self::SES1)->get("/cases/{$w['cid']}")->assertOk()->assertDontSee('他社 花子');
    }

    // L3：返事のお知らせは、パートナー経由なら B 社が見られる「紹介の依頼」へ
    public function test_l3_reply_notice_link_for_partner_mail(): void
    {
        $w = $this->world();
        $cm = $this->partnerMail($w);
        $this->post('/r/'.$cm->token, ['answer' => 'interested'])->assertOk();
        $this->assertTrue(DB::table('app_notifications')->where('user_id', $this->user(self::BX)->id)->where('link', "/cases/{$w['cid']}#referral")->where('title', 'like', '%さんから返事%')->exists());
    }

    // T15：別の会社（B 社と C 社）の担当者を、一番上＋others[] で一緒に送ると、宛名とエンジニアがそれぞれ別
    public function test_t15_two_companies_staff_in_one_send(): void
    {
        $w = $this->world();
        $c1 = $this->mkEng(self::SES3, '三社 太郎', 'C.C');
        $this->search($w['cid']); // 後から増えた EG を、検索し直して一覧に入れる
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose", ['pick' => [$w['b1'], $c1]])->assertRedirect();
        $rows = DB::table('case_search_staff')->where('search_id', $w['sid'])->orderBy('id')->get();
        $this->assertCount(2, $rows);
        $this->assertNotSame($rows[0]->partner_ses_id, $rows[1]->partner_ses_id);
        $this->as(self::SES1)->post("/searches/{$w['sid']}/compose/staff/{$rows[0]->id}", ['subject' => '件名', 'body' => $this->bodyOf(self::SES1, $w['sid']), 'others' => [$rows[1]->id]])->assertRedirect();
        $mails = DB::table('mail_outbox')->where('kind', 'case-referral')->get()->keyBy('to_email');
        $this->assertCount(2, $mails);
        $bStaff = CS::staffFor(DB::table('engineers')->find($w['b1']));
        $cStaff = CS::staffFor(DB::table('engineers')->find($c1));
        $this->assertStringContainsString('B.B', $mails[$bStaff->email]->body);
        $this->assertStringNotContainsString('C.C', $mails[$bStaff->email]->body);
        $this->assertStringContainsString('C.C', $mails[$cStaff->email]->body);
        $this->assertStringNotContainsString('B.B', $mails[$cStaff->email]->body);
    }

    // T26：関係のない C 社は、B 社の担当者の検索を開けない
    public function test_t26_other_company_cannot_open_partner_search(): void
    {
        $w = $this->world();
        $stid = $this->sendReferral($w);
        $this->as(self::BX)->post("/cases/{$w['cid']}/referral/$stid/search")->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user(self::BX)->id)->value('id');
        $this->as(self::SES3)->get("/searches/$sid")->assertForbidden();
        $this->as(self::BY)->get("/searches/$sid")->assertOk(); // 同じ会社の担当者は開ける（PLAN 0章・canOpen）
    }

    // T27：B 社の EG が書いたやり取りは、B 社が受ける（受注 SES の A 社の未読には出ない）
    public function test_t27_eg_thread_goes_to_own_company_for_partner_mail(): void
    {
        $w = $this->world();
        $this->partnerMail($w);
        $uid = W::createUser('eg16b1@example.test', '架空 EG丙', 'eg', 'park1234', ['engineer_id' => $w['b1']]);
        $this->completeProfile($uid);
        $this->as('eg16b1@example.test')->post("/cases/{$w['cid']}/threads/{$w['b1']}", ['body' => '架空の質問です'])->assertRedirect();
        $t = DB::table('case_threads')->where('case_id', $w['cid'])->where('engineer_id', $w['b1'])->first();
        $this->assertNotNull($t);
        $this->assertSame($this->sesId(self::SES2), \App\Services\CaseThread::partnerOf($t));
        $this->assertSame(0, \App\Services\CaseThread::unreadForSes($this->sesId(self::SES1))->count());
        $this->assertSame(1, \App\Services\CaseThread::unreadForSes($this->sesId(self::SES2))->count());
    }

    // T31：案内メールの無い EG の画面の金額は「担当者にご確認ください」
    public function test_t31_eg_without_mail_sees_ask_the_staff(): void
    {
        $w = $this->world();
        DB::table('case_offers')->insert(['case_id' => $w['cid'], 'engineer_id' => $w['a2'], 'score' => 50, 'reasons' => '', 'missing' => '', 'sent_by' => $this->user(self::SES1)->id, 'sent_at' => now_str()]);
        $uid = W::createUser('eg16a2@example.test', '架空 EG乙', 'eg', 'park1234', ['engineer_id' => $w['a2']]);
        $this->completeProfile($uid);
        $this->as('eg16a2@example.test')->get("/cases/{$w['cid']}")->assertOk()->assertSee('担当者にご確認ください')->assertDontSee('60万円');
    }

    // L7：パートナー側の検索の「メールの見本」は、伝えられた単価
    public function test_l7_partner_member_sample_uses_told_price(): void
    {
        $w = $this->world();
        $stid = $this->sendReferral($w);
        $this->as(self::BX)->post("/cases/{$w['cid']}/referral/$stid/search")->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $w['cid'])->where('created_by', $this->user(self::BX)->id)->value('id');
        $this->as(self::BX)->get("/searches/$sid/members/{$w['b1']}")->assertOk()->assertSee('■単価：50万円')->assertDontSee('60万円');
    }

    // T32（マイグレーションの埋め戻し）は、テストでは作らない：テストの DB はすでにこのマイグレーションを通っており、
    // 作り直すと他のテストの表を壊すため。「■単価：」の行を読む正規表現は test_price_text_helper と同じ形で確かめている。

    public function test_version(): void
    {
        $this->assertSame('v0.17.0', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.17.0');
    }
}
