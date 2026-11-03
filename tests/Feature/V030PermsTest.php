<?php

namespace Tests\Feature;

use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// v0.3.0 テスト設計書 版 02：A（権限）A-01〜A-09。スキル表と経歴の両方を、データプロバイダで回す。
// 立場は 管理者・SES（代表者）・担当者・エンジニア（EG）の4つ。担当者は ses1 と同じ会社の、SES ではない人
class V030PermsTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';       // みどり情報システム（代表者）
    private const SES2 = 'ses2@park.example';
    private const STAFF = 'mate@example.com';       // ses1 の担当者
    private const EG1 = 'eg1@park.example';         // ses1 の EG
    private const EG2 = 'eg2@park.example';         // ses2 の EG
    private const MARK = 'ZzPermProject';

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
    }

    public static function kinds(): array
    {
        return ['skills' => ['skills'], 'projects' => ['projects']];
    }

    // ---------- 道具 ----------

    private function sesId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('ses_id');
    }

    private function staff(): int
    {
        $id = W::createUser(self::STAFF, '同僚 一郎', 'ses', 'park1234', ['ses_id' => $this->sesId(self::SES1)]);
        $this->completeProfile($id);
        return $id;
    }

    // 本人が確認した状態の、きれいな EG にする
    private function target(int $eid): int
    {
        $this->cleanEg($eid);
        DB::table('engineers')->where('id', $eid)->update(['self_confirmed_at' => now_str()]);
        return $eid;
    }

    private function confirmed(int $eid): bool
    {
        return DB::table('engineers')->where('id', $eid)->value('self_confirmed_at') !== null;
    }

    // 種類（スキル表・経歴）に合わせて、1件足す送信をする
    private function edit(string $kind, int $eid)
    {
        return $kind === 'skills'
            ? $this->post("/engineers/$eid/skills", $this->skillForm([['name' => 'Laravel', 'cat' => 'fw', 'years' => '2']]))
            : $this->post("/engineers/$eid/projects", ['title' => self::MARK, 'tech' => 'Laravel']);
    }

    // 送信した内容が DB に入っているか
    private function changed(string $kind, int $eid): bool
    {
        return $kind === 'skills'
            ? DB::table('engineer_skills')->where('engineer_id', $eid)->exists()
            : DB::table('engineer_projects')->where('engineer_id', $eid)->where('title', self::MARK)->exists();
    }

    // 仮登録中の EG（users.role='eg' で registered_at が空）を、ses1 に作る。[engineer_id, user_id]
    private function provisionalEg(): array
    {
        $eid = (int) DB::table('engineers')->insertGetId(['ses_id' => $this->sesId(self::SES1), 'name' => '仮 登録', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $uid = W::createUser('prov@example.com', '仮 登録', 'eg', 'park1234', ['ses_id' => $this->sesId(self::SES1), 'engineer_id' => $eid]);
        DB::table('users')->where('id', $uid)->update(['registered_at' => null, 'password' => null]);
        return [$eid, $uid];
    }

    // ses1 の、まだ誰とも結びついていない EG（高橋）を、$email の人の「エンジニアとしての自分」にする（兼任）
    private function makeDual(string $email): int
    {
        $eid = (int) DB::table('engineers')->where('name', '高橋 陽介')->value('id');
        DB::table('engineers')->where('id', $eid)->update(['kana_sei' => 'たかはし', 'kana_mei' => 'ようすけ', 'phone' => '090-0000-0001', 'postal_code' => '100-0001',
            'address' => '東京都（テスト）', 'consent_at' => now_str(), 'registered_at' => now_str()]);
        DB::table('users')->where('email', $email)->update(['engineer_id' => $eid]);
        return $this->target($eid);
    }

    // ---------- A-01〜A-04：直せる人 ----------

    #[DataProvider('kinds')]
    public function test_a_01_eg_edits_himself(string $kind): void
    {
        $eid = $this->target($this->engId(self::EG1));
        $this->as(self::EG1);
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertTrue($this->confirmed($eid), '本人が直したときは「確認しました」が残る');
    }

    #[DataProvider('kinds')]
    public function test_a_02_admin_edits_ses_eg_and_direct_eg(string $kind): void
    {
        $eid = $this->target($this->engId(self::EG1));
        $this->as(self::ADMIN);
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertFalse($this->confirmed($eid), '上位が直したら、本人の確認はやり直し');

        // 管理者の直属の EG
        $direct = (int) DB::table('engineers')->insertGetId(['ses_id' => W::directSesId(), 'name' => '直属 一郎', 'registered_at' => now_str(), 'self_confirmed_at' => now_str(),
            'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->as(self::ADMIN);
        $this->edit($kind, $direct)->assertRedirect();
        $this->assertTrue($this->changed($kind, $direct));
        $this->assertFalse($this->confirmed($direct));
    }

    #[DataProvider('kinds')]
    public function test_a_03_ses_edits_own_company_eg_even_when_suspended(string $kind): void
    {
        $eid = $this->target($this->engId(self::EG1));
        DB::table('engineers')->where('id', $eid)->update(['suspended_at' => now_str()]);
        $this->as(self::SES1);
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertFalse($this->confirmed($eid));
    }

    #[DataProvider('kinds')]
    public function test_a_04_staff_edits_own_company_eg(string $kind): void
    {
        $this->staff();
        $eid = $this->target($this->engId(self::EG1));
        $this->as(self::STAFF);
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertFalse($this->confirmed($eid));
    }

    // ---------- A-05・A-06：直せない人 ----------

    #[DataProvider('kinds')]
    public function test_a_05_ses_and_staff_other_company(string $kind): void
    {
        $this->staff();
        $eid = $this->target($this->engId(self::EG2)); // ses2 の EG
        foreach ([self::SES1, self::STAFF] as $who) {
            $this->as($who);
            $this->edit($kind, $eid)->assertForbidden();
            $this->assertFalse($this->changed($kind, $eid), $who);
            $this->assertTrue($this->confirmed($eid), $who);
        }
    }

    #[DataProvider('kinds')]
    public function test_a_06_eg_cannot_edit_another_eg(string $kind): void
    {
        $eid = $this->target($this->engId(self::EG2));
        $this->as(self::EG1);
        $this->edit($kind, $eid)->assertForbidden();
        $this->assertFalse($this->changed($kind, $eid));
    }

    // ---------- A-07：仮登録中は直せない ----------

    #[DataProvider('kinds')]
    public function test_a_07_provisional_eg_cannot_be_edited_by_upper_roles(string $kind): void
    {
        $this->staff();
        [$eid, $uid] = $this->provisionalEg();
        foreach ([self::ADMIN, self::SES1, self::STAFF] as $who) {
            $this->as($who);
            $this->edit($kind, $eid)->assertRedirect("/people/$uid/edit");
            $this->assertFalse($this->changed($kind, $eid), $who);
        }
    }

    public function test_a_07_provisional_eg_ai_draft_is_refused(): void
    {
        $this->staff();
        [$eid] = $this->provisionalEg();
        $draft = ['projects' => [['title' => 'AI の下書きの案件', 'period_from' => '2021-04', 'period_to' => '2022-03', 'tech' => 'Laravel', 'phases' => ['実装']]]];
        $jid = DB::table('ai_jobs')->insertGetId(['kind' => 'resume', 'ref_type' => 'engineer_file', 'ref_id' => 1, 'input' => json_encode(['engineer_id' => $eid]),
            'status' => 'done', 'output' => json_encode($draft, JSON_UNESCAPED_UNICODE), 'created_at' => now_str()]);
        foreach ([self::ADMIN, self::SES1, self::STAFF] as $who) {
            $this->as($who)->post("/ai-jobs/$jid/project/0")->assertForbidden();
            $this->assertSame(0, DB::table('engineer_projects')->where('engineer_id', $eid)->count(), $who);
        }
    }

    // ---------- A-08：兼任 ----------

    #[DataProvider('kinds')]
    public function test_a_08_admin_dual_confirmation_depends_on_acting_role(string $kind): void
    {
        $eid = $this->makeDual(self::ADMIN);
        $this->as(self::ADMIN, 'eg');
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertTrue($this->confirmed($eid), 'エンジニアの立場で直したら残る');

        $this->cleanEg($eid);
        $this->as(self::ADMIN, 'admin');
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertFalse($this->confirmed($eid), '管理者の立場で直したらやり直し');
    }

    #[DataProvider('kinds')]
    public function test_a_08_staff_dual_confirmation_depends_on_acting_role(string $kind): void
    {
        $this->staff();
        $eid = $this->makeDual(self::STAFF);
        $this->as(self::STAFF, 'eg');
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertTrue($this->confirmed($eid));

        $this->cleanEg($eid);
        $this->as(self::STAFF, 'ses');
        $this->edit($kind, $eid)->assertRedirect();
        $this->assertTrue($this->changed($kind, $eid));
        $this->assertFalse($this->confirmed($eid));
    }

    // ---------- A-09：技術マスタは管理者だけ ----------

    public function test_a_09_master_is_admin_only(): void
    {
        $this->staff();
        $from = (int) DB::table('skill_terms')->where('name', 'Docker')->value('id');
        $to = (int) DB::table('skill_terms')->where('name', 'Laravel')->value('id');
        foreach ([self::SES1, self::STAFF, self::EG1] as $who) {
            $this->as($who);
            $this->get('/admin/skills')->assertForbidden();
            $this->post("/admin/skills/$from/merge", ['to' => $to])->assertForbidden();
            $this->assertNotNull(DB::table('skill_terms')->find($from), $who);
        }
        $this->as(self::ADMIN)->get('/admin/skills')->assertOk();
    }
}
