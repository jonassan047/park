<?php

namespace Tests\Feature;

use App\Services\CaseParser;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

// v0.6.0 段階1：必須・尚可の規則の読み方（PLAN.md 6章 T1〜T6）。本文は架空の文だけ
class V060RuleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
    }

    private function sec(string $text): array
    {
        return CaseParser::sections($text);
    }

    // ---------- T1：いろいろな見出しの書き方 ----------
    public function test_t1_heading_styles(): void
    {
        $s = $this->sec("<<必須>>\nJava の開発経験\nSQL の経験\n<<尚可>>\nAWS");
        $this->assertSame(['Java の開発経験', 'SQL の経験'], $s['required']);
        $this->assertSame(['AWS'], $s['preferred']);

        $s = $this->sec("⇒必須\n・Java\n・SQL\n⇒尚可\n・AWS");
        $this->assertSame(['Java', 'SQL'], $s['required']);
        $this->assertSame(['AWS'], $s['preferred']);

        $s = $this->sec("スキル(必須)：\n・Go\n・Rust\nスキル(尚可)：\n・Perl");
        $this->assertSame(['Go', 'Rust'], $s['required']);
        $this->assertSame(['Perl'], $s['preferred']);

        $s = $this->sec("◆スキル：\n・PHP\n・MySQL");
        $this->assertSame(['PHP', 'MySQL'], $s['required']);
        $this->assertSame([], $s['preferred']);

        $s = $this->sec("【必須】\n・Java\n【尚可】\n・Go");
        $this->assertSame(['Java'], $s['required']);
        $this->assertSame(['Go'], $s['preferred']);

        $s = $this->sec("MUST\n・Java\nWANT\n・Go");
        $this->assertSame(['Java'], $s['required']);
        $this->assertSame(['Go'], $s['preferred']);

        $s = $this->sec("■必須スキル■\n・Java\n■歓迎スキル■\n・Go");
        $this->assertSame(['Java'], $s['required']);
        $this->assertSame(['Go'], $s['preferred']);
    }

    // ---------- T2：箇条書きの印・番号・続きの行 ----------
    public function test_t2_bullets_numbers_and_continuation(): void
    {
        $s = $this->sec("必須:\n(1) Java\n(2) SQL\n(3) Go");
        $this->assertSame(['Java', 'SQL', 'Go'], $s['required'], '番号の印は外れる');

        $s = $this->sec("必須:\nJava\nSQL\nGo");
        $this->assertSame(['Java', 'SQL', 'Go'], $s['required'], '印の無い節は1行が1項目');

        $s = $this->sec("必須:\n・Java の経験\n  3年以上\n・SQL");
        $this->assertSame(['Java の経験 3年以上', 'SQL'], $s['required'], '箇条書きの節の印の無い行は前の項目の続き');

        $s = $this->sec("必須:\n　・Java　\n　・SQL");
        $this->assertSame(['Java', 'SQL'], $s['required'], '全角空白があっても文字が壊れない');
    }

    // ---------- T3：見出しと同じ行の本文 ----------
    public function test_t3_body_on_heading_line(): void
    {
        $s = $this->sec("■必　須：Java の開発経験");
        $this->assertSame(['Java の開発経験'], $s['required']);

        $s = $this->sec("■必須（いずれか）\n・Java");
        $this->assertSame(['Java'], $s['required'], '（いずれか）は項目にならない');
    }

    // ---------- T4：節の終わり・小見出し・子項目・補足 ----------
    public function test_t4_section_end_subheading_child_and_note(): void
    {
        $s = $this->sec("必須:\n・Java\n  - Spring\n※経験3年以上\n・SQL\n---\n・節の外の行");
        $this->assertSame(['Java Spring ※経験3年以上', 'SQL'], $s['required'], '子項目と※は前の項目に付き、区切り線で終わる');

        $s = $this->sec("必須:\n・Java\n単価：70万\n・SQL");
        $this->assertSame(['Java'], $s['required'], '項目名＋コロンで節が終わる');

        $s = $this->sec("必須:\n【テクニカルスキル】\n・Java");
        $this->assertSame(['Java'], $s['required'], '小見出しは項目にならない');

        $s = $this->sec("必須:\n・Java\n\n節の外の文");
        $this->assertSame(['Java'], $s['required'], '空行のあとの印の無い行で終わる');

        $s = $this->sec("必須:\n・Java\n■概要\n・節の外の行");
        $this->assertSame(['Java'], $s['required'], '次の見出しで終わる');
    }

    // ---------- T5：D3 の形 ----------
    public function test_t5_extra_forms(): void
    {
        $s = $this->sec("推奨：\n・Go");
        $this->assertSame([], $s['required']);
        $this->assertSame(['Go'], $s['preferred'], '推奨は尚可');

        $s = $this->sec("■必須スキル/経験\n・Java");
        $this->assertSame(['Java'], $s['required'], '/経験 は項目にならない');

        $s = $this->sec("必須:\n・Java\n・Go の経験（尚可）");
        $this->assertSame(['Java'], $s['required']);
        $this->assertSame(['Go の経験'], $s['preferred'], '行の中の（尚可）は尚可へ移り、印は消える');
    }

    // ---------- T6：今までの読み方・後処理は変えない ----------
    public function test_t6_parse_keeps_old_behavior(): void
    {
        $p = CaseParser::parse("【案件名】在庫管理\n【必須】Java、SQL\n【尚可】AWS");
        $this->assertSame('Java、SQL', $p['required_skills']);
        $this->assertStringStartsWith('AWS', $p['preferred_skills']);

        // 新しい読み方で読めない形は、今までの読み方（R9）で読む
        $p = CaseParser::parse("案件名：テスト\n必須経験：Java");
        $this->assertStringContainsString('Java', $p['required_skills']);

        // 必須が空なら、本文の技術が必須に入る（R10）
        $p = CaseParser::parse("案件名：テスト\n概要：PHP と Laravel で作る画面");
        $this->assertStringContainsString('PHP', $p['required_skills']);

        // 業務の拾い出しは今と同じ
        $p = CaseParser::parse("【案件】テスト取り込み\n■必須スキル\n・進捗管理の経験\n・AIX の運用\n■尚可スキル\n・英語での会話");
        $this->assertSame('進捗管理の経験、AIX の運用', $p['required_skills']);
        $this->assertSame('進捗・課題管理', $p['required_exp']);
        $this->assertSame('英語・海外', $p['preferred_exp']);
    }
}
