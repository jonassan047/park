<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\Qa;
use App\Services\SkillChecks;
use App\Services\SkillTable;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.4.0 スキル登録画面（チェック式）。PLAN.md の AC-01〜AC-22 と D10。立場は見本の EG（eg2）、管理者、その SES、他社の SES、他の EG。
// 項目の正本は resources/data/skill_form.json。道筋は 'lang/PHP'（節/名前）・'lang/PHP/Laravel'（節/親/名前）で書く（V030Helpers::checkForm）。
class SkillFormTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const EG = 'eg2@park.example';

    private int $eid;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        $this->eid = $this->engId(self::EG);
        $this->cleanEg($this->eid);
    }

    private function save(array $form, ?string $email = null)
    {
        $eid = $email ? $this->engId($email) : $this->eid;
        return $this->as($email ?? self::EG)->post("/engineers/$eid/skills", $form);
    }

    private function page(string $query = '', ?string $email = null)
    {
        return $this->as($email ?? self::EG)->get("/engineers/{$this->eid}/skills".$query);
    }

    private function assertBad($response, ?string $msg = null): void
    {
        $response->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && ($msg === null ? $f['msg'] !== '' : $f['msg'] === $msg));
    }

    private function sesEmailOf(int $eid): string
    {
        $sesId = (int) DB::table('engineers')->where('id', $eid)->value('ses_id');
        return (string) DB::table('users')->where('ses_id', $sesId)->where('role', 'ses')->value('email');
    }

    // ---------- 画面 ----------

    public function test_ac01_screen_is_built_from_json(): void
    {
        $html = $this->page()->assertOk()->getContent();
        $last = -1;
        foreach (SkillForm::tree() as $sec) {
            $p = strpos($html, $sec['title']);
            $this->assertNotFalse($p, $sec['title']);
            $this->assertGreaterThan($last, $p, '節の順');
            $last = $p;
        }
        // 1つ選ぶ節（PM/PL の設問）はプルダウン（name="one[…]"）なので、チェックの数に入れない
        $single = array_filter(SkillForm::tree(), fn ($s) => $s['single']);
        $singleKeys = array_column($single, 'key');
        $checkable = array_filter(SkillForm::nodes(), fn ($n) => $n['checkable'] && !in_array($n['section'], $singleKeys, true));
        $this->assertSame(count($checkable), substr_count($html, 'name="chk['));
        // 見出しだけのグループ（課題・タスク管理や「そのほか（…）」）にはチェックが無い
        foreach (array_filter(SkillForm::nodes(), fn ($n) => $n['group'] && !$n['checkable']) as $n) {
            $this->assertStringNotContainsString('name="chk['.$n['id'].']"', $html, $n['item']);
            $this->assertStringContainsString($n['item'], $html);
        }
        // 節の題には、チェックが無い
        $this->assertStringNotContainsString('chk[roles]', $html);
    }

    public function test_ac02_year_select_has_five_options(): void
    {
        $html = $this->page()->assertOk()->getContent();
        $id = $this->skillNode('lang/PHP')['id'];
        preg_match('#<select name="yr\['.preg_quote($id, '#').'\]"[^>]*>(.*?)</select>#s', $html, $m);
        $this->assertNotEmpty($m, '年数の select');
        preg_match_all('#<option value="([^"]*)"#', $m[1], $o);
        $this->assertSame(['', '1年未満', '1年以上', '3年以上', '5年以上'], $o[1]);
        $this->assertStringContainsString('yr['.$this->skillNode('roles/メンバー')['id'].']', $html);
        // 年数が false の項目（工程）には、プルダウンが無い
        $this->assertStringNotContainsString('yr[phases-', $html);
    }

    public function test_ac03_other_has_inputs_and_templates(): void
    {
        $this->save($this->checkForm([], ['lang/PHP/その他' => [['Haxe', '3年以上'], ['Foo', '']]]))->assertRedirect();
        $html = $this->page()->assertOk()->getContent();
        $otherId = $this->skillNode('lang/PHP/その他')['id'];
        $this->assertSame(2, substr_count($html, 'name="oth['.$otherId.'][]"'));
        $this->assertSame(2, substr_count($html, 'name="othyr['.$otherId.'][]"'));
        $others = array_filter(SkillForm::nodes(), fn ($n) => $n['other']);
        $this->assertSame(count($others), substr_count($html, 'class="sf-other"'));
        // 保存済みのその他だけ開き、ほかの（年数付きの）その他は hidden
        $withYears = array_filter($others, fn ($n) => $n['years']);
        $this->assertSame(count($withYears) - 1, substr_count($html, 'data-sf-years="1" hidden>'));
        $this->assertStringContainsString('id="sf-tpl-y"', $html);
        $this->assertStringContainsString('id="sf-tpl-n"', $html);
    }

    public function test_ac04_layout_classes(): void
    {
        $html = $this->page()->assertOk()->getContent();
        foreach (['<details class="sf-sec"', 'class="sf-groups"', 'class="sf-items"'] as $c) $this->assertStringContainsString($c, $html);
        $this->assertStringContainsString('repeat(auto-fill', file_get_contents(public_path('css/park.css')));
    }

    public function test_ac22_input_count_stays_small(): void
    {
        $this->assertLessThan(900, substr_count($this->page()->getContent(), 'name="'));
        // 全部チェックして保存したあとの画面でも
        $form = ['_sf' => '1', 'chk' => [], 'yr' => [], '_sf_end' => '1'];
        foreach (SkillForm::nodes() as $n) {
            if (!$n['checkable'] || $n['other']) continue;
            $form['chk'][$n['id']] = '1';
            if ($n['years']) $form['yr'][$n['id']] = '1年以上';
        }
        $this->save($form)->assertRedirect();
        $this->assertLessThan(900, substr_count($this->page()->getContent(), 'name="'));
        $this->assertLessThan(900, count($form['chk']) + count($form['yr']) + 4);
    }

    // ---------- 保存 ----------

    public function test_ac05_06_save_checks_and_years(): void
    {
        $old = DB::table('engineer_skills')->count();
        $this->save($this->checkForm(['lang/PHP' => '5年以上', 'lang/PHP/Laravel' => '']))
            ->assertRedirect("/engineers/{$this->eid}")
            ->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], 'スキルを保存しました。'));
        $rows = $this->checkRows($this->eid);
        $this->assertCount(2, $rows);
        $php = $rows->firstWhere('item', 'PHP');
        $lara = $rows->firstWhere('item', 'Laravel');
        $this->assertSame(5, (int) $php->years_min);
        $this->assertSame('', $php->parent);
        $this->assertSame(1, (int) $lara->years_min); // 年数のある項目は年数が必須（checkForm が空の年数を「1年以上」にして送る）
        $this->assertSame('PHP', $lara->parent);
        $this->assertSame('form', $php->source);
        $this->assertSame($old, DB::table('engineer_skills')->count()); // 古い表は変わらない
    }

    public function test_ac07_other_names_are_merged_and_limited(): void
    {
        $this->save($this->checkForm([], ['lang/PHP/その他' => [['Haxe', '1年以上'], [' haxe ', '3年以上'], ['', '']]]))->assertRedirect();
        $rows = $this->checkRows($this->eid);
        $this->assertCount(2, $rows); // その他の行と、自動で補われた親（PHP）の行
        $o = $rows->firstWhere('item', 'その他');
        $this->assertSame('Haxe', $o->other_text);
        $this->assertSame(3, (int) $o->years_min);
        $this->assertNotNull($rows->firstWhere('item', 'PHP'));

        // 41文字・11個は、何も変えずに断る
        $this->assertBad($this->save($this->checkForm(['lang/PHP' => ''], ['lang/PHP/その他' => [[str_repeat('Z', 41), '']]])));
        $this->assertCount(2, $this->checkRows($this->eid));
        $many = array_map(fn ($i) => ["Zname$i", ''], range(1, 11));
        $this->assertBad($this->save($this->checkForm(['lang/PHP' => ''], ['lang/PHP/その他' => $many])), '1つの「その他」に入れられるのは10個までです。');
        $this->assertCount(2, $this->checkRows($this->eid));
        // 40文字・10個までは通る
        $ten = array_map(fn ($i) => ["Zname$i", ''], range(1, 10));
        $this->save($this->checkForm([], ['lang/PHP/その他' => array_merge([[str_repeat('Z', 40), '']], array_slice($ten, 0, 9)), 'roles/その他' => [['SE', '5年以上']]]))->assertRedirect();
        $this->assertCount(12, $this->checkRows($this->eid)); // PHP のその他10＋役割のその他1＋補われた親（PHP）1
    }

    public function test_ac07_checked_other_with_empty_name_is_not_saved_and_noted(): void
    {
        $this->save($this->checkForm([], ['lang/PHP/その他' => [['', '']]]))
            ->assertSessionHas('flash', fn ($f) => str_contains($f['msg'], '名前が空のその他は保存しませんでした。'));
        $this->assertCount(0, $this->checkRows($this->eid));
    }

    // 子だけ送ると、親の行（年数なし）も保存される。親の行が先にあれば、その年数のまま
    public function test_child_alone_also_saves_parent_with_no_years(): void
    {
        $this->save($this->checkForm(['lang/PHP/Laravel' => '3年以上']))->assertRedirect();
        $rows = $this->checkRows($this->eid);
        $this->assertCount(2, $rows);
        $this->assertNull($rows->firstWhere('item', 'PHP')->years_min);
        $this->assertSame(3, (int) $rows->firstWhere('item', 'Laravel')->years_min);
        // 見出しだけのグループ（開発・運用ツールなど）の項目には、親の行は付かない
        $this->save($this->checkForm(['dev/バージョン管理/Git' => '']))->assertRedirect();
        $this->assertSame(['Git'], $this->checkRows($this->eid)->pluck('item')->all());
    }

    public function test_existing_parent_years_are_not_changed(): void
    {
        $this->save($this->checkForm(['lang/PHP' => '5年以上', 'lang/PHP/Laravel' => '']))->assertRedirect();
        $this->assertSame(5, (int) $this->checkRows($this->eid)->firstWhere('item', 'PHP')->years_min);
        // 年数だけ選んだ子（チェックなし）でも、親が補われる。画面で選んだ親の年数はそのまま
        $f = $this->checkForm(['lang/PHP' => '1年以上']);
        $f['yr'][$this->skillNode('lang/PHP/CakePHP')['id']] = '3年以上';
        $this->save($f)->assertRedirect();
        $rows = $this->checkRows($this->eid);
        $this->assertCount(2, $rows);
        $this->assertSame(1, (int) $rows->firstWhere('item', 'PHP')->years_min);
    }

    // 検証エラーで戻っても、子のチェックに合わせて親にチェックが付いて出る
    public function test_parent_check_stays_after_validation_error(): void
    {
        $url = "/engineers/{$this->eid}/skills";
        $f = $this->checkForm(['lang/Java/Spring Boot' => '']);
        $f['yr'][$this->skillNode('lang/Go')['id']] = '9年以上'; // 誤り
        $this->as(self::EG)->from($url)->post($url, $f)->assertRedirect($url);
        $html = $this->get($url)->assertOk()->getContent();
        $javaId = $this->skillNode('lang/Java')['id'];
        $this->assertMatchesRegularExpression('#name="chk\['.preg_quote($javaId, '#').'\]" value="1" data-sf="'.preg_quote($javaId, '#').'" checked#', $html);
        $this->assertCount(0, $this->checkRows($this->eid));
    }

    // 子だけで親が無い古いデータは、保存済みの画面でも親にチェックが付いて出る
    public function test_state_adds_parent_for_old_data_and_migration_000003_is_idempotent(): void
    {
        $now = now();
        DB::table('engineer_skill_checks')->insert(['engineer_id' => $this->eid, 'section' => 'lang', 'parent' => 'PHP', 'item' => 'Laravel', 'other_text' => '',
            'years_min' => 3, 'source' => 'form', 'created_at' => $now, 'updated_at' => $now]);
        DB::table('engineer_skill_checks')->insert(['engineer_id' => $this->eid, 'section' => 'dev', 'parent' => 'バージョン管理', 'item' => 'Git', 'other_text' => '',
            'years_min' => null, 'source' => 'form', 'created_at' => $now, 'updated_at' => $now]);
        $phpId = $this->skillNode('lang/PHP')['id'];
        $this->assertSame('1', SkillChecks::state($this->eid)['chk'][$phpId]);

        $mig = require database_path('migrations/2026_11_11_000003_fill_parent_checks.php');
        $mig->up();
        $rows = $this->checkRows($this->eid);
        $this->assertCount(3, $rows);   // PHP が補われた。見出しだけの「バージョン管理」には補わない
        $php = $rows->firstWhere('item', 'PHP');
        $this->assertSame('autoparent', $php->source);
        $this->assertNull($php->years_min);
        $mig->up();
        $this->assertCount(3, $this->checkRows($this->eid)); // 何度流しても同じ

        $mig->down(); // 補った行だけ消える
        $this->assertSame(['Git', 'Laravel'], $this->checkRows($this->eid)->pluck('item')->sort()->values()->all());
    }

    // 移行・ダミー・見本（saveRows）も、子の行に親を補う
    public function test_save_rows_adds_parent(): void
    {
        SkillChecks::saveRows($this->eid, [['section' => 'db', 'parent' => 'RDB', 'item' => 'MySQL', 'other_text' => '', 'years_min' => 3]], 'migrated');
        $rows = $this->checkRows($this->eid);
        $this->assertSame(['MySQL', 'RDB'], $rows->pluck('item')->sort()->values()->all());
        $this->assertSame('migrated', $rows->firstWhere('item', 'RDB')->source);
    }

    // 子の字下げ用のかたまり（.sf-children）と、親を外したときに子を外す JS が入っている
    public function test_children_are_wrapped_for_indent(): void
    {
        $html = $this->page()->assertOk()->getContent();
        $this->assertStringContainsString('class="sf-gitems sf-children"', $html);
        $this->assertStringContainsString('margin-left: 5mm', file_get_contents(public_path('css/park.css')));
        $js = file_get_contents(public_path('js/park.js'));
        $this->assertStringContainsString('function checkParent', $js);
        $this->assertStringContainsString('function uncheckChildren', $js);
    }

    public function test_ac09_same_name_under_two_parents_is_one_technology_for_matching(): void
    {
        $this->save($this->checkForm(['lang/JavaScript/React' => '1年以上', 'lang/TypeScript/React' => '3年以上']))->assertRedirect();
        $this->assertCount(4, $this->checkRows($this->eid)); // React の2行と、補われた親（JavaScript・TypeScript）
        $idx = SkillChecks::indexMany([$this->eid])[$this->eid];
        $this->assertSame('3年以上', $idx[norm_skill('React')]['years']);
        $summary = SkillChecks::summaryMany([$this->eid])[$this->eid];
        $this->assertSame(1, substr_count($summary, 'React'));
    }

    public function test_ac10_bad_year_saves_nothing_and_unknown_id_is_ignored(): void
    {
        $this->save($this->checkForm(['lang/Java' => '3年以上']))->assertRedirect();
        $f = $this->checkForm(['lang/PHP' => '5年以上']);
        $f['yr'][$this->skillNode('lang/PHP')['id']] = '2年以上';
        $this->assertBad($this->save($f), '年数は選択肢から選んでください。');
        $rows = $this->checkRows($this->eid);
        $this->assertCount(1, $rows);
        $this->assertSame('Java', $rows[0]->item);

        $g = $this->checkForm(['lang/PHP' => '5年以上']);
        $g['chk']['zzz-9'] = '1';
        $g['chk']['lang-999'] = '1';
        $this->save($g)->assertRedirect();
        $this->assertSame(['PHP'], $this->checkRows($this->eid)->pluck('item')->all());
    }

    public function test_year_without_check_is_saved_as_checked(): void
    {
        $f = $this->checkForm([]);
        $f['yr'][$this->skillNode('lang/Go')['id']] = '1年以上'; // チェックの無い年数（JavaScript が動かないときの守り）
        $f['yr'][$this->skillNode('phases/要件定義')['id']] = '5年以上'; // 年数の無い項目の年数は無視
        $this->save($f)->assertRedirect();
        $rows = $this->checkRows($this->eid);
        $this->assertSame(['Go'], $rows->pluck('item')->all());
        $this->assertSame(1, (int) $rows[0]->years_min);
    }

    public function test_ac11_input_stays_after_error(): void
    {
        $url = "/engineers/{$this->eid}/skills";
        $f = $this->checkForm(['lang/PHP' => '5年以上', 'roles/メンバー' => ''], ['lang/Java/その他' => [['Zzz', '1年以上']]]);
        $f['yr'][$this->skillNode('lang/Go')['id']] = '9年以上';
        $this->as(self::EG)->from($url)->post($url, $f)->assertRedirect($url);
        $html = $this->get($url)->assertOk()->getContent();
        $phpId = $this->skillNode('lang/PHP')['id'];
        $this->assertMatchesRegularExpression('#name="chk\['.preg_quote($phpId, '#').'\]" value="1" data-sf="'.preg_quote($phpId, '#').'" checked#', $html);
        $this->assertMatchesRegularExpression('#<option value="5年以上" selected>#', $html);
        $this->assertStringContainsString('value="Zzz"', $html);
        $this->assertStringContainsString('chk['.$this->skillNode('roles/メンバー')['id'].']" value="1" data-sf="'.$this->skillNode('roles/メンバー')['id'].'" checked', $html);
        $this->assertCount(0, $this->checkRows($this->eid)); // 何も保存されていない
    }

    public function test_boundary_zero_checks_and_double_post(): void
    {
        $this->save($this->checkForm(['lang/PHP' => '5年以上']))->assertRedirect();
        $this->save($this->checkForm([]))->assertRedirect("/engineers/{$this->eid}");
        $this->assertCount(0, $this->checkRows($this->eid));
        $this->as(self::EG)->get("/engineers/{$this->eid}")->assertOk()->assertSee('まだ登録がありません。');
        foreach (["/engineers/{$this->eid}/sheet", "/engineers/{$this->eid}/skills"] as $url) $this->get($url)->assertOk();

        $form = $this->checkForm(['lang/PHP' => '5年以上', 'db/RDB/MySQL' => '']);
        $this->save($form)->assertRedirect();
        $a = $this->checkRows($this->eid)->map(fn ($r) => [$r->item, $r->years_min])->all();
        $this->save($form)->assertRedirect();
        $this->assertSame($a, $this->checkRows($this->eid)->map(fn ($r) => [$r->item, $r->years_min])->all());
    }

    public function test_audit_log_has_count_and_names(): void
    {
        $this->save($this->checkForm(['lang/PHP' => '5年以上', 'lang/PHP/Laravel' => '']))->assertRedirect();
        $log = DB::table('audit_log')->where('action', 'skill-save')->first();
        $this->assertNotNull($log);
        $this->assertStringContainsString('チェック 2 件', $log->detail);
        $this->assertStringContainsString('Laravel', $log->detail);
    }

    // ---------- 表示 ----------

    public function test_ac12_display_in_detail_sheet_and_excel_lines(): void
    {
        $this->save($this->checkForm(['lang/PHP' => '5年以上', 'lang/PHP/Laravel' => '3年以上', 'lang/PHP/CakePHP' => '', 'roles/メンバー' => '5年以上']))->assertRedirect();
        $this->as(self::EG);
        $this->get("/engineers/{$this->eid}")->assertOk()->assertSee('PHP（5年以上）：Laravel（3年以上）、CakePHP', false)->assertDontSee('★');
        $this->get("/engineers/{$this->eid}/sheet")->assertOk()->assertSee('PHP（5年以上）', false)->assertSee('Laravel（3年以上）', false)->assertDontSee('得意な技術');
        $lines = implode("\n", \App\Services\EngineerDocs::skillLines($this->eid));
        $this->assertStringContainsString('【言語・フレームワーク】PHP（5年以上）：Laravel（3年以上）、CakePHP', $lines);
        $this->assertStringContainsString('【役割】メンバー（5年以上）', $lines);
        $this->assertStringNotContainsString('得意', $lines);
    }

    public function test_ac13_lists_show_skills_and_admin_can_search(): void
    {
        $this->save($this->checkForm(['lang/PHP' => '', 'lang/PHP/Laravel' => '']))->assertRedirect();
        $name = DB::table('engineers')->where('id', $this->eid)->value('name');
        $this->as('admin@park.example')->get('/admin/engineers?q=laravel')->assertOk()->assertSee($name)->assertSee('Laravel');
        $this->as($this->sesEmailOf($this->eid))->get('/ses/engineers')->assertOk()->assertSee('Laravel');
    }

    // 年数のある項目は、チェックしたら年数が必須（2026-11-11）
    public function test_years_are_required_for_checked_items_with_years(): void
    {
        $php = $this->skillNode('lang/PHP')['id'];
        $this->assertBad($this->save(['_sf' => '1', 'chk' => [$php => '1'], 'yr' => [], '_sf_end' => '1']));
        $this->assertSame(0, $this->checkRows($this->eid)->count());
        $other = $this->skillNode('lang/PHP/その他')['id'];
        $this->assertBad($this->save(['_sf' => '1', 'chk' => [$other => '1'], 'oth' => [$other => ['Haxe']], 'othyr' => [$other => ['']], '_sf_end' => '1']));
        $this->assertSame(0, $this->checkRows($this->eid)->count());
        $this->save(['_sf' => '1', 'chk' => [$php => '1'], 'yr' => [$php => '3年以上'], '_sf_end' => '1'])->assertRedirect();
        $this->assertSame(1, $this->checkRows($this->eid)->count());
    }

    // ---------- マッチング ----------

    public function test_ac14_matching_reasons_and_sheet_table(): void
    {
        $this->save($this->checkForm(['lang/Java' => '3年以上', 'db/RDB/MySQL' => '']))->assertRedirect();
        $mk = fn ($t, $req, $pref) => (int) DB::table('cases')->insertGetId(['title' => $t, 'required_skills' => $req, 'preferred_skills' => $pref, 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $c1 = $mk('案件ワン', 'java', 'MySQL');
        $c2 = $mk('案件ツー', 'MySQL', null);
        $e = DB::table('engineers')->find($this->eid);
        $reasons = Matcher::score(DB::table('cases')->find($c1), $e)['reasons'];
        $this->assertContains('必須 java（3年以上）', $reasons);
        $this->assertContains('尚可 MySQL', $reasons);
        $this->assertContains('必須 MySQL（1年以上）', Matcher::score(DB::table('cases')->find($c2), $e)['reasons']);

        // スキルシートの ?case= は、見る権限のある案件だけ（EG は、案内が届いた案件・応募した案件）
        foreach ([$c1, $c2] as $cid) DB::table('case_offers')->insert(['case_id' => $cid, 'engineer_id' => $this->eid, 'sent_at' => now(), 'status' => 'sent']);
        $this->as(self::EG)->get("/engineers/{$this->eid}/sheet?case=$c1")->assertOk()->assertSee('○ 3年以上');
        $this->get("/engineers/{$this->eid}/sheet?case=$c2")->assertOk()->assertSee('○ 1年以上');
    }

    // ---------- 権限 ----------

    public function test_ac16_permissions(): void
    {
        $form = $this->checkForm(['lang/PHP' => '1年以上']);
        $url = "/engineers/{$this->eid}/skills";
        // 管理者と自社の SES は開けて保存できる
        foreach (['admin@park.example', $this->sesEmailOf($this->eid)] as $mail) {
            $this->as($mail)->get($url)->assertOk()->assertDontSee('name="case"', false);
            $this->post($url, $form)->assertRedirect("/engineers/{$this->eid}");
        }
        $this->assertCount(1, $this->checkRows($this->eid));
        // 他社の SES と他の EG は 403（開くのも保存するのも）
        foreach (['ses1@park.example', 'eg1@park.example'] as $mail) {
            $this->as($mail)->get($url)->assertForbidden();
            $this->post($url, $this->checkForm(['lang/Go' => '']))->assertForbidden();
        }
        $this->assertSame(['PHP'], $this->checkRows($this->eid)->pluck('item')->all());
    }

    // ---------- 完成度・技術マスタ ----------

    public function test_ac19_sheet_check_counts_checks_and_years(): void
    {
        $five = ['lang/PHP' => '5年以上', 'lang/Java' => '3年以上', 'db/RDB/MySQL' => '1年以上', 'db/RDB' => '3年以上', 'roles/メンバー' => '5年以上', 'phases/要件定義' => '']; // 子を選ぶと親も入るので、親（RDB）の年数も選んでおく
        $this->save($this->checkForm($five))->assertRedirect();
        $tips = implode("\n", Qa::sheetCheck($this->eid)['tips']);
        $this->assertStringNotContainsString('5つ以上チェック', $tips);
        $this->assertStringNotContainsString('年数を選んでいない', $tips); // 年数の無い工程は数えない

        // 年数の無い行（古いデータ・取り込み）は、画面からは作れないので表に直接入れる
        DB::table('engineer_skill_checks')->where('engineer_id', $this->eid)->where('item', 'PHP')->update(['years_min' => null]);
        $tips = implode("\n", Qa::sheetCheck($this->eid)['tips']);
        $this->assertStringContainsString('年数を選んでいない項目があります（「経験あり」と出ます）。わかる範囲で選びましょう。', $tips);

        $this->save($this->checkForm(['lang/PHP' => '1年以上']))->assertRedirect();
        $this->assertStringContainsString('経験のある項目に5つ以上チェックを入れましょう。', implode("\n", Qa::sheetCheck($this->eid)['tips']));
    }

    public function test_ac21_other_word_goes_to_master_and_used_counts(): void
    {
        $this->save($this->checkForm([], ['lang/PHP/その他' => [['ZzLang', '1年以上']]]))->assertRedirect();
        $t = DB::table('skill_terms')->where('name', 'ZzLang')->first();
        $this->assertNotNull($t);
        $this->assertSame(0, (int) $t->reviewed);
        SkillCatalog::forget();
        $this->assertSame(1, SkillTable::usedCounts()[(int) $t->id] ?? 0);
        $this->as('admin@park.example')->get('/admin/skills')->assertOk()->assertSee('ZzLang');
        // 決まった項目の名前は、技術マスタに足さない
        $terms = DB::table('skill_terms')->count();
        $this->save($this->checkForm(['lang/PHP/CodeIgniter' => '', 'lang/Kotlin/Ktor' => '']))->assertRedirect();
        $this->assertSame($terms, DB::table('skill_terms')->count());
    }

    // ---------- JSON から消えた項目（D10） ----------

    public function test_d10_items_missing_from_json_are_kept_only_when_asked(): void
    {
        $this->save($this->checkForm(['lang/PHP' => '5年以上']))->assertRedirect();
        $id = (int) DB::table('engineer_skill_checks')->insertGetId(['engineer_id' => $this->eid, 'section' => 'lang', 'parent' => '', 'item' => 'ZzGoneLang',
            'other_text' => '', 'years_min' => 3, 'source' => 'form', 'created_at' => now(), 'updated_at' => now()]);
        $this->page()->assertOk()->assertSee('今の表に無い項目')->assertSee('ZzGoneLang');

        // keep[] を付けて保存すると残る
        $f = $this->checkForm(['lang/PHP' => '5年以上']);
        $f['keep'] = [$id];
        $this->save($f)->assertRedirect();
        $this->assertTrue(DB::table('engineer_skill_checks')->where('id', $id)->exists());
        // 付けずに保存すると消える
        $this->save($this->checkForm(['lang/PHP' => '5年以上']))->assertRedirect();
        $this->assertFalse(DB::table('engineer_skill_checks')->where('id', $id)->exists());
        $this->assertSame(['PHP'], $this->checkRows($this->eid)->pluck('item')->all());
    }

    // JSON で見出しだけに変わった項目の行も、「今の表に無い項目」に出て、黙って消えない（L4）
    public function test_l4_row_of_item_that_became_heading_is_shown_as_gone_and_not_silently_deleted(): void
    {
        $id = (int) DB::table('engineer_skill_checks')->insertGetId(['engineer_id' => $this->eid, 'section' => 'pm', 'parent' => '', 'item' => '課題・タスク管理',
            'other_text' => '', 'years_min' => null, 'source' => 'form', 'created_at' => now(), 'updated_at' => now()]);
        $this->assertNotEmpty(SkillChecks::gone($this->eid));
        $this->page()->assertOk()->assertSee('今の表に無い項目');
        // 画面を開いて、そのまま保存しても残る（keep が最初からチェックされている）
        $f = $this->checkForm(['lang/PHP' => '']);
        $f['keep'] = [$id];
        $this->save($f)->assertRedirect();
        $this->assertTrue(DB::table('engineer_skill_checks')->where('id', $id)->exists());
        // keep を付けないと消える
        $this->save($this->checkForm(['lang/PHP' => '']))->assertRedirect();
        $this->assertFalse(DB::table('engineer_skill_checks')->where('id', $id)->exists());
    }

    // 最後の隠し欄 _sf_end が届かなかったら（入力が多すぎて切れたとき）、保存しない（L1）
    public function test_l1_truncated_input_is_not_saved(): void
    {
        $this->save($this->checkForm(['lang/Java' => '3年以上']))->assertRedirect();
        $f = $this->checkForm(['lang/PHP' => '']);
        unset($f['_sf_end']);
        $this->save($f)->assertSessionHas('flash', fn ($m) => $m['type'] === 'bad' && str_contains($m['msg'], '入力が多すぎて送れませんでした'));
        $this->assertSame(['Java'], $this->checkRows($this->eid)->pluck('item')->all());
        $this->page()->assertSee('name="_sf_end"', false);
    }

    // 権限の無い案件は、スキルシートの ?case= で選んでも、選ばなかったものとして扱う（M1）
    public function test_m1_sheet_ignores_case_the_viewer_cannot_see(): void
    {
        $c = (int) DB::table('cases')->insertGetId(['title' => '案件ひみつエックス', 'required_skills' => 'java', 'status' => 'open', 'created_at' => now(), 'updated_at' => now()]);
        $this->as(self::EG)->get("/engineers/{$this->eid}/sheet?case=$c")->assertOk()->assertDontSee('案件ひみつエックス');
        DB::table('case_offers')->insert(['case_id' => $c, 'engineer_id' => $this->eid, 'sent_at' => now(), 'status' => 'sent']);
        $this->get("/engineers/{$this->eid}/sheet?case=$c")->assertOk()->assertSee('案件ひみつエックス');
    }

    // 同じ鍵の行が来ても黙って捨てず、年数の大きいほうに寄せる（H1）
    public function test_h1_save_rows_raises_years_of_existing_row(): void
    {
        $row = ['section' => 'lang', 'parent' => '', 'item' => 'PHP', 'other_text' => '', 'years_min' => 1];
        SkillChecks::saveRows($this->eid, [$row, ['years_min' => 3] + $row], 'seed');
        $this->assertSame([3], $this->checkRows($this->eid)->pluck('years_min')->map(fn ($v) => (int) $v)->all());
        SkillChecks::saveRows($this->eid, [['years_min' => 5] + $row], 'seed');
        SkillChecks::saveRows($this->eid, [['years_min' => 0] + $row], 'seed');
        $this->assertSame([5], $this->checkRows($this->eid)->pluck('years_min')->map(fn ($v) => (int) $v)->all());
    }
}
