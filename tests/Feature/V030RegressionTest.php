<?php

namespace Tests\Feature;

use App\Services\Matcher;
use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\SkillCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.3.0 テスト設計書 版 02：R（今の機能が壊れていないこと）R-01・R-02・R-04〜R-07。
// R-03 は EngineerDetailTest・Q&A のテスト、R-08 は全体のテストで見る
class V030RegressionTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const EG2 = 'eg2@park.example';

    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        SkillCatalog::forget();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        // このテストで作ったバックアップは消す
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    private function admin(): object
    {
        return DB::table('users')->where('email', self::ADMIN)->first();
    }

    // 候補に出る形にする（待機中・本登録済み・いつでも動ける・働き方は問わない）
    private function available(int $eid): void
    {
        DB::table('engineers')->where('id', $eid)->update(['status' => 'available', 'available_from' => null, 'work_style' => 'any']);
    }

    // ---------- R-01：点数の計算は変わらない ----------

    public function test_r_01_score_is_unchanged(): void
    {
        $eid = $this->engId(self::EG2);
        $this->cleanEg($eid);
        $this->available($eid);
        W::saveSkills($eid, $this->checkForm(['lang/PHP' => '3年以上']));
        $this->makeProject($eid, ['tech' => 'Laravel']); // 経歴の文字列にだけある技術（0.8 と数える）
        $cid = W::saveCase(['title' => 'R-01 の案件', 'required_skills' => 'Laravel、PHP', 'work_style' => 'any', 'status' => 'open'], $this->admin());

        $c = DB::table('cases')->find($cid);
        $e = DB::table('engineers')->find($eid);
        // 55 × 1.8 ÷ 2 ＋ 尚可なし 7.5 ＋ 年数なし 8 ＋ 単価なし 5 ＋ 開始日なし 6 ＋ 働き方 6 ＝ 82
        $this->assertSame(82, Matcher::score($c, $e)['score']);
        $this->assertSame(82, Matcher::score($c, $e, Matcher::skillIndex($eid))['score'], 'まとめて読んだ索引でも同じ');

        $hit = collect(Matcher::forCase($cid))->first(fn ($r) => (int) $r['engineer']->id === $eid);
        $this->assertNotNull($hit);
        $this->assertSame(82, $hit['score']);
    }

    // ---------- R-02：問い合わせの数が人数で増えない ----------

    public function test_r_02_query_count_does_not_grow_with_people(): void
    {
        $ids = [$this->engId('eg1@park.example'), $this->engId('eg2@park.example'), $this->engId('eg3@park.example')];
        $fourth = (int) DB::table('engineers')->where('name', '高橋 陽介')->value('id');
        // 候補に出るのは、ここで決めた人だけにする
        DB::table('engineers')->whereNotIn('id', array_merge($ids, [$fourth]))->update(['status' => 'unavailable']);
        foreach (array_merge($ids, [$fourth]) as $id) {
            $this->available($id);
            $this->makeProject($id, ['tech' => 'Laravel、Docker', 'period_from' => '2024-01', 'period_to' => '2024-06']);
        }
        DB::table('engineers')->where('id', $fourth)->update(['status' => 'unavailable']); // まず3人
        $cid = W::saveCase(['title' => 'R-02 の案件', 'required_skills' => 'Laravel、Docker', 'status' => 'open'], $this->admin());

        Matcher::forCase($cid); // マスタなどの読み込みを先に済ませる
        $count = function () use ($cid): array {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $n = count(Matcher::forCase($cid));
            $q = count(DB::getQueryLog());
            DB::disableQueryLog();
            return [$n, $q];
        };

        [$n3, $q3] = $count();
        $this->available($fourth);
        [$n4, $q4] = $count();
        $this->assertSame(3, $n3);
        $this->assertSame(4, $n4);
        $this->assertSame($q3, $q4, '人数が増えても、問い合わせの数は同じ');
    }

    // ---------- R-04：見本データを消す ----------

    public function test_r_04_purge_samples_removes_project_skill_rows_and_backs_them_up(): void
    {
        // 消したあとに残る、使える管理者
        $aid = W::createUser('real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        $this->completeProfile($aid, ['company_name' => '実在運営']);

        $eid = $this->engId('eg1@park.example');
        $this->makeProject($eid, ['tech' => 'Java、Oracle']);
        $this->assertGreaterThan(0, DB::table('engineer_project_skills')->count());

        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));

        $this->assertSame(0, DB::table('engineer_project_skills')->count());
        $this->assertSame(0, DB::table('engineer_projects')->count());
        $files = array_values(array_diff(glob(storage_path('app/backups/purge-samples-*.json')) ?: [], $this->backupsBefore));
        $this->assertCount(1, $files);
        $json = json_decode(file_get_contents($files[0]), true);
        $this->assertArrayHasKey('engineer_project_skills', $json['表']);
        $this->assertNotEmpty($json['表']['engineer_project_skills']);
    }

    // ---------- R-05：仮登録の取り消し ----------

    private function provisionalEg(string $email): array
    {
        $ses = (int) DB::table('users')->where('email', 'ses1@park.example')->value('ses_id');
        $eid = (int) DB::table('engineers')->insertGetId(['ses_id' => $ses, 'name' => '仮 登録', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $uid = W::createUser($email, '仮 登録', 'eg', 'park1234', ['ses_id' => $ses, 'engineer_id' => $eid]);
        DB::table('users')->where('id', $uid)->update(['registered_at' => null, 'password' => null]);
        return [$eid, $uid];
    }

    public function test_r_05_cancel_provisional_eg_without_tech_data(): void
    {
        [$eid, $uid] = $this->provisionalEg('prov1@example.com');
        $res = Registration::cancel(DB::table('users')->find($uid), $this->admin());
        $this->assertSame('ok', $res['type']);
        $this->assertNull(DB::table('users')->find($uid));
        $this->assertNull(DB::table('engineers')->find($eid), '技術データが無い EG は消える');
        $this->assertSame(0, $this->orphanIndexRows());
    }

    public function test_r_05_cancel_provisional_eg_with_projects(): void
    {
        [$eid, $uid] = $this->provisionalEg('prov2@example.com');
        $pid = $this->makeProject($eid, ['tech' => 'Laravel、Docker']);
        $this->assertGreaterThan(0, DB::table('engineer_project_skills')->where('project_id', $pid)->count());

        $res = Registration::cancel(DB::table('users')->find($uid), $this->admin());
        $this->assertSame('ok', $res['type']);
        $this->assertNull(DB::table('users')->find($uid));
        $this->assertNotNull(DB::table('engineers')->find($eid), '経歴がある EG の技術登録は残る（v0.2.0 のとおり）');
        $this->assertSame(0, $this->orphanIndexRows());
    }

    // 親の経歴が無い索引の行の数
    private function orphanIndexRows(): int
    {
        return DB::table('engineer_project_skills')->whereNotIn('project_id', DB::table('engineer_projects')->select('id'))->count();
    }

    // ---------- R-06・R-07：版とマニュアル ----------

    public function test_r_06_version(): void
    {
        $this->assertSame('v0.10.1', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.10.1');
    }

    public function test_r_07_manuals_describe_the_new_skill_table(): void
    {
        // v0.4.0：チェック式。年数は4段階で、レベル・得意・ひとこと・最後に使った年月の説明は無い
        $page = $this->get('/manual/engineer')->assertOk();
        foreach (['1年未満', '1年以上', '3年以上', '5年以上', '経験あり', '「その他」', '印は保存されません', 'あなたのスキルに無い必須'] as $text) $page->assertSee($text, false);
        foreach (['最後に使った年月', 'レベルの目安', '得意な技術'] as $text) $page->assertDontSee($text);

        $this->get('/manual/engineer-steps')->assertOk()->assertSee('すべて開く')->assertSee('その他')->assertDontSee('最後に使った年月');
        $this->get('/manual/ses')->assertOk()->assertSee('確認しました')->assertSee('仮登録中');
        $this->get('/manual/ses-steps')->assertOk()->assertSee('確認しました');

        $this->as(self::ADMIN);
        $this->get('/manual/admin')->assertOk()->assertSee('使っている人（スキル表・経歴）')->assertSee('チェック式');
        $this->get('/manual/admin-steps')->assertOk()->assertSee('経歴の索引');
    }
}
