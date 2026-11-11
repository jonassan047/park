<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\SkillChecks;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// EG のスキル画面で、案件の必須・尚可に当たる項目に「必須」「尚可」の印を付ける機能（PLAN.md AC-15、D7、境界、SkillChecks::caseMarks の単体）。
// v0.4.0 から、印を付けるだけで、チェックは自動では変わらない。印は保存されない。
class SkillCaseCheckTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const EG = 'eg2@park.example';

    private int $eid;
    private int $sesId;
    private int $caseA;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        $this->eid = $this->engId(self::EG);
        $this->sesId = (int) DB::table('engineers')->where('id', $this->eid)->value('ses_id');
        $this->cleanEg($this->eid);
        $this->as(self::EG)->post("/engineers/{$this->eid}/skills", $this->checkForm([
            'lang/Java' => '', 'lang/Java/Spring Boot' => '', 'db/RDB/MySQL' => '', 'lang/Go' => '',
        ]));
        $this->caseA = $this->mkCase('案件エー', 'java, spring boot', 'MySQL', 'open', ['price' => 99.5, 'client_label' => 'ひみつ取引先ZZ', 'description' => '説明の目印QQ', 'location' => '場所の目印WW']);
        $this->offer($this->caseA);
    }

    private function mkCase(string $title, ?string $req, ?string $pref, string $status = 'open', array $extra = []): int
    {
        return (int) DB::table('cases')->insertGetId($extra + ['title' => $title, 'required_skills' => $req, 'preferred_skills' => $pref, 'status' => $status,
            'created_at' => now(), 'updated_at' => now()]);
    }

    private function offer(int $caseId, ?int $eid = null): void
    {
        DB::table('case_offers')->insert(['case_id' => $caseId, 'engineer_id' => $eid ?? $this->eid, 'sent_at' => now(), 'status' => 'sent']);
    }

    private function open(string $query = '', string $email = self::EG, ?int $eid = null)
    {
        return $this->as($email)->get('/engineers/'.($eid ?? $this->eid).'/skills'.$query);
    }

    // 指定の道筋の項目のラベル（<label>…</label>）を取り出す
    private function labelOf(string $html, string $path): string
    {
        $id = $this->skillNode($path)['id'];
        preg_match('#<label class="sf-item[^"]*"><input type="checkbox" name="chk\['.preg_quote($id, '#').'\]".*?</label>#s', $html, $m);
        $this->assertNotEmpty($m, "ラベルが見つからない：$path");
        return $m[0];
    }

    public function test_ac15_select_shows_only_offered_or_applied_non_draft_cases(): void
    {
        $this->mkCase('案件ビー届かず', 'php', null);
        $c = $this->mkCase('案件シー下書き', 'php', null, 'draft'); $this->offer($c);
        $d = $this->mkCase('案件ディー応募のみ', 'php', null);
        DB::table('applications')->insert(['case_id' => $d, 'engineer_id' => $this->eid, 'ses_id' => $this->sesId, 'status' => 'applied', 'applied_at' => now()]);
        $r = $this->open();
        $r->assertOk()->assertSee('name="case"', false)->assertSee('案件エー')->assertSee('案件ディー応募のみ')
            ->assertDontSee('案件ビー届かず')->assertDontSee('案件シー下書き')->assertSee('合わせる');
        $r->assertDontSee('sf-mark', false);
    }

    public function test_closed_case_is_listed_with_status_after_open_ones(): void
    {
        $f = $this->mkCase('案件エフ終了', 'php', null, 'closed'); $this->offer($f);
        $html = $this->open()->assertOk()->getContent();
        $this->assertStringContainsString('案件エフ終了（', $html);
        $this->assertLessThan(strpos($html, '案件エフ終了'), strpos($html, '案件エー'));
    }

    public function test_marks_are_added_but_checks_do_not_change(): void
    {
        $html = $this->open('?case='.$this->caseA)->assertOk()->getContent();
        foreach (['lang/Java', 'lang/Java/Spring Boot'] as $path) {
            $l = $this->labelOf($html, $path);
            $this->assertStringContainsString('class="sf-item hit"', $l, $path);
            $this->assertStringContainsString('<small class="sf-mark">必須</small>', $l, $path);
            $this->assertStringContainsString(' checked', $l, $path); // 保存済みだったので、チェックのまま
        }
        $l = $this->labelOf($html, 'db/RDB/MySQL');
        $this->assertStringContainsString('<small class="sf-mark">尚可</small>', $l);
        // 保存していない項目は、印が付いても、チェックは付かない
        $l = $this->labelOf($html, 'lang/Kotlin/Spring Boot');
        $this->assertStringContainsString('<small class="sf-mark">必須</small>', $l);
        $this->assertStringNotContainsString(' checked', $l);
        // 当たらない項目には印が無い
        $l = $this->labelOf($html, 'lang/Go');
        $this->assertStringNotContainsString('sf-mark', $l);
        $this->assertStringContainsString('案件「案件エー」', $html);
        $this->assertStringContainsString('印は保存されません', $html);
    }

    public function test_sections_with_marks_are_open(): void
    {
        $this->cleanEg($this->eid);
        $html = $this->open('?case='.$this->caseA)->assertOk()->getContent();
        $this->assertStringContainsString('data-sf-sec="lang" open', $html);
        $this->assertStringContainsString('data-sf-sec="db" open', $html);
        $this->assertStringNotContainsString('data-sf-sec="os" open', $html);
    }

    public function test_short_names_need_exact_match(): void
    {
        $x = $this->mkCase('案件エックス', 'go, c', null); $this->offer($x);
        $html = $this->open('?case='.$x)->assertOk()->getContent();
        $this->assertStringContainsString('sf-mark', $this->labelOf($html, 'lang/Go'));
        $this->assertStringContainsString('sf-mark', $this->labelOf($html, 'lang/C'));
        $this->assertStringNotContainsString('sf-mark', $this->labelOf($html, 'lang/C#'));
    }

    public function test_save_with_case_selected_saves_only_checks(): void
    {
        $old = DB::table('engineer_skills')->count();
        $this->open('?case='.$this->caseA)->assertOk();
        $r = $this->as(self::EG)->from("/engineers/{$this->eid}/skills?case={$this->caseA}")
            ->post("/engineers/{$this->eid}/skills", $this->checkForm(['lang/Java' => '', 'lang/Rust' => '']));
        $r->assertRedirect(url("/engineers/{$this->eid}"));
        $this->assertEqualsCanonicalizing(['Java', 'Rust'], $this->checkRows($this->eid)->pluck('item')->all()); // 印の付いた項目は、チェックしなければ保存されない
        $this->assertSame($old, DB::table('engineer_skills')->count());
    }

    public function test_no_case_means_no_marks(): void
    {
        foreach (['', '?case=', '?case'] as $q) {
            $this->open($q)->assertOk()->assertDontSee('sf-mark', false)->assertDontSee('あなたのスキルに無い必須');
        }
    }

    public function test_missing_required_listed(): void
    {
        $k = $this->mkCase('案件ケー', 'Java, Kubernetes, Terraform', null); $this->offer($k);
        $this->open('?case='.$k)->assertOk()->assertSee('あなたのスキルに無い必須：Kubernetes、Terraform');
        $this->open('?case='.$this->caseA)->assertOk()->assertDontSee('あなたのスキルに無い必須');
    }

    public function test_no_other_case_details(): void
    {
        $this->open('?case='.$this->caseA)->assertOk()->assertDontSee('ひみつ取引先ZZ')->assertDontSee('説明の目印QQ')->assertDontSee('場所の目印WW')->assertDontSee('99.5');
    }

    public function test_other_roles_do_not_see_the_feature(): void
    {
        $sesEmail = DB::table('users')->where('ses_id', $this->sesId)->where('role', 'ses')->value('email');
        $this->assertNotEmpty($sesEmail);
        foreach (['admin@park.example', $sesEmail] as $mail) {
            $this->open('?case='.$this->caseA, $mail)->assertOk()->assertDontSee('name="case"', false)->assertDontSee('sf-mark', false)->assertDontSee('案件エー');
        }
        $staff = DB::table('users')->where('ses_id', $this->sesId)->whereNotIn('role', ['ses', 'eg', 'admin'])->value('email');
        if ($staff) $this->open('?case='.$this->caseA, $staff)->assertOk()->assertDontSee('sf-mark', false)->assertDontSee('name="case"', false);
    }

    public function test_invalid_case_values_are_ignored(): void
    {
        $b = $this->mkCase('案件ビー届かず', 'java', null);
        $c = $this->mkCase('案件シー下書き', 'java', null, 'draft'); $this->offer($c);
        foreach ([$b, $c, 999999, 'abc', '1x', '-1', '1.5'] as $v) {
            $this->open('?case='.$v)->assertOk()->assertDontSee('sf-mark', false)->assertDontSee('案件ビー届かず')->assertDontSee('案件シー下書き');
        }
        $this->open('?case[]='.$this->caseA)->assertOk()->assertDontSee('sf-mark', false);
    }

    public function test_other_eg_gets_403(): void
    {
        $this->open('?case='.$this->caseA, 'eg1@park.example', $this->eid)->assertForbidden();
    }

    public function test_case_stays_after_validation_error(): void
    {
        $url = "/engineers/{$this->eid}/skills?case={$this->caseA}";
        $f = $this->checkForm(['lang/Java' => '', 'lang/Rust' => '', 'db/RDB/MySQL' => '']);
        $f['yr'][$this->skillNode('lang/Go')['id']] = '9年以上'; // 誤り
        $r = $this->as(self::EG)->from($url)->post("/engineers/{$this->eid}/skills", $f);
        $r->assertRedirect($url);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('sf-mark', $this->labelOf($html, 'lang/Java'));
        $this->assertStringContainsString('<small class="sf-mark">尚可</small>', $this->labelOf($html, 'db/RDB/MySQL'));
        $this->assertStringContainsString(' checked', $this->labelOf($html, 'lang/Rust')); // 送ったチェックのとおり
        $this->assertStringNotContainsString(' checked', $this->labelOf($html, 'lang/Go'));
    }

    public function test_boundary_no_cases_at_all(): void
    {
        DB::table('case_offers')->delete();
        $this->open()->assertOk()->assertSee('案件が届くと、ここで選べます。')->assertDontSee('name="case"', false)->assertDontSee('合わせる');
    }

    public function test_boundary_zero_skills(): void
    {
        $this->cleanEg($this->eid);
        $html = $this->open('?case='.$this->caseA)->assertOk()->assertSee('あなたのスキルに無い必須：java、spring boot')->getContent();
        $this->assertStringContainsString('sf-mark', $html);
        $this->assertStringNotContainsString(' checked', $html);
    }

    public function test_boundary_empty_required_and_preferred(): void
    {
        $e = $this->mkCase('案件イー空', null, ''); $this->offer($e);
        $html = $this->open('?case='.$e)->assertOk()->assertSee('この案件には、必須・尚可のスキルが書かれていません。')->assertDontSee('あなたのスキルに無い必須')->getContent();
        $this->assertStringNotContainsString('sf-mark', $html);
    }

    public function test_boundary_only_preferred(): void
    {
        $p = $this->mkCase('案件ピー尚可のみ', null, 'MySQL'); $this->offer($p);
        $html = $this->open('?case='.$p)->assertOk()->assertDontSee('あなたのスキルに無い必須')->assertDontSee('書かれていません')->getContent();
        $this->assertStringContainsString('<small class="sf-mark">尚可</small>', $this->labelOf($html, 'db/RDB/MySQL'));
        $this->assertStringNotContainsString('sf-mark', $this->labelOf($html, 'lang/Java'));
    }

    public function test_boundary_save_notice(): void
    {
        $this->assertStringContainsString('先に保存してから選んでください', $this->open('?case='.$this->caseA)->getContent());
    }

    public function test_unit_case_marks(): void
    {
        $java = $this->skillNode('lang/Java')['id'];
        $docker = $this->skillNode('dev/コンテナ・オーケストレーション/Docker')['id'];
        $state = SkillChecks::stateFromInput(['chk' => [$java => '1']]);
        $m = SkillChecks::caseMarks($state, (object) ['required_skills' => 'Java, Kubernetes', 'preferred_skills' => 'java, Docker']);
        $this->assertSame('required', $m['marks'][$java]);
        $this->assertSame('preferred', $m['marks'][$docker]);
        $this->assertSame(['Kubernetes'], $m['missing']);
        $this->assertFalse($m['empty']);

        $php = $this->skillNode('lang/PHP')['id'];
        $m = SkillChecks::caseMarks(SkillChecks::stateFromInput(['chk' => [$php => '1']]), (object) ['required_skills' => 'php', 'preferred_skills' => null]);
        $this->assertSame('required', $m['marks'][$php]);
        $this->assertSame([], $m['missing']);
        $this->assertTrue(SkillChecks::caseMarks([], (object) ['required_skills' => '', 'preferred_skills' => null])['empty']);

        // 「その他」に書いた名前も、印と「無い必須」の判定に使う
        $other = $this->skillNode('lang/PHP/その他')['id'];
        $state = SkillChecks::stateFromInput(['chk' => [$other => '1'], 'oth' => [$other => ['Haxe']]]);
        $m = SkillChecks::caseMarks($state, (object) ['required_skills' => 'Haxe', 'preferred_skills' => null]);
        $this->assertSame('required', $m['marks'][$other]);
        $this->assertSame([], $m['missing']);
    }

    public function test_unit_same_skill_and_has_skill_unchanged(): void
    {
        $this->assertTrue(Matcher::sameSkill('java', 'java'));
        $this->assertFalse(Matcher::sameSkill('c', 'c#'));
        $this->assertFalse(Matcher::sameSkill('go', 'gox'));
        $this->assertSame(loose_match('springboot', 'spring'), Matcher::sameSkill('springboot', 'spring'));
        $idx = [norm_skill('Java') => ['x'], norm_skill('Go') => ['g']];
        $this->assertSame(['x'], Matcher::hasSkill($idx, 'JAVA'));
        $this->assertSame(['g'], Matcher::hasSkill($idx, 'go'));
        $this->assertNull(Matcher::hasSkill($idx, 'Rust'));
        $this->assertNull(Matcher::hasSkill(['c#' => ['c']], 'c'));
    }
}
