<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\CaseParser;
use App\Services\Matcher;
use App\Support\SkillCatalog;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

// 細かい登録・技術マスタ・データ領域（書類の書き出し・アップロード・ダウンロード）
class EngineerDetailTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    public function test_skill_master_normalizes_aliases(): void
    {
        $this->assertSame(norm_skill('PostgreSQL'), norm_skill('Postgres'));
        $this->assertSame(norm_skill('Zend Framework'), norm_skill('ZendFramework2'));
        $this->assertSame('SQL Server', SkillCatalog::find('SQLServer')[1]);
        $this->assertNotSame(norm_skill('Java'), norm_skill('JavaScript'));
        // 業務経歴の「PHP(Laravel)」は PHP と Laravel に分ける
        $this->assertSame(['PHP', 'Laravel', 'MariaDB'], project_techs((object) ['tech' => '', 'os_tools' => 'PHP(Laravel)', 'langs' => '', 'dbs' => 'MariaDB']));
        // 別名で書かれた案件でも、エンジニアのスキルに当たる
        $c = (object) ['required_skills' => 'Postgres', 'preferred_skills' => '', 'min_years' => null, 'price' => null, 'start_date' => null, 'work_style' => 'any'];
        $e = DB::table('engineers')->where('name', '山田 太郎')->first();
        DB::table('engineer_skills')->insert(['engineer_id' => $e->id, 'name' => 'PostgreSQL', 'category' => 'db', 'years' => 3, 'level' => 3]);
        $this->assertContains('必須 Postgres（3年）', Matcher::score($c, $e)['reasons']);
    }

    public function test_case_mail_detects_and_auto_adds_terms(): void
    {
        $p = CaseParser::parse("【案件名】在庫システム\n【必須】PHP、Laravel\n【概要】Docker と AWS 上で、Vue.js の画面も触ります");
        $this->assertContains('Docker', $p['detected']);
        $this->assertStringContainsString('Vue.js', $p['preferred_skills']);
        $this->as('admin@park.example');
        $this->post('/cases', ['title' => '新技術の案件', 'required_skills' => 'PHP、Hotwire', 'preferred_skills' => 'Postgres', 'status' => 'open'])->assertRedirect();
        $this->assertDatabaseHas('skill_terms', ['name' => 'Hotwire', 'source' => 'auto', 'reviewed' => 0]);
        // 別名は正式な名前にそろえて保存する
        $this->assertDatabaseHas('cases', ['title' => '新技術の案件', 'preferred_skills' => 'PostgreSQL']);
        $this->get('/admin/skills')->assertOk()->assertSee('Hotwire')->assertSee('確認待ち');
        $id = DB::table('skill_terms')->where('name', 'Hotwire')->value('id');
        $this->post("/admin/skills/$id", ['name' => 'Hotwire', 'category' => 'fw', 'aliases' => 'Turbo'])->assertRedirect();
        $this->assertDatabaseHas('skill_terms', ['name' => 'Hotwire', 'category' => 'fw', 'reviewed' => 1]);
        $to = DB::table('skill_terms')->where('name', 'PostgreSQL')->value('id');
        SkillCatalog::ensure('Postgre');
        $pid = DB::table('skill_terms')->where('name', 'Postgre')->value('id');
        $this->post("/admin/skills/$pid/merge", ['to' => $to])->assertRedirect();
        $this->assertSame('PostgreSQL', SkillCatalog::find('Postgre')[1]);
        $this->as('ses1@park.example')->get('/admin/skills')->assertForbidden();
    }

    public function test_profile_histories_and_documents(): void
    {
        Storage::fake('local');
        $this->as('eg1@park.example');
        $this->get('/engineers/1/profile')->assertOk();
        $this->post('/engineers/1/profile', ['birth_date' => '1984-04-01', 'phone' => '090-0000-0000', 'address' => '東京都港区1-2-3', 'nearest_line' => '山手線',
            'walk_minutes' => '5', 'education' => '架空大学 工学部', 'graduated_ym' => '2007-03', 'career_summary' => '業務系の開発を15年。', 'desired_roles' => 'SE・PL',
            'h_kind' => ['edu', 'work'], 'h_ym' => ['2007-03', '2007-04'], 'h_text' => ['架空大学 工学部 卒業', '架空システム株式会社 入社'],
            'c_name' => ['基本情報技術者'], 'c_ym' => ['2008-10']])->assertRedirect();
        $this->assertDatabaseHas('engineers', ['id' => 1, 'birth_year' => 1984, 'nearest_line' => '山手線']);
        $this->assertSame(2, DB::table('engineer_histories')->where('engineer_id', 1)->count());
        $this->assertDatabaseHas('engineer_certs', ['engineer_id' => 1, 'name' => '基本情報技術者']);
        $this->get('/engineers/1')->assertSee('架空大学 工学部 卒業')->assertSee('データ領域');

        // 書き出し（履歴書・業務経歴書・名前を伏せた版）→ ダウンロード
        foreach ([['kind' => 'resume'], ['kind' => 'careersheet'], ['kind' => 'careersheet', 'anon' => 1]] as $f) $this->post('/engineers/1/docs/generate', $f)->assertRedirect();
        $files = DB::table('engineer_files')->where('engineer_id', 1)->get();
        $this->assertCount(3, $files);
        foreach ($files as $f) {
            Storage::disk('local')->assertExists($f->path);
            $res = $this->get("/engineers/1/docs/{$f->id}")->assertOk();
            $this->assertStringContainsString(rawurlencode($f->filename), $res->headers->get('Content-Disposition')); // 日本語の名前は filename* で送る
        }
        // 中身：業務経歴書に経歴の案件が入り、名前を伏せた版には名前が出ない
        $path = Storage::disk('local')->path($files->firstWhere('title', '業務経歴書（PARKS で書き出し）')->path);
        $text = $this->sheetText($path);
        $this->assertStringContainsString('損害保険会社 契約管理システムの刷新', $text);
        $this->assertStringContainsString('山田 太郎', $text);
        $anon = $this->sheetText(Storage::disk('local')->path($files->firstWhere('title', '業務経歴書（名前を伏せた版）（PARKS で書き出し）')->path));
        $this->assertStringNotContainsString('山田 太郎', $anon);
        $this->assertStringNotContainsString('東京都港区', $anon);
        $resume = $this->sheetText(Storage::disk('local')->path($files->firstWhere('kind', 'resume')->path));
        $this->assertStringContainsString('架空システム株式会社 入社', $resume);
        $this->assertStringContainsString('基本情報技術者', $resume);

        // アップロード
        $this->post('/engineers/1/docs/upload', ['file' => UploadedFile::fake()->create('経歴書.pdf', 20, 'application/pdf'), 'kind' => 'careersheet'])->assertRedirect();
        $this->assertSame(4, DB::table('engineer_files')->where('engineer_id', 1)->count());

        // ほかの人の書類は見られない（ほかの会社の SES・ほかのエンジニア）
        $fid = $files->first()->id;
        $this->as('eg2@park.example')->get("/engineers/1/docs/$fid")->assertForbidden();
        $this->as('ses2@park.example')->get("/engineers/1/docs/$fid")->assertForbidden();
        $this->as('ses1@park.example')->get("/engineers/1/docs/$fid")->assertOk();
        // 削除
        $this->as('admin@park.example')->post("/engineers/1/docs/$fid/delete")->assertRedirect();
        $this->assertDatabaseMissing('engineer_files', ['id' => $fid]);
    }

    private function sheetText(string $path): string
    {
        $ws = \PhpOffice\PhpSpreadsheet\IOFactory::load($path)->getActiveSheet();
        $t = '';
        foreach ($ws->toArray() as $row) $t .= implode(' ', array_filter($row, fn ($v) => $v !== null))."\n";
        return $t;
    }
}
