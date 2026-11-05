<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// EG のスキル画面で、案件に合うスキルにチェックを入れる機能（PLAN.md AC-01〜12、境界、Matcher の単体）
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
        $this->as(self::EG)->post("/engineers/{$this->eid}/skills", $this->skillForm([
            ['name' => 'Java'], ['name' => 'Spring Boot'], ['name' => 'MySQL'], ['name' => 'Go'],
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

    // 指定のスキル名の入力欄がある行（<tr>…</tr>）を取り出す
    private function rowOf(string $html, string $name): string
    {
        preg_match_all('#<tr>.*?</tr>#s', $html, $m);
        foreach ($m[0] as $tr) {
            if (str_contains($tr, 'name="skill_name[]"') && str_contains($tr, 'value="'.$name.'"')) return $tr;
        }
        $this->fail("行が見つからない：$name");
    }

    public function test_ac01_select_shows_only_offered_or_applied_non_draft_cases(): void
    {
        $this->mkCase('案件ビー届かず', 'php', null);
        $c = $this->mkCase('案件シー下書き', 'php', null, 'draft'); $this->offer($c);
        $d = $this->mkCase('案件ディー応募のみ', 'php', null);
        DB::table('applications')->insert(['case_id' => $d, 'engineer_id' => $this->eid, 'ses_id' => $this->sesId, 'status' => 'applied', 'applied_at' => now()]);
        $r = $this->open();
        $r->assertOk()->assertSee('name="case"', false)->assertSee('案件エー')->assertSee('案件ディー応募のみ')
            ->assertDontSee('案件ビー届かず')->assertDontSee('案件シー下書き')->assertSee('合わせる');
        $r->assertDontSee('class="skill-hit"', false);
    }

    public function test_ac01_closed_case_is_listed_with_status_after_open_ones(): void
    {
        $f = $this->mkCase('案件エフ終了', 'php', null, 'closed'); $this->offer($f);
        $html = $this->open()->assertOk()->getContent();
        $this->assertStringContainsString('案件エフ終了（', $html);
        $this->assertLessThan(strpos($html, '案件エフ終了'), strpos($html, '案件エー'));
    }

    public function test_ac02_03_matching_rows_checked_with_marks(): void
    {
        $html = $this->open('?case='.$this->caseA)->assertOk()->assertSee('<th>合う</th>', false)->getContent();
        foreach (['Java', 'Spring Boot'] as $n) {
            $tr = $this->rowOf($html, $n);
            $this->assertStringContainsString('class="skill-hit" checked', $tr, $n);
            $this->assertStringContainsString('<small>必須</small>', $tr, $n);
        }
        $tr = $this->rowOf($html, 'MySQL');
        $this->assertStringContainsString('class="skill-hit" checked', $tr);
        $this->assertStringContainsString('<small>尚可</small>', $tr);
        $tr = $this->rowOf($html, 'Go');
        $this->assertStringContainsString('class="skill-hit"', $tr);
        $this->assertStringNotContainsString('checked', $tr);
        $this->assertStringContainsString('案件「案件エー」', $html);
    }

    public function test_ac03_short_names_need_exact_match(): void
    {
        $x = $this->mkCase('案件エックス', 'go, c', null); $this->offer($x);
        $this->as(self::EG)->post("/engineers/{$this->eid}/skills", $this->skillForm([['name' => 'Go'], ['name' => 'C#'], ['name' => 'Gogo']]));
        $html = $this->open('?case='.$x)->assertOk()->getContent();
        $this->assertStringContainsString('checked', $this->rowOf($html, 'Go'));
        $this->assertStringNotContainsString('checked', $this->rowOf($html, 'C#'));
        $this->assertStringNotContainsString('checked', $this->rowOf($html, 'Gogo'));
    }

    public function test_ac04_05_save_with_case_selected_keeps_rows_and_columns(): void
    {
        $cols = Schema::getColumnListing('engineer_skills');
        $html = $this->open('?case='.$this->caseA)->assertOk()->getContent();
        $this->assertDoesNotMatchRegularExpression('#<input type="checkbox" class="skill-hit"[^>]*name=#', $html);
        $r = $this->as(self::EG)->from("/engineers/{$this->eid}/skills?case={$this->caseA}")
            ->post("/engineers/{$this->eid}/skills", $this->skillForm([['name' => 'Java'], ['name' => 'Rust']]));
        $r->assertRedirect(url("/engineers/{$this->eid}"));
        $this->assertEqualsCanonicalizing(['Java', 'Rust'], DB::table('engineer_skills')->where('engineer_id', $this->eid)->pluck('name')->all());
        $this->assertSame($cols, Schema::getColumnListing('engineer_skills'));
    }

    public function test_ac06_no_case_means_old_screen(): void
    {
        foreach (['', '?case=', '?case'] as $q) {
            $this->open($q)->assertOk()->assertDontSee('class="skill-hit"', false)->assertDontSee('<th>合う</th>', false)->assertDontSee('スキル表に無い必須');
        }
    }

    public function test_ac07_missing_required_listed(): void
    {
        $k = $this->mkCase('案件ケー', 'Java, Kubernetes, Terraform', null); $this->offer($k);
        $this->open('?case='.$k)->assertOk()->assertSee('スキル表に無い必須：Kubernetes、Terraform');
        $this->open('?case='.$this->caseA)->assertOk()->assertDontSee('スキル表に無い必須');
    }

    public function test_ac08_no_other_case_details(): void
    {
        $this->open('?case='.$this->caseA)->assertOk()->assertDontSee('ひみつ取引先ZZ')->assertDontSee('説明の目印QQ')->assertDontSee('場所の目印WW')->assertDontSee('99.5');
    }

    public function test_ac09_other_roles_do_not_see_the_feature(): void
    {
        $sesEmail = DB::table('users')->where('ses_id', $this->sesId)->where('role', 'ses')->value('email');
        $this->assertNotEmpty($sesEmail);
        foreach (['admin@park.example', $sesEmail] as $mail) {
            $this->open('?case='.$this->caseA, $mail)->assertOk()->assertDontSee('name="case"', false)->assertDontSee('skill-hit', false)->assertDontSee('案件エー');
        }
        // 担当者が見本にいれば同じく確かめる
        $staff = DB::table('users')->where('ses_id', $this->sesId)->whereNotIn('role', ['ses', 'eg', 'admin'])->value('email');
        if ($staff) $this->open('?case='.$this->caseA, $staff)->assertOk()->assertDontSee('skill-hit', false)->assertDontSee('name="case"', false);
        // 兼任の人がいまの立場を管理者にした場合は、見本に兼任がいないため省略（手動確認）
    }

    public function test_ac10_invalid_case_values_are_ignored(): void
    {
        $b = $this->mkCase('案件ビー届かず', 'java', null);
        $c = $this->mkCase('案件シー下書き', 'java', null, 'draft'); $this->offer($c);
        foreach ([$b, $c, 999999, 'abc', '1x', '-1', '1.5'] as $v) {
            $this->open('?case='.$v)->assertOk()->assertDontSee('skill-hit', false)->assertDontSee('案件ビー届かず')->assertDontSee('案件シー下書き')->assertDontSee('<th>合う</th>', false);
        }
        $this->open('?case[]='.$this->caseA)->assertOk()->assertDontSee('skill-hit', false);
    }

    public function test_ac11_other_eg_gets_403(): void
    {
        $this->open('?case='.$this->caseA, 'eg1@park.example', $this->eid)->assertForbidden();
    }

    public function test_ac12_case_stays_after_validation_error(): void
    {
        $url = "/engineers/{$this->eid}/skills?case={$this->caseA}";
        $rows = [];
        foreach (['Java', 'Spring Boot', 'MySQL', 'Go', 'Rust', 'Ruby', 'PHP'] as $n) $rows[] = ['name' => $n, 'primary' => 1];
        $r = $this->as(self::EG)->from($url)->post("/engineers/{$this->eid}/skills", $this->skillForm($rows));
        $r->assertRedirect($url);
        $html = $this->get($url)->assertOk()->getContent();
        $this->assertStringContainsString('class="skill-hit" checked', $this->rowOf($html, 'Java'));
        $this->assertStringContainsString('<small>尚可</small>', $this->rowOf($html, 'MySQL'));
        $this->assertStringNotContainsString('checked', $this->rowOf($html, 'Rust'));
    }

    public function test_boundary_no_cases_at_all(): void
    {
        DB::table('case_offers')->delete();
        $this->open()->assertOk()->assertSee('案件が届くと、ここで選べます。')->assertDontSee('name="case"', false)->assertDontSee('合わせる');
    }

    public function test_boundary_zero_skills(): void
    {
        $this->cleanEg($this->eid);
        $html = $this->open('?case='.$this->caseA)->assertOk()->assertSee('スキル表に無い必須：java、spring boot')->getContent();
        $this->assertStringContainsString('<th>合う</th>', $html);
        $this->assertStringContainsString('class="skill-hit"', $html);
        $this->assertStringNotContainsString('checked', $html);
    }

    public function test_boundary_empty_required_and_preferred(): void
    {
        $e = $this->mkCase('案件イー空', null, ''); $this->offer($e);
        $html = $this->open('?case='.$e)->assertOk()->assertSee('この案件には、必須・尚可のスキルが書かれていません。')->assertDontSee('スキル表に無い必須')->getContent();
        $this->assertStringNotContainsString('checked', $html);
    }

    public function test_boundary_only_preferred(): void
    {
        $p = $this->mkCase('案件ピー尚可のみ', null, 'MySQL'); $this->offer($p);
        $html = $this->open('?case='.$p)->assertOk()->assertDontSee('スキル表に無い必須')->assertDontSee('書かれていません')->getContent();
        $this->assertStringContainsString('<small>尚可</small>', $this->rowOf($html, 'MySQL'));
        $this->assertStringNotContainsString('checked', $this->rowOf($html, 'Java'));
    }

    public function test_boundary_template_has_unchecked_box_and_save_notice(): void
    {
        $html = $this->open('?case='.$this->caseA)->getContent();
        preg_match('#<template id="skillrow">(.*?)</template>#s', $html, $m);
        $this->assertStringContainsString('class="skill-hit"', $m[1]);
        $this->assertStringNotContainsString('checked', $m[1]);
        $this->assertStringContainsString('先に保存してから選んでください', $html);
    }

    public function test_unit_row_marks(): void
    {
        $c = (object) ['required_skills' => 'Java, Kubernetes', 'preferred_skills' => 'java, Docker'];
        $m = Matcher::rowMarks(['Java', '', 'Docker', 'ＰＨＰ', 'Java'], $c);
        $this->assertSame([0 => 'required', 4 => 'required', 2 => 'preferred'], $m['marks']);
        $this->assertSame(['Kubernetes'], $m['missing']);
        $this->assertFalse($m['empty']);
        $m = Matcher::rowMarks(['PHP'], (object) ['required_skills' => 'php', 'preferred_skills' => null]);
        $this->assertSame([0 => 'required'], $m['marks']);
        $this->assertSame([], $m['missing']);
        $this->assertTrue(Matcher::rowMarks(['PHP'], (object) ['required_skills' => '', 'preferred_skills' => null])['empty']);
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
