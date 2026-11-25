<?php

namespace Tests\Feature;

use App\Services\EngineerMailImport;
use App\Services\EngineerMailParser as P;
use App\Services\SkillChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.18.0 候補者紹介メールの読み取り（PLAN.md の P1〜P14）と埋め方（I1〜I6）、レビューで見つかったケース。氏名・アドレスはすべて架空
class EngineerMailParserTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(\Illuminate\Support\Carbon::parse('2026-11-25 10:00:00'));
    }

    private function base(): string
    {
        return "（候補者）\n氏名：山田一郎(IY)\n・56歳　男性\n最寄駅：池上線　池上\n所属：弊社プロパー\n"
            ."言語スキル：PHP、Perl、Javascript\n他スキル：MySQL、PostgreSQL、ZendFW\n　PMO、PM、PL可能\n"
            ."英語：ビジネス会話可能\n希望月額：～180万円程度　(固定)\n就業時期：10月～　（11、12月でも可）\n"
            ."人物：明朗、協調性あり\n・家族の介護のため、リモート併用案件を希望します。\n";
    }

    private function parse(string $t): array
    {
        return P::parse($t);
    }

    private function names(array $p): array
    {
        return array_column($p['skills'], 'name');
    }

    // ---- P1 ----
    public function test_p1_base_example(): void
    {
        $p = $this->parse($this->base());
        $f = $p['fields'];
        $this->assertFalse($p['multiple']);
        $this->assertSame('山田一郎', $f['name']);
        $this->assertSame('I.Y', $f['initials']);
        $this->assertSame(1970, $f['birth_year']);
        $this->assertSame('男', $f['gender']);
        $this->assertSame('池上線', $f['nearest_line']);
        $this->assertSame('池上', $f['nearest_station']);
        $this->assertSame(180, $f['desired_price']);
        $this->assertArrayNotHasKey('min_price', $f);
        $this->assertSame('2026-11-25', $f['available_from']);
        $this->assertSame('英語：ビジネス会話可能', $f['english_level']);
        $this->assertSame('hybrid', $f['work_style']);
        $this->assertSame('明朗、協調性あり', $f['appeal']);
        $this->assertContains('家族の介護のため、リモート併用案件を希望します。', $p['notes']);
        $this->assertSame('弊社プロパー', $p['ref']['affiliation']);
        // 所属は取り込み先を持たない
        $this->assertArrayNotHasKey('ses_id', $f);
    }

    // ---- P2 ----
    public function test_p2_skills(): void
    {
        $p = $this->parse($this->base());
        $n = $this->names($p);
        foreach (['PHP', 'Perl', 'JavaScript', 'MySQL', 'PostgreSQL'] as $s) $this->assertContains($s, $n, $s);
        // PMO・PM・PL はマスタにあれば入り、無ければ unknown_words に出る（どちらかには必ず入る）
        foreach (['PMO', 'PM', 'PL可能'] as $w) {
            $inSkills = in_array($w, array_column($p['skills'], 'word'), true);
            $this->assertTrue($inSkills || in_array($w, $p['unknown_words'], true), $w);
        }
        // マスタに無い語は技術マスタに足されない
        $before = DB::table('skill_terms')->count();
        $this->parse($this->base());
        $this->assertSame($before, DB::table('skill_terms')->count());
        // 同じ正式名は1回だけ
        $this->assertSame(count($n), count(array_unique($n)));
    }

    public function test_p2b_unknown_word_goes_to_unknown_words(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n言語スキル：PHP、ゾルゾルXYZ架空\n");
        $this->assertContains('PHP', $this->names($p));
        $this->assertContains('ゾルゾルXYZ架空', $p['unknown_words']);
        $this->assertNotContains('ゾルゾルXYZ架空', $this->names($p));
    }

    // ---- P3 ----
    public function test_p3_notation_variants(): void
    {
        $expect = function (string $t) {
            $p = $this->parse($t);
            $this->assertSame('山田一郎', $p['fields']['name']);
            $this->assertSame('池上', $p['fields']['nearest_station']);
            $this->assertSame(180, $p['fields']['desired_price']);
            $this->assertContains('PHP', $this->names($p));
        };
        $expect("氏名：山田一郎(IY)\n最寄駅：池上線 池上\n希望月額：180万\n言語スキル：PHP\n");
        $expect("氏名:山田一郎(IY)\n最寄駅:池上線 池上\n希望月額:180万\n言語スキル:PHP\n");
        $expect("■氏名：山田一郎(IY)\n■最寄駅：池上線 池上\n■希望月額：180万\n■言語スキル：PHP\n");
        $expect("◆氏名：山田一郎(IY)\n◆最寄駅：池上線 池上\n◆希望月額：180万\n◆言語スキル：PHP\n");
        $expect("・氏名：山田一郎(IY)\n・最寄駅：池上線 池上\n・希望月額：180万\n・言語スキル：PHP\n");
        $expect("●氏名：山田一郎(IY)\n●最寄駅：池上線 池上\n●希望月額：180万\n●言語スキル：PHP\n");
        $expect("【氏名】：山田一郎(IY)\n【最寄駅】：池上線 池上\n【希望月額】：180万\n【言語スキル】：PHP\n");
    }

    public function test_p3b_bracket_label_with_colon_and_heading_only(): void
    {
        $p = $this->parse("【氏名】：山田一郎(IY)\n【言語スキル】\nPHP、Perl\n");
        $this->assertSame('山田一郎', $p['fields']['name']);
        $this->assertContains('PHP', $this->names($p));
        $this->assertContains('Perl', $this->names($p));
    }

    // ---- P4 ----
    public function test_p4_fullwidth_alnum(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n言語スキル：ＰＨＰ、ＭｙＳＱＬ\n");
        $this->assertContains('PHP', $this->names($p));
        $this->assertContains('MySQL', $this->names($p));
    }

    // ---- P5 ----
    public function test_p5_fullwidth_space_in_label(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n最寄　駅：池上線　池上\n");
        $this->assertSame('池上', $p['fields']['nearest_station']);
        $this->assertSame('池上線', $p['fields']['nearest_line']);
    }

    // ---- P6 ----
    public function test_p6_career_and_achievements(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n経験：\n　金融系の基幹システム開発\n　設計から運用まで\n実績：\n　要件定義を主導\n");
        $s = $p['fields']['career_summary'];
        $this->assertStringContainsString("金融系の基幹システム開発\n設計から運用まで", $s);
        $this->assertStringContainsString("【実績】\n要件定義を主導", $s);
        $this->assertLessThan(strpos($s, '【実績】'), strpos($s, '設計から運用まで'));
    }

    // ---- P7 ----
    public function test_p7_years_not_confused_with_career(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n経験年数：12年\n");
        $this->assertSame(12, $p['fields']['years_exp']);
        $this->assertArrayNotHasKey('career_summary', $p['fields']);
        $p = $this->parse("氏名：山田一郎(IY)\n経験：金融系の開発\n");
        $this->assertArrayNotHasKey('years_exp', $p['fields']);
        $this->assertSame('金融系の開発', $p['fields']['career_summary']);
        $p = $this->parse("氏名：山田一郎(IY)\n経験年数：10年以上\n");
        $this->assertSame(10, $p['fields']['years_exp']);
        $p = $this->parse("氏名：山田一郎(IY)\n経験年数：7.3年\n");
        $this->assertSame(7.5, $p['fields']['years_exp']);
    }

    public function test_p7b_it_experience_from_year_is_not_years_exp(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\nIT経験：2005年～\n");
        $this->assertArrayNotHasKey('years_exp', $p['fields']);
        $this->assertNotEmpty($p['unread']);
        $p = $this->parse("氏名：山田一郎(IY)\nIT経験年数：2005年\n");
        $this->assertArrayNotHasKey('years_exp', $p['fields']);
    }

    // ---- P8 ----
    public function test_p8_price(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n希望月額：70～80万\n");
        $this->assertSame(80, $p['fields']['desired_price']);
        $this->assertSame(70, $p['fields']['min_price']);
        $p = $this->parse("氏名：山田一郎(IY)\n希望月額：スキル見合い\n");
        $this->assertArrayNotHasKey('desired_price', $p['fields']);
        $this->assertArrayNotHasKey('min_price', $p['fields']);
        $this->assertNotEmpty($p['unread']);
        $p = $this->parse("氏名：山田一郎(IY)\n希望月額：180万以上\n");
        $this->assertSame(180, $p['fields']['desired_price']);
        $this->assertArrayNotHasKey('min_price', $p['fields']);
    }

    public function test_p8b_price_range_with_man_on_both_sides(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n希望月額：70万～80万円\n");
        $this->assertSame(80, $p['fields']['desired_price']);
        $this->assertSame(70, $p['fields']['min_price']);
        $p = $this->parse("氏名：山田一郎(IY)\n希望月額：７０万円～８０万円\n");
        $this->assertSame(80, $p['fields']['desired_price']);
        $this->assertSame(70, $p['fields']['min_price']);
    }

    // ---- P9 ----
    public function test_p9_start_dates(): void
    {
        $d = fn (string $v) => $this->parse("氏名：山田一郎(IY)\n就業時期：{$v}\n");
        $this->assertSame('2026-11-25', $d('即日')['fields']['available_from']);
        $this->assertSame('2027-04-01', $d('4月～')['fields']['available_from']);
        $this->assertSame('2026-12-01', $d('12月～')['fields']['available_from']);
        $this->assertSame('2027-01-01', $d('2027年1月')['fields']['available_from']);
        $this->assertSame('2027-01-01', $d('2027/1')['fields']['available_from']);
        $this->assertArrayNotHasKey('available_from', $d('未定')['fields']);
        $this->assertNotEmpty($d('未定')['unread']);
        // 今月・過ぎた2か月以内は今日
        $this->assertSame('2026-11-25', $d('11月～')['fields']['available_from']);
        $this->assertSame('2026-11-25', $d('9月～')['fields']['available_from']);
        // 3か月以上前は来年
        $this->assertSame('2027-08-01', $d('8月～')['fields']['available_from']);
        // かっこの中は読まない
        $this->assertSame('2026-12-01', $d('12月～（11月でも可）')['fields']['available_from']);
    }

    // ---- P10 ----
    public function test_p10_work_style(): void
    {
        $w = fn (string $t) => $this->parse("氏名：山田一郎(IY)\n{$t}\n")['fields'];
        $this->assertSame('remote', $w('勤務形態：フルリモート希望')['work_style']);
        $this->assertArrayNotHasKey('work_style', $w('勤務形態：フルリモート不可'));
        $this->assertArrayNotHasKey('work_style', $w('勤務形態：フルリモートは不可'));
        $this->assertArrayNotHasKey('work_style', $w('勤務形態：フルリモートNG'));
        $this->assertArrayNotHasKey('work_style', $w('人物：明朗'));
        $this->assertSame('onsite', $w('勤務形態：常駐')['work_style']);
        $this->assertSame('hybrid', $w('勤務形態：一部リモート')['work_style']);
        $this->assertSame('remote', $w('・フルリモートを希望します。')['work_style']);
    }

    // ---- P11 ----
    public function test_p11_sparse_mail(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n言語スキル：PHP、Perl\n");
        $this->assertEqualsCanonicalizing(['name', 'initials'], array_keys($p['fields']));
        $this->assertSame(['PHP', 'Perl'], array_slice($this->names($p), 0, 2));
        $this->assertFalse($p['multiple']);
        $this->assertSame([], $p['notes']);
        // 完全に空に近い文でも例外にならない
        $p = $this->parse("こんにちは");
        $this->assertSame([], $p['fields']);
        $this->assertNotEmpty($p['unread']);
    }

    // ---- P12 ----
    public function test_p12_multiple(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n年齢：30歳\n氏名：鈴木二郎(JS)\n年齢：40歳\n");
        $this->assertTrue($p['multiple']);
        $this->assertSame([], $p['fields']);
        $p = $this->parse("【候補者1】\n氏名：山田一郎(IY)\n【候補者2】\n言語スキル：PHP\n");
        $this->assertTrue($p['multiple']);
        $p = $this->parse("1人目\n年齢：30歳\n2人目\n年齢：40歳\n");
        $this->assertTrue($p['multiple']);
        $this->assertSame([], $p['skills']);
        $this->assertSame([], $p['notes']);
    }

    public function test_p12b_multiple_without_name_items(): void
    {
        // 氏名の項目が無い2人分：年齢・希望月額が2回出る
        $p = $this->parse("年齢：30歳\n最寄駅：池上線 池上\n希望月額：70万\n言語スキル：PHP\n\n年齢：45歳\n最寄駅：京浜東北線 蒲田\n希望月額：80万\n言語スキル：Java\n");
        $this->assertTrue($p['multiple']);
        $this->assertSame([], $p['fields']);
        $this->assertSame([], $p['skills']);
    }

    // ---- P13 ----
    public function test_p13_unknown_items_and_greeting_are_unread(): void
    {
        $p = $this->parse("お世話になっております。\n候補者をご紹介します。\n氏名：山田一郎(IY)\n資格：基本情報技術者\n国籍：日本\n");
        $this->assertSame('山田一郎', $p['fields']['name']);
        $joined = implode("\n", $p['unread']);
        $this->assertStringContainsString('基本情報技術者', $joined);
        $this->assertStringContainsString('国籍：日本', $joined);
        $this->assertStringContainsString('お世話になっております', $joined);
        $this->assertSame([], $p['notes']);
    }

    // ---- P14 ----
    public function test_p14_station_with_line_in_parentheses(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n最寄駅：新宿駅（JR山手線）徒歩10分\n");
        $this->assertSame('JR山手線', $p['fields']['nearest_line']);
        $this->assertSame('新宿', $p['fields']['nearest_station']);
        $this->assertSame(10, $p['fields']['walk_minutes']);
        $p = $this->parse("氏名：山田一郎(IY)\n最寄駅：池上\n");
        $this->assertSame('池上', $p['fields']['nearest_station']);
        $this->assertArrayNotHasKey('nearest_line', $p['fields']);
    }

    // ---- レビューで見つかったケース ----
    public function test_age_in_decade_is_not_age(): void
    {
        foreach (['50代', '50代前半', '五十代'] as $v) {
            $p = $this->parse("氏名：山田一郎(IY)\n年齢：{$v}\n");
            $this->assertArrayNotHasKey('birth_year', $p['fields'], $v);
        }
        $p = $this->parse("氏名：山田一郎(IY)\n・50代　男性\n");
        $this->assertArrayNotHasKey('birth_year', $p['fields']);
        $this->assertSame('男', $p['fields']['gender']);
        $p = $this->parse("氏名：山田一郎(IY)\n年齢：56歳\n");
        $this->assertSame(1970, $p['fields']['birth_year']);
        $p = $this->parse("氏名：山田一郎(IY)\n年齢：５６\n");
        $this->assertSame(1970, $p['fields']['birth_year']);
    }

    public function test_signature_and_after_closing_are_not_notes_or_appeal(): void
    {
        $t = "氏名：山田一郎(IY)\n人物：明朗\n・家族の介護のため、リモート併用案件を希望します。\n"
            ."以上、よろしくお願いいたします。\n株式会社テスト商事 営業部\n・TEL：03-0000-0000\n・お問い合わせは担当までご連絡ください架空\n"
            ."担当：架空 営業\nmail: sales@park.example\n";
        $p = $this->parse($t);
        $this->assertSame('明朗', $p['fields']['appeal']);
        $this->assertSame(['家族の介護のため、リモート併用案件を希望します。'], $p['notes']);
        $all = json_encode($p, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('お問い合わせは担当', json_encode($p['notes'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('株式会社テスト商事', json_encode($p['fields'], JSON_UNESCAPED_UNICODE));
        $this->assertStringNotContainsString('03-0000-0000', json_encode($p['fields'] + ['n' => $p['notes']], JSON_UNESCAPED_UNICODE));
        $this->assertNotSame('', $all);
    }

    public function test_signature_after_a_continuation_block_not_in_appeal(): void
    {
        // 人物の続きの行に、署名が入り込まない
        $p = $this->parse("氏名：山田一郎(IY)\n人物：明朗、協調性あり\n\n以上\n株式会社テスト商事\n");
        $this->assertSame('明朗、協調性あり', $p['fields']['appeal']);
        $p = $this->parse("氏名：山田一郎(IY)\n人物：明朗\n以上\n株式会社テスト商事\n");
        $this->assertSame('明朗', $p['fields']['appeal']);
    }

    public function test_note_items_go_to_notes(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n備考：通院のため水曜は午後休\n");
        $this->assertContains('通院のため水曜は午後休', $p['notes']);
    }

    public function test_crlf_text_is_read_the_same(): void
    {
        $p = $this->parse(str_replace("\n", "\r\n", $this->base()));
        $this->assertSame('山田一郎', $p['fields']['name']);
        $this->assertSame(180, $p['fields']['desired_price']);
    }

    public function test_roles_join_and_cut(): void
    {
        $p = $this->parse("氏名：山田一郎(IY)\n希望業務：\n　PM、PMO\n得意分野：Web 開発\n");
        $this->assertSame('Web 開発 ／ PM、PMO', $p['fields']['desired_roles']);
        $p = $this->parse("氏名：山田一郎(IY)\n得意分野：".str_repeat('あ', 400)."\n");
        $this->assertSame(255, mb_strlen($p['fields']['desired_roles']));
    }

    public function test_name_only_initials_and_title_line(): void
    {
        $p = $this->parse("氏名：T.N\n言語スキル：PHP\n");
        $this->assertSame('T.N', $p['fields']['initials']);
        $this->assertArrayNotHasKey('name', $p['fields']);
        $p = $this->parse("【候補者】\n氏名：山田一郎(IY)\n");
        $this->assertFalse($p['multiple']);
    }

    // =================== 埋め方 ===================

    private function eng(array $over = []): object
    {
        $id = \App\Services\Workflow::createEngineer((int) DB::table('ses_companies')->orderBy('id')->value('id'),
            ['name' => '山田 一郎', 'kana' => 'やまだ いちろう'], DB::table('users')->where('role', 'admin')->first(), false);
        DB::table('engineers')->where('id', $id)->update($over + ['initials' => 'I.Y', 'status' => 'available']);
        return DB::table('engineers')->find($id);
    }

    private function plan(object $e, ?string $mail = null, bool $ow = false): array
    {
        return EngineerMailImport::plan($e, P::parse($mail ?? $this->base()), $ow);
    }

    public function test_i1_empty_engineer_gets_everything(): void
    {
        $e = $this->eng(['birth_year' => null, 'nearest_station' => null, 'desired_price' => null, 'work_style' => 'any', 'available_from' => null]);
        $pl = $this->plan($e);
        foreach (['birth_year' => 1970, 'gender' => '男', 'nearest_line' => '池上線', 'nearest_station' => '池上', 'desired_price' => 180, 'work_style' => 'hybrid', 'available_from' => '2026-11-25', 'appeal' => '明朗、協調性あり'] as $c => $v) {
            $this->assertSame($v, $pl['fill'][$c], $c);
        }
        $this->assertStringContainsString('家族の介護のため', $pl['fill']['special_notes']);
        foreach ($pl['rows'] as $r) $this->assertTrue(str_contains($r['status'], '入れました') || str_contains($r['status'], '足しました'), $r['label'].'：'.$r['status']);
        $this->assertSame([], $pl['warn']);
    }

    public function test_i2_overwrite_flag(): void
    {
        $e = $this->eng(['desired_price' => 150]);
        $pl = $this->plan($e, null, false);
        $this->assertArrayNotHasKey('desired_price', $pl['fill']);
        $row = collect($pl['rows'])->firstWhere('label', '希望単価（万円／月）');
        $this->assertStringContainsString('今の値のまま', $row['status']);
        $pl = $this->plan($e, null, true);
        $this->assertSame(180, $pl['fill']['desired_price']);
    }

    public function test_i2b_same_value(): void
    {
        $e = $this->eng(['desired_price' => 180]);
        $row = collect($this->plan($e)['rows'])->firstWhere('label', '希望単価（万円／月）');
        $this->assertSame('同じ値です', $row['status']);
    }

    public function test_i3_never_columns_even_with_overwrite(): void
    {
        $e = $this->eng(['email' => 'eg-keep@park.example']);
        $mail = $this->base()."氏名：山田一郎(IY)\n";
        $pl = EngineerMailImport::plan($e, ['fields' => ['name' => '別人', 'initials' => 'X.X', 'email' => 'x@park.example', 'ses_id' => 99, 'status' => 'left', 'kana' => 'べつ',
            'name_sei' => 'べ', 'desired_price' => 180], 'raw' => [], 'skills' => [], 'unknown_words' => [], 'notes' => [], 'unread' => [], 'ref' => [], 'multiple' => false], true);
        foreach (['name', 'name_sei', 'name_mei', 'kana', 'kana_sei', 'kana_mei', 'email', 'ses_id', 'status', 'initials'] as $c) $this->assertArrayNotHasKey($c, $pl['fill'], $c);
        $pl = $this->plan($e, $this->base(), true);
        foreach (['name', 'email', 'ses_id', 'status', 'initials'] as $c) $this->assertArrayNotHasKey($c, $pl['fill'], $c);
        $this->assertNotSame('', $mail);
    }

    public function test_i4_birth_date_blocks_birth_year(): void
    {
        $e = $this->eng(['birth_date' => '1980-05-05', 'birth_year' => 1980]);
        $pl = $this->plan($e, null, true);
        $this->assertArrayNotHasKey('birth_year', $pl['fill']);
        $row = collect($pl['rows'])->firstWhere('label', '生まれた年');
        $this->assertStringContainsString('生年月日が登録済み', $row['status']);
    }

    public function test_i5_special_notes_append_without_duplicates(): void
    {
        $e = $this->eng(['special_notes' => '家族の介護のため、リモート併用案件を希望します。']);
        $pl = $this->plan($e);
        $this->assertArrayNotHasKey('special_notes', $pl['fill']);
        $e = $this->eng(['special_notes' => "別の事情です\n家族の介護のため、リモート併用案件を希望します。"]);
        $this->assertArrayNotHasKey('special_notes', $this->plan($e)['fill']);
        $e = $this->eng(['special_notes' => '別の事情です']);
        $pl = $this->plan($e, null, true); // 上書きでも消さない
        $this->assertSame("別の事情です\n家族の介護のため、リモート併用案件を希望します。", $pl['fill']['special_notes']);
        // 空白の違いだけの同じ行は足さない
        $e = $this->eng(['special_notes' => '家族の介護のため、 リモート併用案件を希望します。']);
        $this->assertArrayNotHasKey('special_notes', $this->plan($e)['fill']);
    }

    public function test_i6_registered_skill_is_marked(): void
    {
        $e = $this->eng();
        SkillChecks::saveFromNames((int) $e->id, [['PHP', 'lang', 3]], 'seed');
        $skills = collect($this->plan($e)['skills']);
        $this->assertTrue($skills->firstWhere('name', 'PHP')['registered']);
        $this->assertFalse($skills->firstWhere('name', 'Perl')['registered']);
    }

    public function test_work_style_any_counts_as_empty_but_set_value_is_kept(): void
    {
        $e = $this->eng(['work_style' => 'onsite']);
        $pl = $this->plan($e);
        $this->assertArrayNotHasKey('work_style', $pl['fill']);
        $this->assertSame('hybrid', $this->plan($e, null, true)['fill']['work_style']);
    }

    public function test_name_mismatch_warns_and_multiple_fills_nothing(): void
    {
        $e = $this->eng();
        $pl = $this->plan($e, "氏名：佐藤三郎(SS)\n希望月額：100万\n");
        $this->assertStringContainsString('メールの氏名が登録の氏名と違います', implode('', $pl['warn']));
        $this->assertSame(100, $pl['fill']['desired_price']); // 止めない
        $pl = $this->plan($e, "氏名：山田一郎(IY)\n年齢：30歳\n氏名：鈴木二郎(JS)\n年齢：40歳\n");
        $this->assertSame([], $pl['fill']);
        $this->assertStringContainsString('1通に複数の候補者', implode('', $pl['warn']));
        // 同じ氏名は、空白・かっこの違いがあっても警告しない
        $pl = $this->plan($e, "氏名：山田　一郎(IY)\n");
        $this->assertSame([], $pl['warn']);
    }

    public function test_initials_only_mismatch_warns_reverse_order_ok(): void
    {
        $e = $this->eng(['initials' => 'T.N']);
        $this->assertSame([], $this->plan($e, "氏名：N.T\n言語スキル：PHP\n")['warn']);
        $this->assertNotSame([], $this->plan($e, "氏名：A.B\n言語スキル：PHP\n")['warn']);
    }
}
