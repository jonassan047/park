<?php

namespace Tests\Feature;

use App\Services\EngineerDocs;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.3.0 テスト設計書 K（スキル表）のうち、v0.4.0（チェック式）でも残る部分。
// レベル・得意・ひとこと・最後に使った年月・名前の付き合わせは、v0.4.0 で無くなったので消した（新しい画面のテストは SkillFormTest）。
class V030SkillsTest extends TestCase
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

    public function test_k_11_zero_skills_do_not_break_screens_or_export(): void
    {
        $this->as(self::EG);
        foreach (["/engineers/{$this->eid}", "/engineers/{$this->eid}/skills", "/engineers/{$this->eid}/sheet"] as $url) $this->get($url)->assertOk();
        $this->assertSame([], EngineerDocs::skillLines($this->eid));
    }

    public function test_k_12_old_table_is_not_read_or_written(): void
    {
        DB::table('engineer_skills')->insert(['engineer_id' => $this->eid, 'name' => 'ZzOldOnly', 'category' => 'fw', 'term_id' => null, 'years' => 2, 'level' => 3]);

        $this->as(self::EG);
        $this->get("/engineers/{$this->eid}")->assertOk()->assertDontSee('ZzOldOnly');
        $this->get("/engineers/{$this->eid}/sheet")->assertOk()->assertDontSee('ZzOldOnly');
        $this->assertSame([], EngineerDocs::skillLines($this->eid));

        $this->post("/engineers/{$this->eid}/skills", $this->checkForm(['lang/PHP' => '1年以上']))->assertRedirect();
        $this->assertSame(['ZzOldOnly'], DB::table('engineer_skills')->where('engineer_id', $this->eid)->pluck('name')->all());
    }

    public function test_k_13_level_note_and_last_used_are_not_shown_anywhere(): void
    {
        $this->as(self::EG)->post("/engineers/{$this->eid}/skills", $this->checkForm(['lang/PHP' => '5年以上']))->assertRedirect();
        $page = $this->get("/engineers/{$this->eid}/skills")->assertOk();
        foreach (['レベルの目安', '最後に使った年月', '得意', 'ひとこと'] as $gone) $page->assertDontSee($gone);
        $this->get("/engineers/{$this->eid}/sheet")->assertOk()->assertDontSee('得意な技術');
    }
}
