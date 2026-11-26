<?php

namespace Tests\Feature;

use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.19.0 推薦文の登録・更新・消す、見える範囲（PLAN-v0190.md の A1〜A10・D1〜D3）。T14〜T36 の一部。名前・アドレスはすべて架空
class V0190IntroTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;
    use V0170World;

    protected $seed = true;

    private const MARK = 'MARK-INTRO-7Q';
    private const BODY = "・言語：Java（5年）、Python（2年）\n・DB：MySQL\n年齢：41歳\n希望月額：88万円\nMARK-INTRO-7Q";

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

    public function test_t17_a_staff_saves_and_skills_are_added_and_second_save_overwrites(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY)->assertRedirect("/engineers/$a1/intro");
        $row = DB::table('engineer_intros')->where('engineer_id', $a1)->first();
        $this->assertSame($this->sesId(self::SES1), (int) $row->ses_id);
        $this->assertContains('Java', $this->items($a1));
        $this->assertContains('Python', $this->items($a1));
        $this->assertSame('intro', $this->checkRows($a1)->firstWhere('item', 'Java')->source);
        $this->as(self::AS2)->get("/engineers/$a1/intro")->assertOk()->assertSee(self::MARK);
        $this->as(self::AS2)->followingRedirects()->post("/engineers/$a1/intro", ['body' => self::BODY])->assertOk()->assertSee('足したスキル');
        $this->as(self::AS2)->get("/engineers/$a1")->assertOk()->assertSee('推薦文（社内用）')->assertSee('本文を開く');
        $this->save($a1, "Java\n".self::MARK.'-2')->assertRedirect();
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $a1)->count());
    }

    public function test_t19_empty_and_too_long_are_refused(): void
    {
        $a1 = $this->a1();
        $this->save($a1, "  \n ")->assertSessionHas('flash.msg', '推薦文を入れてください。');
        $this->save($a1, str_repeat('あ', 10001))->assertSessionHas('flash.msg', '推薦文は10,000文字までにしてください。');
        $this->assertSame(0, DB::table('engineer_intros')->count());
        $this->save($a1, str_repeat('あ', 10000))->assertSessionHas('flash.type', 'ok');
        $this->assertSame(1, DB::table('engineer_intros')->count());
    }

    public function test_t14_t15_update_removes_only_intro_rows(): void
    {
        $a1 = $this->a1();
        $this->save($a1, "Java\nPython");
        $this->assertContains('Python', $this->items($a1));
        $this->save($a1, 'Java');
        $this->assertNotContains('Python', $this->items($a1));
        $this->assertContains('Java', $this->items($a1));
        // 画面でスキルを保存すると form になり、推薦文の更新で消えない
        $this->save($a1, "Java\nPython");
        $this->as(self::AS2)->post("/engineers/$a1/skills", $this->checkForm(['lang/Java' => '1年以上', 'lang/Python' => '1年以上']));
        $this->save($a1, 'Java');
        $this->assertContains('Python', $this->items($a1));
    }

    public function test_t13_existing_rows_are_kept(): void
    {
        $a1 = $this->a1();
        $this->as(self::AS2)->post("/engineers/$a1/skills", $this->checkForm(['lang/Java' => '1年以上']));
        $this->save($a1, 'Java（5年）');
        $r = $this->checkRows($a1)->firstWhere('item', 'Java');
        $this->assertSame('form', $r->source);
        $this->assertSame(1, (int) $r->years_min);
    }

    public function test_t21_delete_removes_text_and_intro_rows_only(): void
    {
        $a1 = $this->a1();
        $this->save($a1, "Java\nPython");
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete")->assertRedirect("/engineers/$a1");
        $this->assertSame(0, DB::table('engineer_intros')->count());
        $this->assertNotContains('Python', $this->items($a1));
    }

    public function test_t22_other_company_cannot_touch_it(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        $this->as(self::SES2)->get("/engineers/$a1/intro")->assertForbidden();
        $this->as(self::BX)->post("/engineers/$a1/intro", ['body' => 'Java'])->assertForbidden();
        $this->as(self::BX)->post("/engineers/$a1/intro/delete")->assertForbidden();
        $this->as(self::SES2)->get("/engineers/$a1")->assertForbidden();
    }

    public function test_t23_admin_reads_but_cannot_write(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        $this->as(self::ADMIN)->get("/engineers/$a1/intro")->assertOk()->assertSee(self::MARK)->assertDontSee('保存してスキルを抜き出す');
        $this->as(self::ADMIN)->get("/engineers/$a1")->assertOk()->assertSee(self::MARK);
        $this->as(self::ADMIN)->post("/engineers/$a1/intro", ['body' => 'Java'])->assertForbidden();
        $this->as(self::ADMIN)->post("/engineers/$a1/intro/delete")->assertForbidden();
    }

    public function test_t24_engineer_cannot_see_it_anywhere(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        $mail = $this->egLogin($a1, 'eg-intro19@example.test');
        foreach (["/engineers/$a1", "/engineers/$a1/edit", "/engineers/$a1/skills", "/engineers/$a1/sheet", "/engineers/$a1/messages", '/eg'] as $url) {
            $res = $this->as($mail)->get($url);
            $this->assertStringNotContainsString(self::MARK, $res->getContent(), $url);
            $this->assertStringNotContainsString('推薦文', $res->getContent(), $url);
        }
        $this->as($mail)->get("/engineers/$a1/intro")->assertForbidden();
        $this->as($mail)->post("/engineers/$a1/intro", ['body' => 'Java'])->assertForbidden();
    }

    public function test_t25_dual_role_person_cannot_see_own_intro(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        DB::table('users')->where('email', self::AS2)->update(['engineer_id' => $a1]);
        $this->as(self::AS2, 'ses')->get("/engineers/$a1/intro")->assertForbidden();
        $this->as(self::AS2, 'ses')->post("/engineers/$a1/intro", ['body' => 'Java'])->assertForbidden();
    }

    public function test_t26_left_engineer_is_read_only(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        DB::table('engineers')->where('id', $a1)->update(['status' => 'left']);
        $this->save($a1, 'Java')->assertSessionHas('flash.msg', '退会済みのエンジニアの推薦文は直せません。');
        $this->as(self::AS2)->get("/engineers/$a1/intro")->assertOk()->assertSee(self::MARK);
    }

    public function test_t27_after_moving_to_another_company_the_old_text_is_hidden(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        DB::table('engineers')->where('id', $a1)->update(['ses_id' => $this->sesId(self::SES2)]);
        $this->as(self::AS2)->get("/engineers/$a1/intro")->assertForbidden();
        $this->as(self::BX)->get("/engineers/$a1/intro")->assertOk()->assertDontSee(self::MARK);
        $this->as(self::ADMIN)->get("/engineers/$a1")->assertOk()->assertSee('前の所属');
        $this->as(self::BX)->post("/engineers/$a1/intro", ['body' => 'Java'])->assertRedirect();
        $this->assertSame(2, DB::table('engineer_intros')->where('engineer_id', $a1)->count());
    }

    public function test_t28_provisional_registration_with_intro(): void
    {
        $this->as(self::AS2)->get('/engineers/new')->assertOk()->assertSee('推薦文（任意・社内用）');
        $this->as(self::AS2)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '花子', 'email' => 'newintro19@example.test', 'intro' => self::BODY])
            ->assertSessionHas('flash.type', 'ok');
        $eid = (int) DB::table('users')->where('email', 'newintro19@example.test')->value('engineer_id');
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $eid)->count());
        $this->assertContains('Java', $this->items($eid));
    }

    // ---------- レビューの指摘（1〜11） ----------

    public function test_r1_cancel_of_provisional_person_with_intro(): void
    {
        $reg = fn (string $mail) => $this->as(self::AS2)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '取消', 'email' => $mail, 'intro' => "Java（3年）\n".self::MARK]);
        // 推薦文だけが入っている人は、取り消せて、エンジニアも消える
        $reg('cancel19a@example.test');
        $uid = (int) DB::table('users')->where('email', 'cancel19a@example.test')->value('id');
        $eid = (int) DB::table('users')->where('id', $uid)->value('engineer_id');
        $this->assertSame(1, DB::table('engineer_intros')->where('engineer_id', $eid)->count());
        $this->as(self::AS2)->post("/people/$uid/cancel")->assertSessionHas('flash.type', 'ok');
        $this->assertFalse(DB::table('engineers')->where('id', $eid)->exists());
        $this->assertSame(0, DB::table('engineer_skill_checks')->where('engineer_id', $eid)->count());
        // 経験票がある人は、エンジニアを残し、推薦文と推薦文から入れたスキルは消す
        $reg('cancel19b@example.test');
        $uid = (int) DB::table('users')->where('email', 'cancel19b@example.test')->value('id');
        $eid = (int) DB::table('users')->where('id', $uid)->value('engineer_id');
        $this->makeProject($eid, ['title' => '架空の案件']);
        $this->as(self::AS2)->post("/people/$uid/cancel")->assertSessionHas('flash.type', 'ok');
        $this->assertTrue(DB::table('engineers')->where('id', $eid)->exists());
        $this->assertSame(0, DB::table('engineer_intros')->where('engineer_id', $eid)->count());
        $this->assertSame(0, DB::table('engineer_skill_checks')->where('engineer_id', $eid)->where('source', 'intro')->count());
    }

    public function test_r2_check_stops_huge_or_broken_text_early(): void
    {
        [, $e1] = \App\Services\EngineerIntro::check(str_repeat('あ', 40000));
        $this->assertSame('推薦文は10,000文字までにしてください。', $e1);
        [, $e2] = \App\Services\EngineerIntro::check("Java\xff\xfe");
        $this->assertSame('推薦文の文字を読み取れませんでした。', $e2);
        [$b, $e3] = \App\Services\EngineerIntro::check("　 \n　Java　\n \t　");
        $this->assertNull($e3);
        $this->assertSame('Java', $b);
    }

    public function test_r5_r6_own_text_is_hidden_even_without_link_or_as_admin(): void
    {
        $a1 = $this->a1();
        $this->save($a1, self::BODY);
        // 紐づけが外れていても、メールアドレスが同じなら本人
        $mail = (string) DB::table('users')->where('email', self::AS2)->value('email');
        DB::table('engineers')->where('id', $a1)->update(['email' => strtoupper($mail)]);
        $this->as(self::AS2)->get("/engineers/$a1/intro")->assertForbidden();
        $this->as(self::AS2)->get("/engineers/$a1")->assertOk()->assertDontSee(self::MARK);
        // 管理者を兼ねる EG は、自分の分は読めない（ほかの人の分は読める）
        DB::table('engineers')->where('id', $a1)->update(['email' => 'eg-a1-19@example.test']);
        DB::table('users')->where('email', self::ADMIN)->update(['engineer_id' => $a1]);
        $admin = DB::table('users')->where('email', self::ADMIN)->first();
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B');
        $this->assertFalse(\App\Services\EngineerIntro::canView($admin, DB::table('engineers')->where('id', $a1)->first()));
        $this->assertTrue(\App\Services\EngineerIntro::canView($admin, DB::table('engineers')->where('id', $b1)->first()));
    }

    public function test_r7_intro_rows_follow_years_but_form_rows_do_not(): void
    {
        $a1 = $this->a1();
        $this->save($a1, 'Java（2年）');
        $this->assertSame(1, (int) $this->checkRows($a1)->firstWhere('item', 'Java')->years_min);
        $this->save($a1, 'Java（5年）')->assertSessionHas('intro_result', fn ($r) => in_array('Java', $r['updated'], true) && $r['kept'] === []);
        $this->assertSame(5, (int) $this->checkRows($a1)->firstWhere('item', 'Java')->years_min);
        // 画面で保存した（form）行は変えない
        $this->as(self::AS2)->post("/engineers/$a1/skills", $this->checkForm(['lang/Java' => '1年以上']));
        $this->save($a1, 'Java（5年）');
        $this->assertSame(1, (int) $this->checkRows($a1)->firstWhere('item', 'Java')->years_min);
    }

    public function test_r9_r10_delete_without_text_and_failed_save_keeps_no_body(): void
    {
        $a1 = $this->a1();
        $this->as(self::AS2)->post("/engineers/$a1/intro/delete")->assertSessionHas('flash.msg', '推薦文はありません。');
        $this->assertSame(0, DB::table('audit_log')->where('action', 'eg-intro-delete')->count());
        $this->save($a1, str_repeat('あ', 10001))->assertSessionMissing('_old_input.body');
    }

    // ---------- v0.19.1：推薦文なしで登録したあとの、推薦文の案内 ----------

    private function regEid(string $as, array $form): int
    {
        $this->as($as)->post('/engineers', $form)->assertSessionHas('flash.type', 'ok');
        return (int) DB::table('users')->where('email', $form['email'])->value('engineer_id');
    }

    public function test_v0191_prompt_shows_once_after_registration_without_intro(): void
    {
        $eid = $this->regEid(self::AS2, ['name_sei' => '架空', 'name_mei' => '案内', 'email' => 'prompt19a@example.test']);
        $this->get("/engineers/$eid")->assertOk()->assertSee('intro-prompt', false)->assertDontSee(self::MARK);
        $this->get("/engineers/$eid")->assertOk()->assertDontSee('intro-prompt', false); // リロードで消える
    }

    public function test_v0191_prompt_not_shown_with_intro_admin_self_or_engineer(): void
    {
        $eid = $this->regEid(self::AS2, ['name_sei' => '架空', 'name_mei' => '入力', 'email' => 'prompt19b@example.test', 'intro' => "Java\n".self::MARK]);
        $this->get("/engineers/$eid")->assertOk()->assertDontSee('intro-prompt', false);
        // 管理者
        $eid = $this->regEid(self::ADMIN, ['ses_id' => $this->sesId(self::SES1), 'name_sei' => '架空', 'name_mei' => '管理', 'email' => 'prompt19c@example.test']);
        $this->get("/engineers/$eid")->assertOk()->assertDontSee('intro-prompt', false);
        // 自分自身を足したとき
        $this->as(self::AS2, 'ses')->post('/engineers', ['name_sei' => '架空', 'name_mei' => '本人', 'email' => self::AS2])->assertSessionMissing('intro_prompt');
        // EG は、詳細を開いても出ない（フラッシュを仕込まれても、登録できる立場でないので出ない）
        $eid = $this->a1();
        $mail = $this->egLogin($eid, 'eg-prompt19@example.test');
        $this->as($mail)->withSession(['intro_prompt' => 'sent'])->get("/engineers/$eid")->assertOk()->assertDontSee('intro-prompt', false);
    }

    public function test_t29_too_long_intro_stops_the_registration(): void
    {
        $users = DB::table('users')->count();
        $this->as(self::AS2)->post('/engineers', ['name_sei' => '架空', 'name_mei' => '次郎', 'email' => 'long19@example.test', 'intro' => str_repeat('あ', 10001)])
            ->assertSessionHas('flash.type', 'bad');
        $this->assertSame($users, DB::table('users')->count());
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    public function test_t30_admin_registration_has_no_intro_field(): void
    {
        $ses = $this->sesId(self::SES1);
        $this->as(self::ADMIN)->get("/engineers/new?ses=$ses")->assertOk()->assertDontSee('推薦文');
        $this->as(self::ADMIN)->post('/engineers', ['ses_id' => $ses, 'name_sei' => '架空', 'name_mei' => '三郎', 'email' => 'adm19@example.test', 'intro' => self::BODY]);
        $this->assertSame(0, DB::table('engineer_intros')->count());
    }

    public function test_t32_company_stays_fixed_for_staff(): void
    {
        $this->as(self::AS2)->post('/engineers', ['ses_id' => $this->sesId(self::SES2), 'name_sei' => '架空', 'name_mei' => '四郎', 'email' => 'fix19@example.test']);
        $eid = (int) DB::table('users')->where('email', 'fix19@example.test')->value('engineer_id');
        $this->assertSame($this->sesId(self::SES1), (int) DB::table('engineers')->where('id', $eid)->value('ses_id'));
    }

    public function test_t33_t34_t35_text_does_not_leak_to_audit_ai_or_documents(): void
    {
        $a1 = $this->a1();
        $ai = DB::table('ai_jobs')->count();
        $this->save($a1, self::BODY);
        $this->assertSame($ai, DB::table('ai_jobs')->count());
        $log = DB::table('audit_log')->where('action', 'eg-intro-save')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString(self::MARK, (string) $log->detail);
        $this->assertStringNotContainsString('Java', (string) $log->detail);
        $this->assertStringNotContainsString('自社 太郎', (string) $log->detail);
        // 書類・メール・お知らせ・案件の画面にも出ない
        $this->as(self::AS2)->post("/engineers/$a1/docs/generate", ['kind' => 'careersheet']);
        foreach (DB::table('engineer_files')->where('engineer_id', $a1)->get() as $f) $this->assertStringNotContainsString(self::MARK, (string) $f->title);
        foreach (['mail_log', 'notifications'] as $t) {
            if (!\Illuminate\Support\Facades\Schema::hasTable($t)) continue;
            foreach (DB::table($t)->get() as $row) $this->assertStringNotContainsString(self::MARK, json_encode($row, JSON_UNESCAPED_UNICODE), $t);
        }
        $this->as(self::AS2)->get("/engineers/$a1/sheet")->assertOk();
        $this->assertStringNotContainsString(self::MARK, $this->as(self::AS2)->get("/engineers/$a1/sheet")->getContent());
    }

    public function test_t36_version(): void
    {
        $this->assertSame('v0.22.0', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.22.0');
    }
}
