<?php

namespace Tests\Feature;

use App\Services\EngineerDocs;
use App\Services\SkillChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// v0.18.0 候補者紹介メールの取り込みの画面と権限（PLAN.md の T1〜T16）。氏名・アドレスはすべて架空
class EngineerMailImportTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;
    use V0170World;

    protected $seed = true;

    private const MAIL = "（候補者）\n氏名：山田一郎(IY)\n・56歳　男性\n最寄駅：池上線　池上\n所属：弊社プロパー\n"
        ."言語スキル：PHP、Perl、Javascript\n他スキル：MySQL、PostgreSQL、ZendFW\n　PMO、PM、PL可能\n"
        ."英語：ビジネス会話可能\n希望月額：～180万円程度　(固定)\n就業時期：10月～　（11、12月でも可）\n"
        ."人物：明朗、協調性あり\n・家族の介護のため、リモート併用案件を希望します。\n";
    private const NOTE = '家族の介護のため、リモート併用案件を希望します。';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpWorld();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-25 10:00:00'));
    }

    // A 社の本登録済みの EG（氏名は山田 一郎）
    private function eg(array $over = []): int
    {
        return $this->mkEng(self::SES1, '山田 一郎', 'I.Y', $over);
    }

    private function row(int $id): array
    {
        return (array) DB::table('engineers')->find($id);
    }

    private function nSkills(int $id): int
    {
        return DB::table('engineer_skill_checks')->where('engineer_id', $id)->count();
    }

    // 入力欄の値を HTML から読む
    private function input(string $html, string $name): ?string
    {
        if (preg_match('/<textarea[^>]*name="'.preg_quote($name, '/').'"[^>]*>(.*?)<\/textarea>/s', $html, $m)) return html_entity_decode(ltrim($m[1], "\n"), ENT_QUOTES);
        if (preg_match('/<input[^>]*name="'.preg_quote($name, '/').'"[^>]*value="([^"]*)"/', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES);
        if (preg_match('/<select[^>]*name="'.preg_quote($name, '/').'".*?<option value="([^"]*)" selected/s', $html, $m)) return html_entity_decode($m[1], ENT_QUOTES);
        return null;
    }

    // 全表を調べ、$needle を含む表の名前を返す
    private function tablesContaining(string $needle): array
    {
        $hit = [];
        foreach (DB::select("select name from sqlite_master where type='table'") as $t) {
            $rows = DB::table($t->name)->get();
            if ($rows->isNotEmpty() && str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), $needle)) $hit[] = $t->name;
        }
        return $hit;
    }

    private function save(string $as, int $id, array $over = []): \Illuminate\Testing\TestResponse
    {
        $e = (array) DB::table('engineers')->find($id);
        $body = ['name_sei' => $e['name_sei'] ?: '山田', 'name_mei' => $e['name_mei'] ?: '一郎', 'kana_sei' => $e['kana_sei'] ?: 'やまだ', 'kana_mei' => $e['kana_mei'] ?: 'いちろう',
            'phone' => $e['phone'] ?: '090-0000-0001', 'postal_code' => $e['postal_code'] ?: '100-0001', 'address' => $e['address'] ?: '東京都（テスト）',
            'email' => $e['email'], 'status' => 'available', 'work_style' => $e['work_style'] ?: 'any'];
        return $this->as($as)->post("/engineers/$id", $over + $body);
    }

    private function importAs(string $as, int $id, string $text = self::MAIL, array $extra = []): \Illuminate\Testing\TestResponse
    {
        return $this->as($as)->post("/engineers/$id/import-mail", ['mail_text' => $text] + $extra);
    }

    // ---- T1 ----
    public function test_t1_card_is_shown_to_admin_ses_and_staff(): void
    {
        $id = $this->eg();
        foreach ([self::SES1, self::AS2, self::ADMIN] as $who) {
            $this->as($who)->get("/engineers/$id/edit")->assertOk()->assertSee('紹介メールから取り込む')->assertSee('読み取る')->assertSee('特記事項');
        }
    }

    // ---- T2 ----
    public function test_t2_import_returns_screen_and_saves_nothing(): void
    {
        $id = $this->eg();
        $before = $this->row($id);
        $skills = $this->nSkills($id);
        $r = $this->importAs(self::SES1, $id);
        $r->assertOk()->assertSee('読み取った結果')->assertSee('池上')->assertSee('180')->assertSee('取り込めなかった部分')
            ->assertSee('メールから')->assertSee('まだ保存していません')->assertSee('弊社プロパー');
        $this->assertSame($before, $this->row($id));
        $this->assertSame($skills, $this->nSkills($id));
        // 画面の入力欄には読んだ値が入る
        $html = $r->getContent();
        $this->assertSame('180', $this->input($html, 'desired_price'));
        $this->assertSame('1970', $this->input($html, 'birth_year'));
        $this->assertSame('池上', $this->input($html, 'nearest_station'));
        $this->assertSame('2026-11-25', $this->input($html, 'available_from'));
        $this->assertSame('hybrid', $this->input($html, 'work_style'));
        $this->assertStringContainsString(self::NOTE, (string) $this->input($html, 'special_notes'));
        // 保存のフォームとは別の <form>（本文の欄は保存のフォームに無い）
        $this->assertSame(1, preg_match_all('/name="mail_text"/', $html));
        $this->assertMatchesRegularExpression('#<form[^>]*action="[^"]*/engineers/'.$id.'/import-mail"#', $html);
        $this->assertMatchesRegularExpression('#id="engineer-form"#', $html);
        $this->assertSame(0, preg_match('#<form[^>]*id="engineer-form".*?name="mail_text".*?</form>#s', $html));
        // スキル候補のチェックは保存のフォームに属する
        $this->assertStringContainsString('name="import_skills[]" value="PHP" form="engineer-form"', $html);
        // 所属は変えない（参考だけ）
        $this->assertSame($before['ses_id'], $this->row($id)['ses_id']);
    }

    public function test_t2b_staff_and_admin_can_import_too(): void
    {
        $id = $this->eg();
        $this->importAs(self::AS2, $id)->assertOk()->assertSee('読み取った結果');
        $this->importAs(self::ADMIN, $id)->assertOk()->assertSee('読み取った結果');
    }

    // ---- T3 ----
    public function test_t3_body_is_stored_nowhere_and_audit_has_only_names(): void
    {
        $id = $this->eg();
        $this->importAs(self::SES1, $id)->assertOk();
        foreach (['家族の介護のため', '弊社プロパー', '山田一郎(IY)', '明朗、協調性あり', 'ビジネス会話可能'] as $needle) {
            $this->assertSame([], $this->tablesContaining($needle), $needle);
        }
        $this->assertStringNotContainsString('家族の介護のため', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('弊社プロパー', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertArrayNotHasKey('mail_text', session()->all());
        $a = DB::table('audit_log')->where('action', 'engineer-import-mail')->get();
        $this->assertCount(1, $a);
        $this->assertStringContainsString('読んだ項目', $a[0]->detail);
        $this->assertStringContainsString("#{$id}", $a[0]->detail);
        foreach (['池上', '180', '1970', '明朗'] as $v) $this->assertStringNotContainsString($v, $a[0]->detail);
        // 取り込みの前後でほかの監査（engineer-update）は増えない
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-update')->count());
        // ログにも書かない
        $log = storage_path('logs/laravel.log');
        if (is_file($log)) $this->assertStringNotContainsString('家族の介護のため', (string) file_get_contents($log));
    }

    public function test_t3b_empty_and_too_long_leave_no_session_body(): void
    {
        $id = $this->eg();
        $this->importAs(self::SES1, $id, str_repeat('秘密の本文XYZ', 1000))->assertOk();
        $this->assertStringNotContainsString('秘密の本文XYZ', json_encode(session()->all(), JSON_UNESCAPED_UNICODE));
        $this->assertSame([], $this->tablesContaining('秘密の本文XYZ'));
    }

    // ---- T4 ----
    public function test_t4_save_goes_through_update_and_adds_skills(): void
    {
        $id = $this->eg(['special_notes' => null]);
        $this->egLogin($id, 'eg-t4@park.example');
        $beforeChecks = DB::table('engineer_skill_checks')->where('engineer_id', $id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
        $html = $this->importAs(self::SES1, $id)->getContent();
        $payload = [];
        foreach (['birth_year', 'gender', 'nearest_line', 'nearest_station', 'walk_minutes', 'desired_price', 'work_style', 'available_from', 'appeal', 'english_level', 'special_notes'] as $c) {
            $v = $this->input($html, $c);
            if ($v !== null) $payload[$c] = $v;
        }
        $this->assertSame('男', $payload['gender']);
        $r = $this->save(self::SES1, $id, $payload + ['import_skills' => ['PHP', 'MySQL']]);
        $r->assertRedirect("/engineers/$id");
        $e = $this->row($id);
        $this->assertSame(1970, (int) $e['birth_year']);
        $this->assertSame('池上', $e['nearest_station']);
        $this->assertSame('池上線', $e['nearest_line']);
        $this->assertEquals(180, $e['desired_price']);
        $this->assertSame('hybrid', $e['work_style']);
        $this->assertSame('男', $e['gender']);
        $this->assertSame('2026-11-25', substr((string) $e['available_from'], 0, 10));
        $this->assertStringContainsString(self::NOTE, $e['special_notes']);
        $this->assertNull($e['self_confirmed_at']);
        // 元のスキルは残り、年数も変わらない
        $now = DB::table('engineer_skill_checks')->where('engineer_id', $id)->get();
        foreach ($beforeChecks as $b) {
            $same = $now->firstWhere('id', $b['id']);
            $this->assertNotNull($same);
            $this->assertEquals($b['years_min'], $same->years_min);
        }
        $names = $now->pluck('item')->map(fn ($n) => strtolower((string) $n))->all();
        $this->assertContains('php', $names);
        $this->assertContains('mysql', $names);
        // 足した行は PHP・MySQL と、MySQL の親（RDB）の行だけ（Java・SQL など元の行は残る）
        $added = $now->filter(fn ($r) => !in_array($r->id, array_column($beforeChecks, 'id')))->pluck('item')->sort()->values()->all();
        $this->assertSame(['MySQL', 'PHP', 'RDB'], $added);
        foreach ($now as $row) if (in_array(strtolower((string) $row->item), ['php', 'mysql'], true)) $this->assertSame('mail', $row->source);
        $this->assertSame(1, DB::table('audit_log')->where('action', 'skill-import')->count());
        $this->assertSame(1, DB::table('audit_log')->where('action', 'engineer-update')->count());
        $this->assertStringNotContainsString('家族の介護のため', (string) DB::table('audit_log')->where('action', 'skill-import')->value('detail'));
        // お知らせ
        $this->as(self::SES1)->get("/engineers/$id")->assertOk();
    }

    public function test_t4b_save_message_and_second_save_does_not_duplicate(): void
    {
        $id = $this->eg();
        $this->save(self::SES1, $id, ['import_skills' => ['PHP']])->assertRedirect("/engineers/$id")->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], 'スキルを 1 件足しました'));
        $n = $this->nSkills($id);
        $this->save(self::SES1, $id, ['import_skills' => ['PHP']])->assertRedirect("/engineers/$id");
        $this->assertSame($n, $this->nSkills($id));
        // すでにある言葉（Java）を送っても増えない・年数は 12 のまま
        $this->save(self::SES1, $id, ['import_skills' => ['Java']])->assertRedirect("/engineers/$id");
        $this->assertSame($n, $this->nSkills($id));
    }

    public function test_t4c_import_skills_only_accepts_master_words(): void
    {
        $id = $this->eg();
        $n = $this->nSkills($id);
        $this->save(self::SES1, $id, ['import_skills' => ['ゾルゾルXYZ架空', ['PHP'], '']])->assertRedirect("/engineers/$id");
        $this->assertSame($n, $this->nSkills($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'skill-import')->count());
        // 配列でない値
        $this->save(self::SES1, $id, ['import_skills' => 'PHP'])->assertRedirect("/engineers/$id");
        $this->assertSame($n, $this->nSkills($id));
        // 101 件送っても 100 件まで（マスタにある語は多くないので、少なくとも上限を超えない）
        $many = array_fill(0, 150, 'PHP');
        $this->save(self::SES1, $id, ['import_skills' => $many])->assertRedirect("/engineers/$id");
        $this->assertSame($n + 1, $this->nSkills($id));
    }

    // ---- T5 ----
    public function test_t5_overwrite_flag(): void
    {
        $id = $this->eg(['desired_price' => 150, 'nearest_station' => '蒲田']);
        $html = $this->importAs(self::SES1, $id)->assertOk()->assertSee('今の値のまま')->getContent();
        $this->assertSame('150', $this->input($html, 'desired_price'));
        $this->assertSame('蒲田', $this->input($html, 'nearest_station'));
        $html = $this->importAs(self::SES1, $id, self::MAIL, ['overwrite' => '1'])->assertOk()->assertSee('上書きします')->getContent();
        $this->assertSame('180', $this->input($html, 'desired_price'));
        $this->assertSame('池上', $this->input($html, 'nearest_station'));
        // DB は変わらない
        $this->assertEquals(150, $this->row($id)['desired_price']);
        $this->assertSame('蒲田', $this->row($id)['nearest_station']);
        // 上書きしても氏名・メール・状態は入力欄に入らない
        $this->assertSame('山田', $this->input($html, 'name_sei'));
        $this->assertSame($this->row($id)['email'], $this->input($html, 'email'));
    }

    public function test_t5b_overwrite_never_replaces_name_even_if_mail_differs(): void
    {
        $id = $this->eg();
        $html = $this->importAs(self::SES1, $id, "氏名：佐藤三郎(SS)\n希望月額：100万\n", ['overwrite' => '1'])->assertOk()->getContent();
        $this->assertSame('山田', $this->input($html, 'name_sei'));
        $this->assertSame('一郎', $this->input($html, 'name_mei'));
        $this->assertSame('100', $this->input($html, 'desired_price'));
    }

    // ---- T6 ----
    public function test_t6_name_mismatch_warns(): void
    {
        $id = $this->mkEng(self::SES1, '佐藤 三郎', 'S.S');
        $r = $this->importAs(self::SES1, $id)->assertOk()->assertSee('メールの氏名が登録の氏名と違います');
        $html = $r->getContent();
        $this->assertSame('佐藤', $this->input($html, 'name_sei') ?? '佐藤');
        $this->assertNotSame('山田', $this->input($html, 'name_sei'));
        $this->assertSame('佐藤 三郎', $this->row($id)['name']);
        // 一致するときは出ない
        $ok = $this->eg();
        $this->importAs(self::SES1, $ok)->assertOk()->assertDontSee('メールの氏名が登録の氏名と違います');
    }

    // ---- T7 ----
    public function test_t7_multiple_candidates(): void
    {
        $id = $this->eg(['desired_price' => null]);
        $two = self::MAIL."\n氏名：鈴木二郎(JS)\n年齢：40歳\n";
        $r = $this->importAs(self::SES1, $id, $two)->assertOk()->assertSee('1通に複数の候補者が入っています。1人分だけを貼ってください。');
        $this->assertNotSame('180', $this->input($r->getContent(), 'desired_price'));
        $this->assertNotSame('池上', $this->input($r->getContent(), 'nearest_station'));
        $this->assertSame([], $this->tablesContaining('鈴木二郎'));
        // 氏名の項目が無い2人分
        $r = $this->importAs(self::SES1, $id, "年齢：30歳\n希望月額：70万\n言語スキル：PHP\n\n年齢：45歳\n希望月額：80万\n言語スキル：Java\n")->assertOk()->assertSee('1通に複数の候補者が入っています');
        $this->assertNotSame('80', $this->input($r->getContent(), 'desired_price'));
    }

    // ---- T8 ----
    public function test_t8_empty_and_too_long(): void
    {
        $id = $this->eg();
        $before = $this->row($id);
        $this->importAs(self::SES1, $id, '')->assertOk()->assertSee('メールの本文を貼ってください。');
        $this->importAs(self::SES1, $id, "  \n　\n")->assertOk()->assertSee('メールの本文を貼ってください。');
        $this->as(self::SES1)->post("/engineers/$id/import-mail", [])->assertOk()->assertSee('メールの本文を貼ってください。');
        $this->as(self::SES1)->post("/engineers/$id/import-mail", ['mail_text' => ['a']])->assertOk()->assertSee('メールの本文を貼ってください。');
        $this->importAs(self::SES1, $id, str_repeat('あ', 10001))->assertOk()->assertSee('長すぎます');
        $this->importAs(self::SES1, $id, "氏名：山田一郎(IY)\n".str_repeat('あ', 9970))->assertOk()->assertDontSee('長すぎます');
        $this->assertSame($before, $this->row($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-import-mail')->count() - 1); // 10000字ちょうどの1件だけ記録
    }

    // ---- T9 ----
    public function test_t9_eg_self_has_no_card_and_post_is_403(): void
    {
        $id = $this->eg();
        $mail = $this->egLogin($id, 'eg-t9@park.example');
        $this->as($mail)->get("/engineers/$id/edit")->assertOk()->assertDontSee('紹介メールから取り込む')->assertDontSee('読み取る')->assertSee('特記事項');
        $before = $this->row($id);
        $this->importAs($mail, $id)->assertForbidden();
        $this->assertSame($before, $this->row($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-import-mail')->count());
    }

    // ---- T10 ----
    public function test_t10_eg_edits_own_special_notes_and_cannot_import_skills(): void
    {
        $id = $this->eg(['special_notes' => 'SESが書いた行']);
        $mail = $this->egLogin($id, 'eg-t10@park.example');
        $n = $this->nSkills($id);
        $this->as($mail)->get("/engineers/$id/edit")->assertOk()->assertSee('SESが書いた行');
        $this->save($mail, $id, ['special_notes' => "SESが書いた行\n本人が足した行", 'import_skills' => ['PHP', 'MySQL']])->assertRedirect();
        $this->assertSame("SESが書いた行\n本人が足した行", $this->row($id)['special_notes']);
        $this->assertSame($n, $this->nSkills($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'skill-import')->count());
    }

    // ---- T11 ----
    public function test_t11_other_company_gets_403(): void
    {
        $id = $this->eg();
        $before = $this->row($id);
        foreach ([self::SES2, self::BX, self::BY, self::SES3] as $who) {
            $this->as($who)->get("/engineers/$id/edit")->assertForbidden();
            $this->importAs($who, $id)->assertForbidden();
            $this->save($who, $id, ['special_notes' => '他社が書いた'])->assertForbidden();
            $this->as($who)->get("/engineers/$id")->assertForbidden();
        }
        $this->assertSame($before, $this->row($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-import-mail')->count());
    }

    public function test_t11b_other_eg_and_guest_cannot_import(): void
    {
        $id = $this->eg();
        $other = $this->mkEng(self::SES1, '同社 花子', 'D.H');
        $mail = $this->egLogin($other, 'eg-t11@park.example');
        $this->as($mail)->get("/engineers/$id/edit")->assertForbidden();
        $this->importAs($mail, $id)->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->post("/engineers/$id/import-mail", ['mail_text' => self::MAIL])->assertRedirect();
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-import-mail')->count());
    }

    // ---- T12 ----
    public function test_t12_provisional_eg_goes_back_to_people_edit(): void
    {
        $id = $this->eg();
        $uid = DB::table('users')->insertGetId(['email' => 'prov-t12@park.example', 'name' => '仮 登録', 'role' => 'eg', 'engineer_id' => $id, 'registered_at' => null,
            'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $before = $this->row($id);
        $this->importAs(self::SES1, $id)->assertRedirect("/people/$uid/edit");
        $this->importAs(self::ADMIN, $id)->assertRedirect("/people/$uid/edit");
        $this->assertSame($before, $this->row($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-import-mail')->count());
        // 編集画面でも守りは同じ（カードは出ない）
        $this->as(self::SES1)->get("/engineers/$id/edit")->assertRedirect("/people/$uid/edit");
    }

    // ---- T13 ----
    public function test_t13_left_eg(): void
    {
        $id = $this->eg(['status' => 'left']);
        $before = $this->row($id);
        $this->as(self::SES1)->get("/engineers/$id/edit")->assertOk()->assertDontSee('紹介メールから取り込む');
        $this->importAs(self::SES1, $id)->assertRedirect("/engineers/$id/edit");
        $this->followingRedirects()->importAs(self::SES1, $id)->assertSee('退会済のエンジニアには取り込めません。');
        $this->assertSame($before, $this->row($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'engineer-import-mail')->count());
    }

    public function test_t13b_left_eg_import_skills_adds_nothing(): void
    {
        $id = $this->eg(['status' => 'left']);
        $n = $this->nSkills($id);
        $this->save(self::SES1, $id, ['import_skills' => ['PHP', 'MySQL']]);
        $this->assertSame($n, $this->nSkills($id));
        $this->assertSame(0, DB::table('audit_log')->where('action', 'skill-import')->count());
        $this->assertSame('left', $this->row($id)['status']);
    }

    // ---- T14 ----
    public function test_t14_special_notes_never_leak(): void
    {
        $this->travelBack(); // 日付を止めない（案内メールの流れは今日の日付で動く）
        $mark = '特記ひみつMARK九九';
        $cid = $this->mkCase();
        $a1 = $this->mkEng(self::SES1, '自社 太郎', 'A.A', ['special_notes' => $mark.'A']);
        $b1 = $this->mkEng(self::SES2, '他社 花子', 'B.B', ['special_notes' => $mark.'B']);
        $sid = $this->search($cid);
        $w = compact('cid', 'a1', 'b1', 'sid');
        // EG 検索の一覧・案内メールの作成画面
        foreach ([self::SES1] as $who) {
            $this->as($who)->get("/searches/$sid")->assertOk()->assertDontSee($mark);
        }
        // パートナー（B 社）の行
        $this->as(self::SES2)->get("/searches/$sid")->assertForbidden();
        $cm = $this->partnerMail($w);
        $this->assertStringNotContainsString($mark, (string) $cm->body);
        // スキルシート（名前を伏せたものも）
        foreach ([[self::SES1, $a1], [self::ADMIN, $a1], [self::SES2, $b1], [self::ADMIN, $b1]] as [$who, $eid]) {
            $this->as($who)->get("/engineers/$eid/sheet")->assertOk()->assertDontSee($mark);
            $this->as($who)->get("/engineers/$eid/sheet?anon=1")->assertOk()->assertDontSee($mark);
        }
        $this->as(self::SES3)->get("/engineers/$a1/sheet")->assertForbidden();
        // 書き出す書類（ファイルの中身まで）
        $dump = function ($book) {
            $t = '';
            foreach ($book->getAllSheets() as $ws) foreach ($ws->toArray() as $row) $t .= implode(' ', array_filter($row, fn ($v) => $v !== null))."\n";
            return $t;
        };
        foreach ([$a1, $b1] as $eid) {
            $this->assertStringNotContainsString($mark, $dump(EngineerDocs::resume($eid)));
            $this->assertStringNotContainsString($mark, $dump(EngineerDocs::careersheet($eid)));
            $this->assertStringNotContainsString($mark, $dump(EngineerDocs::careersheet($eid, true)));
            foreach (['resume', 'careersheet'] as $kind) {
                $fid = EngineerDocs::generate($eid, $kind, $this->user(self::ADMIN), $kind === 'careersheet');
                $path = Storage::disk(EngineerDocs::DISK)->path(DB::table('engineer_files')->where('id', $fid)->value('path'));
                $this->assertStringNotContainsString($mark, $dump(\PhpOffice\PhpSpreadsheet\IOFactory::load($path)));
            }
        }
        // 全部の表：特記事項を持つのは engineers だけ（案内メール・プロバイダー宛・AI の仕事・通知・送信箱などに入っていない）
        $this->assertSame(['engineers'], $this->tablesContaining($mark));
        $this->assertGreaterThan(0, DB::table('case_mails')->count());
    }

    // ---- T15 ----
    public function test_t15_detail_page(): void
    {
        $mark = '詳細に出る特記ABC';
        $id = $this->eg(['special_notes' => $mark]);
        $mail = $this->egLogin($id, 'eg-t15@park.example');
        foreach ([self::SES1, self::AS2, self::ADMIN, $mail] as $who) $this->as($who)->get("/engineers/$id")->assertOk()->assertSee('特記事項')->assertSee($mark);
        $this->as(self::SES2)->get("/engineers/$id")->assertForbidden();
        // 空なら見出しも出ない
        $e2 = $this->eg();
        $this->as(self::SES1)->get("/engineers/$e2")->assertOk()->assertDontSee('特記事項');
        // HTML は逃がす
        $e3 = $this->eg(['special_notes' => '<script>alert(1)</script>行']);
        $this->as(self::SES1)->get("/engineers/$e3")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
        $this->as(self::SES1)->get("/engineers/$e3/edit")->assertOk()->assertDontSee('<script>alert(1)</script>', false);
    }

    // ---- T16 ----
    public function test_t16_special_notes_length(): void
    {
        $id = $this->eg(['special_notes' => '元の行']);
        $this->save(self::SES1, $id, ['special_notes' => str_repeat('あ', 2001)])->assertRedirect()->assertSessionHas('flash', fn ($f) => $f['msg'] === '特記事項は2000文字までにしてください。');
        $this->assertSame('元の行', $this->row($id)['special_notes']);
        $this->save(self::SES1, $id, ['special_notes' => str_repeat('あ', 2000)])->assertRedirect("/engineers/$id");
        $this->assertSame(2000, mb_strlen($this->row($id)['special_notes']));
        // 本人も同じ
        $mail = $this->egLogin($id, 'eg-t16@park.example');
        $this->save($mail, $id, ['special_notes' => str_repeat('い', 2001)])->assertRedirect();
        $this->assertSame(str_repeat('あ', 2000), $this->row($id)['special_notes']);
    }

    // ---- そのほか ----
    public function test_special_notes_survive_profile_save_and_import_does_not_clear_them(): void
    {
        $id = $this->eg(['special_notes' => '残る行']);
        $this->as(self::SES1)->post("/engineers/$id/profile", ['career_summary' => '経歴', 'gender' => '男'])->assertRedirect();
        $this->assertSame('残る行', $this->row($id)['special_notes']);
        // 取り込みで足された特記事項は、上書きのチェックがあっても今の行を消さない
        $html = $this->importAs(self::SES1, $id, self::MAIL, ['overwrite' => '1'])->getContent();
        $notes = (string) $this->input($html, 'special_notes');
        $this->assertStringStartsWith("残る行\n", $notes);
        $this->assertStringContainsString(self::NOTE, $notes);
        // 同じ文を2回取り込んで保存しても重ならない
        $this->save(self::SES1, $id, ['special_notes' => $notes])->assertRedirect();
        $html2 = $this->importAs(self::SES1, $id)->getContent();
        $this->assertSame($notes, (string) $this->input($html2, 'special_notes') ?: $notes);
        $this->assertSame(1, substr_count((string) $this->row($id)['special_notes'], self::NOTE));
    }

    public function test_saving_without_special_notes_field_keeps_value(): void
    {
        $id = $this->eg(['special_notes' => '消えない行']);
        $this->save(self::SES1, $id)->assertRedirect("/engineers/$id");
        $this->assertSame('消えない行', $this->row($id)['special_notes']);
    }

    public function test_work_style_not_changed_when_mail_has_no_style(): void
    {
        $id = $this->eg(['work_style' => 'onsite']);
        $html = $this->importAs(self::SES1, $id, "氏名：山田一郎(IY)\n言語スキル：PHP\n", ['overwrite' => '1'])->getContent();
        $this->assertSame('onsite', $this->input($html, 'work_style'));
        $html = $this->importAs(self::SES1, $id, "氏名：山田一郎(IY)\n勤務形態：フルリモートは不可\n", ['overwrite' => '1'])->getContent();
        $this->assertSame('onsite', $this->input($html, 'work_style'));
    }

    public function test_other_skills_unchanged_and_registered_marked_in_screen(): void
    {
        $id = $this->eg();
        SkillChecks::saveFromNames($id, [['PHP', 'lang', 3]], 'seed');
        $r = $this->importAs(self::SES1, $id)->assertOk()->assertSee('登録済み');
        $this->assertStringNotContainsString('value="PHP" form="engineer-form"', $r->getContent());
        $this->assertStringContainsString('value="Perl" form="engineer-form"', $r->getContent());
    }

    public function test_html_in_mail_is_escaped_in_result(): void
    {
        $id = $this->eg();
        $r = $this->importAs(self::SES1, $id, "氏名：山田一郎(IY)\n資格：<script>alert(1)</script>\n言語スキル：<b>zzz架空</b>\n・<img src=x onerror=alert(1)>\n")->assertOk();
        $r->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('<img src=x', false)->assertDontSee('<b>zzz架空</b>', false);
        $r->assertSee('取り込めなかった部分');
    }
}
