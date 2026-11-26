<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\SkillChecks;
use App\Support\IntroSkills;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.19.0 推薦文の境界ケース（tester が追加。PLAN-v0190.md の A4〜A10・B4・B5・C3・D1・D2）。名前・アドレスはすべて架空
class V0190IntroEdgeTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;
    use V0170World;

    protected $seed = true;

    private const MARK = 'MARK-EDGE-9Z';
    private const BODY = "・言語：Java（5年）、Python（2年）\n年齢：39歳\n希望月額：77万円\nMARK-EDGE-9Z";

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        SkillCatalog::syncDetail();
    }

    private function a1(): int
    {
        $id = $this->mkEng(self::SES1, '自社 太郎', 'A.A');
        $this->cleanEg($id);
        return $id;
    }

    private function save(int $eid, string $body, string $as = self::AS2)
    {
        return $this->as($as)->post("/engineers/$eid/intro", ['body' => $body]);
    }

    private function items(int $eid): array
    {
        return $this->checkRows($eid)->pluck('item')->all();
    }

    // ---------- 立場・会社の境界 ----------

    public function test_engineer_cannot_delete_or_open_even_another_engineers_intro(): void
    {
        $a1 = $this->a1();
        $a2 = $this->mkEng(self::SES1, '自社 次郎', 'A.B');
        $this->save($a1, self::BODY);
        $mail = $this->egLogin($a2, 'eg-edge-a2@example.test');
        $this->as($mail)->post("/engineers/$a1/intro/delete")->assertForbidden();
        $this->as($mail)->get("/engineers/$a1/intro")->assertForbidden();
        $this->as($mail)->post("/engineers/$a2/intro/delete")->assertForbidden();
        $this->as($mail)->get("/engineers/$a2/intro")->assertForbidden();
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $a1)->count());
    }

    public function test_guest_is_sent_to_login(): void
    {
        $a1 = $this->a1();
        $this->flushSession();
        $this->get("/engineers/$a1/intro")->assertRedirect();
        $this->post("/engineers/$a1/intro", ['body' => 'Java'])->assertRedirect();
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    public function test_other_company_attempts_change_nothing(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        $before = DB::table('engineer_intros')->where('engineer_id', $a1)->first();
        $skills = $this->checkRows($a1)->count();
        foreach ([self::SES2, self::BX, self::BY, self::SES3] as $who) {
            $this->as($who)->post("/engineers/$a1/intro", ['body' => "Ruby\nMARK-OTHER"])->assertForbidden();
            $this->as($who)->post("/engineers/$a1/intro/delete")->assertForbidden();
            $this->as($who)->get("/engineers/$a1/intro")->assertForbidden();
        }
        $after = DB::table('engineer_intros')->where('engineer_id', $a1)->first();
        $this->assertSame($before->body, $after->body);
        $this->assertSame($skills, $this->checkRows($a1)->count());
        $this->assertNotContains('Ruby', $this->items($a1));
    }

    public function test_other_company_staff_cannot_use_a_new_engineers_registration_to_reach_other_engineer(): void
    {
        // 他社 EG の id で保存しても、自社の EG にも他社の EG にも入らない
        $a1 = $this->a1();
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $this->as(self::AS2)->post("/engineers/$b1/intro", ['body' => 'Java'])->assertForbidden();
        $this->as(self::AS2)->post("/engineers/$b1/intro/delete")->assertForbidden();
        $this->as(self::BX)->post("/engineers/$a1/intro", ['body' => 'Java'])->assertForbidden();
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    public function test_unknown_engineer_id_is_not_found(): void
    {
        $this->as(self::AS2)->get('/engineers/99999999/intro')->assertNotFound();
        $this->as(self::AS2)->post('/engineers/99999999/intro', ['body' => 'Java'])->assertNotFound();
        $this->as(self::ADMIN)->get('/engineers/99999999/intro')->assertNotFound();
    }

    public function test_admin_page_for_engineer_without_text_has_no_buttons(): void
    {
        $a1 = $this->a1();
        $this->as(self::ADMIN)->get("/engineers/$a1/intro")->assertOk()->assertDontSee('保存してスキルを抜き出す')->assertDontSee('推薦文を消す');
        $this->as(self::ADMIN)->get("/engineers/$a1")->assertOk()->assertDontSee('推薦文を登録する');
    }

    public function test_old_company_cannot_write_or_delete_after_move_and_new_company_text_is_separate(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        DB::table('engineers')->where('id', $a1)->update(['ses_id' => $this->sesId(self::SES2)]);
        $this->as(self::AS2)->post("/engineers/$a1/intro", ['body' => 'Ruby'])->assertForbidden();
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete")->assertForbidden();
        $this->as(self::BX)->post("/engineers/$a1/intro/delete")->assertSessionHas('flash.msg', '推薦文はありません。');
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $a1)->count()); // 前の会社の分は消えない
        // 新しい会社が保存したあとも、前の会社の本文は新しい会社の画面に出ない
        $this->save($a1, "Go\nMARK-NEW-B", self::BX);
        $this->as(self::BX)->get("/engineers/$a1/intro")->assertOk()->assertSee('MARK-NEW-B')->assertDontSee(self::MARK);
        $this->as(self::BY)->get("/engineers/$a1")->assertOk()->assertDontSee(self::MARK);
        $this->as(self::ADMIN)->get("/engineers/$a1/intro")->assertOk()->assertSee(self::MARK)->assertSee('MARK-NEW-B');
    }

    public function test_left_engineer_cannot_be_deleted_either(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        DB::table('engineers')->where('id', $a1)->update(['status' => 'left']);
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete")->assertSessionHas('flash.msg', '退会済みのエンジニアの推薦文は直せません。');
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $a1)->count());
    }

    public function test_dual_role_admin_cannot_view_or_write_own_intro_but_staff_dual_cannot_delete(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        DB::table('users')->where('email', self::AS2)->update(['engineer_id' => $a1]);
        $this->as(self::AS2, 'ses')->post("/engineers/$a1/intro/delete")->assertForbidden();
        $this->as(self::AS2, 'ses')->get("/engineers/$a1")->assertOk()->assertDontSee(self::MARK)->assertDontSee('推薦文（社内用）');
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $a1)->count());
        // 同じ会社のほかの担当者は読める
        $this->as(self::SES1)->get("/engineers/$a1/intro")->assertOk()->assertSee(self::MARK);
    }

    public function test_self_registration_with_intro_does_not_store_it(): void
    {
        $this->as(self::AS2)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '自分', 'email' => self::AS2, 'intro' => self::BODY]);
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    // ---------- 本文が出ない場所 ----------

    public function test_text_never_reaches_engineer_screens_documents_or_partner_views(): void
    {
        $w = $this->world();
        $this->save($w['a1'], self::BODY);
        $mail = $this->egLogin($w['a1'], 'eg-edge-leak@example.test');
        foreach (["/engineers/{$w['a1']}", "/engineers/{$w['a1']}/edit", "/engineers/{$w['a1']}/skills", "/engineers/{$w['a1']}/sheet", "/engineers/{$w['a1']}/messages", '/eg', '/eg/cases', '/home'] as $url) {
            $res = $this->as($mail)->get($url);
            $this->assertStringNotContainsString(self::MARK, $res->getContent(), $url);
            $this->assertStringNotContainsString('39歳', $res->getContent(), $url);
            $this->assertStringNotContainsString('77万円', $res->getContent(), $url);
        }
        // パートナー（B 社）に送る紹介の依頼（先に送り、作成画面の本文も見る）
        $this->sendReferral($w);
        // 案件の画面・検索（A 社側）
        $this->assertStringNotContainsString(self::MARK, $this->as(self::SES1)->get("/cases/{$w['cid']}")->getContent());
        $this->assertStringNotContainsString(self::MARK, $this->as(self::SES1)->get("/searches/{$w['sid']}")->getContent());
        $this->assertStringNotContainsString(self::MARK, $this->as(self::SES1)->get("/engineers")->getContent());
        // 送ったメール・お知らせと、B 社の画面
        foreach (['mail_outbox', 'app_notifications', 'case_mails'] as $t) {
            $this->assertGreaterThan(0, DB::table($t)->count() + ($t === 'case_mails' ? 1 : 0), $t);
            foreach (DB::table($t)->get() as $row) $this->assertStringNotContainsString(self::MARK, json_encode($row, JSON_UNESCAPED_UNICODE), $t);
        }
        $this->assertStringNotContainsString(self::MARK, $this->as(self::BX)->get("/cases/{$w['cid']}")->getContent());
        $this->assertStringNotContainsString(self::MARK, $this->as(self::BX)->get('/engineers')->getContent());
        // 書類（業務経歴書・スキルシート）と一覧の検索
        foreach (['careersheet', 'resume'] as $kind) {
            $this->as(self::AS2)->post("/engineers/{$w['a1']}/docs/generate", ['kind' => $kind]);
        }
        foreach (DB::table('engineer_files')->where('engineer_id', $w['a1'])->get() as $f) {
            $this->assertStringNotContainsString(self::MARK, json_encode($f, JSON_UNESCAPED_UNICODE));
        }
        $this->assertStringNotContainsString('39歳', json_encode(\App\Services\EngineerDocs::skillLines($w['a1']), JSON_UNESCAPED_UNICODE));
        // 本文で EG は探せない（他社・自社の一覧とも、本文の目印の検索は 0 件）
        $this->assertStringNotContainsString('自社 太郎', $this->as(self::BX)->get('/engineers?q='.self::MARK)->getContent());
        $this->assertStringNotContainsString('自社 太郎', $this->as(self::AS2)->get('/engineers?q='.self::MARK)->getContent());
    }

    public function test_audit_log_for_save_and_delete_has_counts_only(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete");
        foreach (['eg-intro-save', 'eg-intro-delete'] as $act) {
            $log = DB::table('audit_log')->where('action', $act)->first();
            $this->assertNotNull($log, $act);
            foreach ([self::MARK, 'Java', 'Python', '自社 太郎', '39歳', '77万円', 'eg'.$a1.'@example.test'] as $needle) {
                $this->assertStringNotContainsString($needle, (string) $log->detail, "$act: $needle");
            }
        }
        foreach (DB::table('audit_log')->get() as $row) $this->assertStringNotContainsString(self::MARK, json_encode($row, JSON_UNESCAPED_UNICODE));
    }

    public function test_session_flash_of_failed_save_does_not_echo_body(): void
    {
        $a1 = $this->a1();
        $res = $this->save($a1, self::MARK.str_repeat('あ', 10001));
        $res->assertSessionMissing('_old_input.body');
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    // ---------- 空・上限 ----------

    public function test_control_chars_only_and_crlf_are_handled(): void
    {
        $a1 = $this->a1();
        $this->save($a1, "\x01\x02\x03")->assertSessionHas('flash.msg', '推薦文を入れてください。');
        $this->save($a1, "Java\r\nPython\r\n")->assertSessionHas('flash.type', 'ok');
        $this->assertStringNotContainsString("\r", (string) DB::table('engineer_intros')->where('engineer_id', $a1)->value('body'));
        $this->assertContains('Python', $this->items($a1));
    }

    public function test_exactly_10000_chars_then_10001_keeps_the_old_text(): void
    {
        $a1 = $this->a1();
        $this->save($a1, "Java\n".str_repeat('x', 9995))->assertSessionHas('flash.type', 'ok'); // 4+1+9995 = 10000
        $this->assertSame(10000, mb_strlen(DB::table('engineer_intros')->where('engineer_id', $a1)->value('body')));
        $this->save($a1, "Ruby\n".str_repeat('x', 9996))->assertSessionHas('flash.msg', '推薦文は10,000文字までにしてください。');
        $this->assertStringStartsWith('Java', DB::table('engineer_intros')->where('engineer_id', $a1)->value('body'));
        $this->assertContains('Java', $this->items($a1));
    }

    public function test_missing_or_array_body_is_refused_not_a_server_error(): void
    {
        $a1 = $this->a1();
        $this->as(self::AS2)->post("/engineers/$a1/intro", [])->assertSessionHas('flash.msg', '推薦文を入れてください。');
        $this->as(self::AS2)->post("/engineers/$a1/intro", ['body' => ['Java']])->assertSessionHas('flash.msg', '推薦文を入れてください。');
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    public function test_provisional_registration_with_exact_limit_and_with_whitespace_only(): void
    {
        $this->as(self::AS2)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '上限', 'email' => 'limit19@example.test', 'intro' => str_repeat('あ', 10000)])->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('engineer_intros')->count());
        // 空白だけの推薦文は「入れなかった」扱いで、仮登録はできる
        $this->as(self::AS2)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '空白', 'email' => 'blank19@example.test', 'intro' => " \n　 "])->assertSessionHas('flash.type', 'ok');
        $this->assertTrue(DB::table('users')->where('email', 'blank19@example.test')->exists());
        $this->assertSame(1, DB::table('engineer_intros')->count());
    }

    // ---------- 更新・重複・form 行 ----------

    public function test_update_that_drops_a_word_never_deletes_form_or_seed_rows(): void
    {
        $a1 = $this->a1();
        SkillChecks::saveFromNames($a1, [['SQL', 'db', 12]], 'seed');
        $this->as(self::AS2)->post("/engineers/$a1/skills", $this->checkForm(['lang/Java' => '3年以上']));
        $this->save($a1, "Java\nPython\nSQL");
        $this->save($a1, "Go"); // Java・SQL は intro 由来ではないので残る。Python は消える
        $items = $this->items($a1);
        $this->assertContains('Java', $items);
        $this->assertNotContains('Python', $items);
        $this->assertContains('Go', $items);
        $this->assertSame('form', $this->checkRows($a1)->firstWhere('item', 'Java')->source);
        $this->save($a1, "Java\nGo");
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete");
        $this->assertContains('Java', $this->items($a1));
        $this->assertNotContains('Go', $this->items($a1));
    }

    public function test_same_text_twice_and_duplicate_words_do_not_make_duplicate_rows(): void
    {
        $a1 = $this->a1();
        $this->save($a1, "Java\nJava（3年）\nJAVA\nJava、Java");
        $n = $this->checkRows($a1)->where('item', 'Java')->count();
        $this->assertSame(1, $n);
        $keys = $this->checkRows($a1)->map(fn ($r) => $r->section.'|'.$r->parent.'|'.$r->item.'|'.$r->other_text)->all();
        $this->assertSame($keys, array_values(array_unique($keys)));
        $this->save($a1, "Java\nJava（3年）\nJAVA\nJava、Java");
        $this->assertSame($keys, $this->checkRows($a1)->map(fn ($r) => $r->section.'|'.$r->parent.'|'.$r->item.'|'.$r->other_text)->all());
    }

    public function test_extract_edge_inputs(): void
    {
        $this->assertSame([], IntroSkills::extract('')['rows']);
        $this->assertSame([], IntroSkills::extract("\n\n  \n")['rows']);
        $r = IntroSkills::extract(str_repeat("Java\n", 3000));
        $this->assertCount(1, array_filter($r['rows'], fn ($x) => $x['item'] === 'Java'));
        // 見出しだけで中身が無い行
        $this->assertSame([], IntroSkills::extract("【人物】\n【備考】\n希望月額：")['rows']);
        // 年数に全角数字
        $this->assertNotNull(IntroSkills::extract('Java（５年）')['rows']);
    }

    public function test_extract_langs_overseas_words(): void
    {
        $keys = fn (string $b) => array_map(fn ($x) => $x['section'].'/'.$x['item'], IntroSkills::extract($b)['rows']);
        $this->assertContains('global/中国語', $keys('中国語（ビジネス）'));
        $this->assertContains('global/韓国語', $keys('韓国語'));
        $this->assertContains('global/ベトナム語', $keys('ベトナム語'));
        $this->assertContains('global/海外拠点との連携', $keys('海外拠点との連携あり'));
        $this->assertContains('global/その他', $keys('外国語'));
        $this->assertContains('global/英語', $keys('ビジネス英語'));
        $this->assertContains('global/ブリッジSE', $keys('ブリッジSE'));
    }

    // ---------- 技術マスタ追加と案件・マッチング（C3・T5・T6） ----------

    public function test_case_requirements_english_bse_offshore_are_picked_and_matched_via_global_section(): void
    {
        $cid = $this->mkCase(['required_skills' => 'Java', 'raw_text' => null]);
        $k = \App\Support\CaseKeywords::split(['ビジネスレベルの英語', 'ブリッジSE', 'オフショア経験'], []);
        DB::table('cases')->where('id', $cid)->update(['required_skills' => implode('、', $k['required_skills']), 'required_exp' => implode('、', $k['required_exp'])]);
        $c = DB::table('cases')->find($cid);
        $a1 = $this->a1();
        SkillChecks::saveFromNames($a1, [['Java', 'lang', 12]], 'seed');
        $e = DB::table('engineers')->find($a1);
        $none = Matcher::score($c, $e);
        foreach (['必須 英語', '必須 ブリッジSE', '必須 オフショア開発'] as $m) $this->assertContains($m, $none['missing'], $m);
        // 画面（form）で global を保存すると合う
        $this->as(self::AS2)->post("/engineers/$a1/skills", $this->checkForm(['lang/Java' => '1年以上', 'global/英語' => '', 'global/ブリッジSE' => '', 'global/オフショア開発 / ニアショア開発' => '']));
        $e = DB::table('engineers')->find($a1);
        $hit = Matcher::score($c, $e);
        foreach (['必須 英語', '必須 ブリッジSE', '必須 オフショア開発'] as $m) $this->assertNotContains($m, $hit['missing'], $m);
        $this->assertGreaterThan($none['score'], $hit['score']);
    }

    public function test_intro_text_makes_the_engineer_match_the_new_case_words(): void
    {
        $cid = $this->mkCase(['required_skills' => '英語、ブリッジSE', 'raw_text' => null]);
        $a1 = $this->a1();
        $this->save($a1, "英語：日常会話\nブリッジSE");
        $r = Matcher::score(DB::table('cases')->find($cid), DB::table('engineers')->find($a1));
        $this->assertNotContains('必須 英語', $r['missing']);
        $this->assertNotContains('必須 ブリッジSE', $r['missing']);
    }

    public function test_existing_case_fields_are_not_rewritten_by_sync_or_intro(): void
    {
        $cid = $this->mkCase(['required_skills' => 'Java、SQL', 'raw_text' => null]);
        $before = (array) DB::table('cases')->find($cid);
        SkillCatalog::syncDetail();
        $a1 = $this->a1();
        $this->save($a1, "英語\nJava");
        $after = (array) DB::table('cases')->find($cid);
        $this->assertSame($before['required_skills'], $after['required_skills']);
        $this->assertSame($before['required_exp'] ?? null, $after['required_exp'] ?? null);
        $this->assertSame($before['preferred_skills'], $after['preferred_skills']);
    }

    public function test_intro_does_not_queue_ai_jobs_or_mail(): void
    {
        $a1 = $this->a1();
        $ai = DB::table('ai_jobs')->count();
        $mails = DB::table('mail_outbox')->count();
        $notes = DB::table('app_notifications')->count();
        $this->save($a1, self::BODY);
        $this->save($a1, 'Java');
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete");
        $this->assertSame($ai, DB::table('ai_jobs')->count());
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame($notes, DB::table('app_notifications')->count());
    }
}
