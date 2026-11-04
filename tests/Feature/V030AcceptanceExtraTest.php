<?php

namespace Tests\Feature;

use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.3.0 受け入れ検証で足した境界ケース（設計書 K-04・K-08・K-10・A-09・R-07 の穴埋め）
class V030AcceptanceExtraTest extends TestCase
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

    private function save(array $rows)
    {
        return $this->as(self::EG)->post("/engineers/{$this->eid}/skills", $this->skillForm($rows));
    }

    private function rowCount(): int
    {
        return DB::table('engineer_skills')->where('engineer_id', $this->eid)->count();
    }

    private function bad($r): void
    {
        $r->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad');
    }

    // K-04：日本語（全角）でも 40 文字は通り、41 文字は断られる
    public function test_k_04_multibyte_name_boundary(): void
    {
        $this->bad($this->save([['name' => str_repeat('あ', 41)]]));
        $this->assertSame(0, $this->rowCount());
        $this->save([['name' => str_repeat('あ', 40)]])->assertRedirect();
        $this->assertSame(1, $this->rowCount());
    }

    // K-08：得意ちょうど 5 つは通る（境界）
    public function test_k_08_exactly_five_primaries_pass(): void
    {
        $rows = [];
        foreach (['Java', 'PHP', 'Laravel', 'Docker', 'MySQL'] as $n) $rows[] = ['name' => $n, 'primary' => 1];
        $this->save($rows)->assertRedirect();
        $this->assertSame(5, DB::table('engineer_skills')->where('engineer_id', $this->eid)->where('is_primary', 1)->count());
    }

    // K-10：全角 200 文字は通り、201 文字は断られ、保存済みの行は変わらない
    public function test_k_10_note_boundary_keeps_saved_row(): void
    {
        $this->save([['name' => 'Java', 'note' => str_repeat('あ', 200)]])->assertRedirect();
        $this->bad($this->save([['name' => 'Java', 'note' => str_repeat('あ', 201)]]));
        $this->assertSame(200, mb_strlen(DB::table('engineer_skills')->where('engineer_id', $this->eid)->value('note')));
    }

    // K-08：断られたら今の行も変わらない
    public function test_k_08_refusal_keeps_existing_rows(): void
    {
        $this->save([['name' => 'Java', 'primary' => 1]])->assertRedirect();
        $ids = DB::table('engineer_skills')->where('engineer_id', $this->eid)->pluck('id')->all();
        $rows = [];
        foreach (['Java', 'PHP', 'Laravel', 'Docker', 'MySQL', 'Redis'] as $n) $rows[] = ['name' => $n, 'primary' => 1];
        $this->bad($this->save($rows));
        $this->assertSame($ids, DB::table('engineer_skills')->where('engineer_id', $this->eid)->pluck('id')->all());
    }

    // A-09：未ログインはログインへ。管理者は技術マスタの言葉を足せる
    public function test_a_09_guest_is_redirected_and_admin_can_add_term(): void
    {
        $this->get('/admin/skills')->assertRedirect();
        $this->as('admin@park.example');
        $this->post('/admin/skills', ['name' => 'ZzAdminAdded', 'category' => 'tool']);
        $this->assertTrue(DB::table('skill_terms')->where('name', 'ZzAdminAdded')->exists());
    }

    // R-07：正本（park の resources/manuals）と、41_Park-Project/04_Manuals の写しが一致する。写しが無い PC では飛ばす
    public function test_r_07_manual_copies_match_originals(): void
    {
        $root = 'D:/Workspace/41_Park-Project/04_Manuals';
        if (! is_dir($root)) $this->markTestSkipped('04_Manuals がこの PC に無い');
        $pairs = [
            'admin.md' => '管理者/マニュアル.md', 'admin-steps.md' => '管理者/操作手順書.md',
            'ses.md' => 'SES/マニュアル.md', 'ses-steps.md' => 'SES/操作手順書.md',
            'engineer.md' => 'エンジニア/マニュアル.md', 'engineer-steps.md' => 'エンジニア/操作手順書.md',
        ];
        $diff = [];
        foreach ($pairs as $src => $copy) {
            if (file_get_contents(resource_path("manuals/$src")) !== file_get_contents("$root/$copy")) $diff[] = $src;
        }
        $this->assertSame([], $diff, '写しが正本と違う');
    }
}
