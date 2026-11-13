<?php

namespace Tests\Feature;

use App\Console\Commands\Status;
use App\Http\Controllers\FindController;
use App\Models\User;
use App\Services\Ids;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

// v0.9.0 表示 ID（PLAN.md 7章 T1〜T26）。人・会社は、テストの中で作る架空のものだけ
// 会社：A＝SES1（みどり情報システム）、B＝SES2（あおぞらテック）。エンジニア：EG1＝A、EG2＝B
class V090DisplayIdTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private array $backupsBefore = [];

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const EG1 = 'eg1@park.example';
    private const EG2 = 'eg2@park.example';

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
        // 見本の応募・契約は、選考中・稼働中のものがあるので、まず全部終わったことにする
        DB::table('applications')->update(['status' => 'rejected']);
        DB::table('contracts')->update(['status' => 'ended']);
    }

    protected function tearDown(): void
    {
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    // ---------- 道具 ----------
    private function user(string $email): User
    {
        return User::where('email', $email)->firstOrFail();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    // 8つの対象の ID を全部空にして、数え表を 0 に戻す（作り直しの確かめ用）
    private function wipe(): void
    {
        foreach (Ids::KINDS as [$table, $col]) DB::table($table)->update([$col => null]);
        DB::table('id_counters')->update(['last_no' => 0]);
    }

    private function seq(string $kind, int $n): array
    {
        return $n ? array_map(fn ($i) => Ids::format($kind, $i), range(1, $n)) : [];
    }

    private function mkCase(string $title = '架空の案件V090'): int
    {
        return W::saveCase(['title' => $title, 'price' => 60, 'status' => 'open'], $this->user(self::ADMIN));
    }

    // 本登録が済んだ形のエンジニア（アカウント付き）を、指定の会社に作る
    private function mkEgIn(int $sid, string $name, string $email): int
    {
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
        return $id;
    }

    private function code(string $table, int $id, string $col = 'code'): ?string
    {
        return DB::table($table)->where('id', $id)->value($col);
    }

    private function draftInvoice(int $sesId, string $period): int
    {
        return (int) DB::table('invoices')->insertGetId(['ses_id' => $sesId, 'period' => $period, 'items_json' => '[]', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'status' => 'draft', 'created_at' => now_str()]);
    }

    private function notFound(string $as, string $code, ?string $acting = null): void
    {
        $this->as($as, $acting)->get('/find?code='.urlencode($code))->assertRedirect()->assertSessionHas('flash.msg', FindController::NOT_FOUND);
    }

    // ---------- 段階1：土台 ----------
    // T1：続けて取ると、1 から順に付く
    public function test_t1_next_is_sequential(): void
    {
        DB::table('id_counters')->where('kind', 'C')->update(['last_no' => 0]);
        $this->assertSame(['C-000001', 'C-000002', 'C-000003'], [Ids::next('C'), Ids::next('C'), Ids::next('C')]);
    }

    // T2：100万件を超えたら、桁が増えるだけ
    public function test_t2_format_grows(): void
    {
        $this->assertSame('C-1234567', Ids::format('C', 1234567));
        $this->assertSame('AP-000005', Ids::format('AP', 5));
    }

    // T3：入力のゆれ（全角・小文字・前後の空白・ゼロ埋めやハイフンの省略）
    public function test_t3_normalize(): void
    {
        foreach (['c12', 'Ｃ－１２', ' C-000012 ', 'C12', 'c-12'] as $in) $this->assertSame('C-000012', Ids::normalize($in), $in);
        $this->assertSame('AP-000005', Ids::normalize('ap5'));
        $this->assertSame('CT-000007', Ids::normalize('ct-7'));
        $this->assertSame('IV-000001', Ids::normalize('iv1'));
        foreach (['X-1', 'C-', '', 'C-0', 'abc', 'C-12x'] as $bad) $this->assertNull(Ids::normalize($bad), $bad);
        $this->assertNull(Ids::normalize(null));
    }

    // T4：空の行を、作った順に埋める。下書きの請求書と、エンジニア（eg）の人は空のまま。続けて取ると次の番号
    public function test_t4_fill_missing_in_creation_order(): void
    {
        $a = $this->sesId(self::SES1); $b = $this->sesId(self::SES2);
        $this->mkEgIn($a, '追加 一郎', 'v090-t4@example.test');
        W::createUser('v090-t4-ses@example.test', '追加 担当', 'ses', 'park1234', ['ses_id' => $a]);
        W::createUser('v090-t4-adm@example.test', '追加 管理', 'admin', 'park1234');
        $this->wipe();
        $late = (int) DB::table('invoices')->insertGetId(['ses_id' => $a, 'period' => '2026-01', 'items_json' => '[]', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'status' => 'issued', 'issued_at' => '2026-02-05 10:00:00', 'created_at' => now_str()]);
        $early = (int) DB::table('invoices')->insertGetId(['ses_id' => $b, 'period' => '2026-01', 'items_json' => '[]', 'subtotal' => 0, 'tax' => 0, 'total' => 0, 'status' => 'paid', 'issued_at' => '2026-02-02 10:00:00', 'created_at' => now_str()]);
        $draft = $this->draftInvoice($a, '2026-02');

        $counts = Ids::fillMissing(false); // 数えるだけ。何も変わらない
        $this->assertSame(2, $counts['IV']);
        $this->assertNull($this->code('invoices', $early));

        $counts = Ids::fillMissing(true);
        foreach (['S', 'E', 'C', 'AP', 'CT'] as $k) {
            [$t] = Ids::KINDS[$k];
            $this->assertSame($this->seq($k, DB::table($t)->count()), DB::table($t)->orderBy('id')->pluck('code')->all(), $k);
        }
        $this->assertSame($this->seq('A', DB::table('users')->where('role', 'admin')->count()), DB::table('users')->where('role', 'admin')->orderBy('id')->pluck('admin_code')->all());
        $this->assertSame($this->seq('T', DB::table('users')->where('role', 'ses')->count()), DB::table('users')->where('role', 'ses')->orderBy('id')->pluck('staff_code')->all());
        $this->assertSame(0, DB::table('users')->where('role', 'eg')->where(fn ($q) => $q->whereNotNull('admin_code')->orWhereNotNull('staff_code'))->count());
        $this->assertSame('IV-000001', $this->code('invoices', $early)); // 発行が早いほうが先
        $this->assertSame('IV-000002', $this->code('invoices', $late));
        $this->assertNull($this->code('invoices', $draft));
        $this->assertSame($this->seq('E', DB::table('engineers')->count() + 1)[DB::table('engineers')->count()], Ids::next('E'));
    }

    // T5：2回流しても、何も変わらない。マイグレーションをもう一度流しても落ちない・変わらない
    public function test_t5_idempotent(): void
    {
        $before = DB::table('engineers')->orderBy('id')->pluck('code')->all();
        $this->assertSame(0, array_sum(Ids::fillMissing(true)));
        $this->assertSame(0, array_sum(Ids::fillMissing(true)));
        $m = require database_path('migrations/2026_11_13_000003_display_ids.php');
        $m->up();
        $m->up();
        $this->assertSame($before, DB::table('engineers')->orderBy('id')->pluck('code')->all());
        $this->assertSame(8, DB::table('id_counters')->count());
        // 数え表が付いている最大の番号より小さいときは、大きいほうにそろえる
        DB::table('id_counters')->where('kind', 'E')->update(['last_no' => 0]);
        Ids::fillMissing(true);
        $max = (int) substr((string) DB::table('engineers')->max('code'), 2);
        $this->assertSame($max, (int) DB::table('id_counters')->where('kind', 'E')->value('last_no'));
    }

    // T6：消した行の番号は、使い直さない
    public function test_t6_deleted_numbers_not_reused(): void
    {
        $a = $this->sesId(self::SES1);
        $id1 = W::createEngineer($a, ['name' => '消す 一郎'], $this->user(self::ADMIN), false);
        $code1 = $this->code('engineers', $id1);
        DB::table('engineers')->where('id', $id1)->delete();
        $id2 = W::createEngineer($a, ['name' => '残す 二郎'], $this->user(self::ADMIN), false);
        $code2 = $this->code('engineers', $id2);
        $this->assertNotSame($code1, $code2);
        $this->assertGreaterThan((int) substr($code1, 2), (int) substr($code2, 2));
    }

    // ---------- 段階2：作る経路のすべてで付く ----------
    // T7：管理者が画面からエンジニアを仮登録 → E-。本人の users（eg）には人の ID を付けない
    public function test_t7_provisional_engineer(): void
    {
        $this->as(self::ADMIN)->post('/engineers', ['ses_id' => $this->sesId(self::SES1), 'name_sei' => '試験', 'name_mei' => '一郎', 'email' => 'v090-e@example.test'])->assertSessionHas('flash.type', 'ok');
        $u = DB::table('users')->where('email', 'v090-e@example.test')->first();
        $this->assertMatchesRegularExpression('/^E-\d{6}$/', (string) $this->code('engineers', (int) $u->engineer_id));
        $this->assertNull($u->admin_code);
        $this->assertNull($u->staff_code);
    }

    // T8：SES が案件を登録 → C-。編集の保存で変わらない
    public function test_t8_case_code_stays(): void
    {
        $this->as(self::SES1)->post('/cases', ['title' => '架空の案件T8', 'price' => 60, 'status' => 'open'])->assertRedirect();
        $id = (int) DB::table('cases')->where('title', '架空の案件T8')->value('id');
        $code = $this->code('cases', $id);
        $this->assertMatchesRegularExpression('/^C-\d{6}$/', (string) $code);
        $this->post("/cases/$id", ['title' => '架空の案件T8（直し）', 'price' => 65, 'status' => 'open'])->assertRedirect();
        $this->assertSame($code, $this->code('cases', $id));
    }

    // T9：応募 → AP-、成約 → CT-
    public function test_t9_application_and_contract(): void
    {
        $cid = $this->mkCase();
        $eid = $this->engId(self::EG1);
        $this->as(self::SES1)->post("/cases/$cid/apply", ['engineer_id' => $eid, 'start_ok' => 1, 'price_ok' => 1, 'style_ok' => 1])->assertRedirect();
        $app = (int) DB::table('applications')->where('case_id', $cid)->value('id');
        $this->assertMatchesRegularExpression('/^AP-\d{6}$/', (string) $this->code('applications', $app));
        $this->as(self::ADMIN)->post("/applications/$app/contract", ['price' => 68, 'start_date' => date('Y-m-d')])->assertRedirect();
        $this->assertMatchesRegularExpression('/^CT-\d{6}$/', (string) DB::table('contracts')->where('application_id', $app)->value('code'));
    }

    // T10：参加の申し込み・管理者が SES を仮登録・管理者を仮登録
    public function test_t10_company_and_people(): void
    {
        Mail::fake();
        $this->post('/apply', ['name' => 'V090申込商事', 'email' => 'v090-apply@example.test', 'contact_sei' => '申込', 'contact_mei' => '花子', 'agree' => '1'])->assertOk();
        $this->assertMatchesRegularExpression('/^S-\d{6}$/', (string) DB::table('ses_companies')->where('name', 'V090申込商事')->value('code'));

        $this->as(self::ADMIN)->post('/admin/ses', ['name' => 'V090仮登録商事', 'rep_sei' => '試験', 'rep_mei' => '太郎', 'email' => 'v090-ses@example.test'])->assertRedirect();
        $s = DB::table('ses_companies')->where('name', 'V090仮登録商事')->first();
        $this->assertMatchesRegularExpression('/^S-\d{6}$/', (string) $s->code);
        $rep = DB::table('users')->where('id', $s->rep_user_id)->first();
        $this->assertMatchesRegularExpression('/^T-\d{6}$/', (string) $rep->staff_code);
        $this->assertNull($rep->admin_code);

        $this->post('/admin/admins', ['company_name' => 'V090企業', 'name_sei' => '試験', 'name_mei' => '次郎', 'email' => 'v090-adm@example.test'])->assertRedirect();
        $adm = DB::table('users')->where('email', 'v090-adm@example.test')->first();
        $this->assertMatchesRegularExpression('/^A-\d{6}$/', (string) $adm->admin_code);
        $this->assertNull($adm->staff_code);
    }

    // T11：エンジニアに担当者の役割を足すと T-。外して戻し、もう一度足すと同じ T-
    public function test_t11_role_codes_are_kept(): void
    {
        $a = $this->sesId(self::SES1);
        $eid = $this->mkEgIn($a, '兼任 花子', 'v090-dual@example.test');
        $uid = (int) DB::table('users')->where('email', 'v090-dual@example.test')->value('id');
        $this->assertNull($this->code('users', $uid, 'staff_code'));
        W::addSesRole($uid, $this->user(self::ADMIN));
        $t = $this->code('users', $uid, 'staff_code');
        $this->assertMatchesRegularExpression('/^T-\d{6}$/', (string) $t);
        $this->assertSame('ses', $this->code('users', $uid, 'role'));
        $res = W::leaveSesUser($a, $uid, $this->user(self::ADMIN)); // 担当者の役割だけ外す（エンジニアとしては残る）
        $this->assertSame('ok', $res['type']);
        $this->assertSame('eg', $this->code('users', $uid, 'role'));
        $this->assertSame($t, $this->code('users', $uid, 'staff_code'));
        W::addSesRole($uid, $this->user(self::ADMIN));
        $this->assertSame($t, $this->code('users', $uid, 'staff_code'));
        // 管理者の役割を足すと A-（T- も残る）
        W::addAdminRole($uid, [], $this->user(self::ADMIN));
        $this->assertMatchesRegularExpression('/^A-\d{6}$/', (string) $this->code('users', $uid, 'admin_code'));
        $this->assertSame($t, $this->code('users', $uid, 'staff_code'));
        $this->assertNotNull($this->code('engineers', $eid));
    }

    // T12：下書きには番号が無い。発行で IV-。もう一度発行しても、番号もお知らせも増えない
    public function test_t12_invoice_code_at_issue(): void
    {
        $a = $this->sesId(self::SES1);
        // 請求書の作成（下書き）：契約から作る
        $cid = $this->mkCase();
        DB::table('contracts')->insert(['case_id' => $cid, 'engineer_id' => $this->engId(self::EG1), 'ses_id' => $a, 'price' => 60, 'start_date' => today_str(), 'status' => 'active', 'created_at' => now_str()]);
        $this->as(self::ADMIN)->post('/admin/invoices/build', ['period' => this_month()])->assertRedirect();
        $inv = DB::table('invoices')->where('ses_id', $a)->where('period', this_month())->first();
        $this->assertNotNull($inv);
        $this->assertNull($inv->code);

        $this->post("/admin/invoices/{$inv->id}/issue")->assertRedirect();
        $code = $this->code('invoices', (int) $inv->id);
        $this->assertMatchesRegularExpression('/^IV-\d{6}$/', (string) $code);
        $notices = DB::table('app_notifications')->where('title', 'like', '%分のご請求書%')->count();
        $this->assertGreaterThan(0, $notices);
        $this->post("/admin/invoices/{$inv->id}/issue")->assertRedirect()->assertSessionHas('flash.msg', 'すでに発行済みです。');
        $this->assertSame($code, $this->code('invoices', (int) $inv->id));
        $this->assertSame($notices, DB::table('app_notifications')->where('title', 'like', '%分のご請求書%')->count());
    }

    // T13：見本・ダミーを入れても、付け漏れが無い。park:status の「ID の無い行」が 0
    public function test_t13_no_missing_after_seeds(): void
    {
        $this->assertSame(0, array_sum(Ids::missingCounts()));
        Mail::fake();
        W::createUser('v090-real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        DB::table('users')->where('email', 'v090-real-admin@example.com')->update(['profile_completed_at' => now_str(), 'company_name' => '実在運営']);
        \App\Support\SkillCatalog::sync();
        $code = Artisan::call('park:seed-dummy', ['--ses' => 5, '--staff' => 8, '--eg' => 12, '--direct' => 5, '--seed' => 1, '--yes' => true]);
        $this->assertSame(0, $code, Artisan::output());
        $this->assertSame(0, array_sum(Ids::missingCounts()));
        Artisan::call('park:status', ['--json' => true]);
        $j = json_decode(Artisan::output(), true);
        $this->assertSame(0, $j[Status::ID_KEY]);
    }

    // T14：所属の変更・停止・再開で、E- は変わらない
    public function test_t14_engineer_code_stays(): void
    {
        $eid = $this->engId(self::EG1);
        $code = $this->code('engineers', $eid);
        $b = $this->sesId(self::SES2);
        $this->as(self::ADMIN)->post("/engineers/$eid/affiliation", ['ses_id' => $b])->assertRedirect();
        $this->assertSame($b, (int) $this->code('engineers', $eid, 'ses_id'));
        $this->post("/engineers/$eid/suspend")->assertRedirect();
        $this->post("/engineers/$eid/resume")->assertRedirect();
        $this->assertSame($code, $this->code('engineers', $eid));
    }

    // ---------- 段階3：画面に出す・探す ----------
    // T15：管理者の画面に E-・A- が出る
    public function test_t15_admin_sees_codes(): void
    {
        $eid = $this->engId(self::EG1);
        $e = $this->code('engineers', $eid);
        $a = $this->code('users', (int) $this->user(self::ADMIN)->id, 'admin_code');
        $this->as(self::ADMIN);
        $this->get('/admin/engineers')->assertOk()->assertSee($e);
        $this->get("/engineers/$eid")->assertOk()->assertSee($e);
        $this->get('/admin/admins')->assertOk()->assertSee($a);
        $this->get('/account')->assertOk()->assertSee($a);
    }

    // T16：SES の画面に、自社の E-・S-・T-・C- が出る。管理者の A- は出ない
    public function test_t16_ses_sees_own_codes(): void
    {
        $cid = $this->mkCase();
        $adm = $this->code('users', (int) $this->user(self::ADMIN)->id, 'admin_code');
        $u = $this->user(self::SES1);
        $this->as(self::SES1);
        $this->get('/ses/engineers')->assertOk()->assertSee($this->code('engineers', $this->engId(self::EG1)));
        $r = $this->get('/ses/company')->assertOk();
        $r->assertSee($this->code('ses_companies', (int) $u->ses_id))->assertSee($u->staff_code)->assertDontSee($adm);
        $this->get("/cases/$cid")->assertOk()->assertSee($this->code('cases', $cid))->assertDontSee($adm);
    }

    // T17：他社のエンジニアの E- は、SES の案内の画面に出ない
    public function test_t17_other_company_engineer_code_hidden(): void
    {
        $this->as(self::SES1)->post('/cases', ['title' => '架空の案件T17', 'required_skills' => 'Java、SQL', 'price' => 60, 'status' => 'open'])->assertRedirect();
        $cid = (int) DB::table('cases')->where('title', '架空の案件T17')->value('id');
        $other = $this->code('engineers', $this->engId(self::EG2));
        $own = $this->code('engineers', $this->engId(self::EG1));
        $this->get("/cases/$cid/mail?scope=group")->assertOk()->assertSee('あおぞらテック')->assertDontSee($other);
        $this->get("/cases/$cid/mail")->assertOk()->assertSee($own);
    }

    // T17b：エンジニア検索（/searches）の一覧・詳細でも、他社の人の E- は SES に出ない
    public function test_t17b_search_hides_other_company_code(): void
    {
        $this->as(self::SES1)->post('/cases', ['title' => '架空の案件T17b', 'required_skills' => 'Java、SQL', 'price' => 60, 'status' => 'open'])->assertRedirect();
        $cid = (int) DB::table('cases')->where('title', '架空の案件T17b')->value('id');
        $other = $this->code('engineers', $this->engId(self::EG2));
        $own = $this->code('engineers', $this->engId(self::EG1));
        $res = $this->post("/cases/$cid/search", [])->assertRedirect();
        $list = $this->get($res->headers->get('Location'))->assertOk();
        $list->assertDontSee($other);
        // 他社の人を、手で足しても出ない
        $sid = (int) DB::table('case_searches')->where('case_id', $cid)->value('id');
        $this->post("/searches/$sid/add", ['engineer_id' => $this->engId(self::EG2)]);
        $this->get("/searches/$sid")->assertOk()->assertDontSee($other);
        $this->get("/searches/$sid/members/".$this->engId(self::EG2))->assertOk()->assertDontSee($other);
    }

    // 直属の S- を管理者が探すと、直属のエンジニアの一覧へ（SES の詳細は無い）。SES には開けない
    public function test_find_direct_company(): void
    {
        $direct = W::directSesId();
        $code = $this->code('ses_companies', $direct);
        $this->assertMatchesRegularExpression('/^S-\d{6}$/', (string) $code);
        $this->as(self::ADMIN)->get('/find?code='.urlencode($code))->assertRedirect(url('/admin/engineers?ses=direct'));
        $this->get('/admin/engineers?ses=direct')->assertOk();
        $this->notFound(self::SES1, $code);
    }

    // code が配列でも 500 にならない
    public function test_find_array_code(): void
    {
        $this->as(self::ADMIN)->get('/find?code[]=C-1')->assertRedirect()->assertSessionHas('flash.msg', FindController::NOT_FOUND);
    }

    // 他社へ移ったエンジニアの E- は、移る前の会社の SES が見る応募の画面に出ない（管理者には出る）
    public function test_moved_engineer_code_hidden_on_old_application(): void
    {
        $cid = $this->mkCase();
        $eid = $this->engId(self::EG1);
        $app = W::apply($cid, $eid, ['start_ok' => 1], $this->user(self::EG1));
        DB::table('applications')->where('id', $app)->update(['status' => 'rejected']);
        DB::table('engineers')->where('id', $eid)->update(['ses_id' => $this->sesId(self::SES2)]);
        $code = $this->code('engineers', $eid);
        $this->as(self::SES1)->get("/applications/$app")->assertOk()->assertDontSee($code);
        $this->as(self::ADMIN)->get("/applications/$app")->assertOk()->assertSee($code);
    }

    // 入金は、発行済みの請求書だけ。下書きは入金にならない
    public function test_paid_only_when_issued(): void
    {
        $inv = $this->draftInvoice($this->sesId(self::SES1), '2026-06');
        $this->as(self::ADMIN)->post("/admin/invoices/$inv/paid")->assertRedirect()->assertSessionHas('flash.type', 'bad');
        $this->assertSame('draft', $this->code('invoices', $inv, 'status'));
        $this->post("/admin/invoices/$inv/issue")->assertRedirect();
        $this->post("/admin/invoices/$inv/paid")->assertRedirect()->assertSessionHas('flash.type', 'ok');
        $this->assertSame('paid', $this->code('invoices', $inv, 'status'));
    }

    // park:status は、ID の表が無くても落ちない
    public function test_status_without_counters_table(): void
    {
        \Illuminate\Support\Facades\Schema::drop('id_counters');
        Artisan::call('park:status', ['--json' => true]);
        $j = json_decode(Artisan::output(), true);
        $this->assertSame('未反映', $j[Status::ID_KEY]);
    }

    // T18：エンジニアの画面に、自分の E-・案件の C-・応募の AP-
    public function test_t18_engineer_sees_codes(): void
    {
        $eid = $this->engId(self::EG1);
        $cid = $this->mkCase();
        W::distribute($cid, [$eid], $this->user(self::ADMIN));
        $this->as(self::EG1)->get('/eg')->assertOk()->assertSee($this->code('engineers', $eid));
        $this->get('/eg/offers')->assertOk()->assertSee($this->code('cases', $cid));
        $app = W::apply($cid, $eid, ['start_ok' => 1], $this->user(self::EG1));
        $this->get('/eg/applications')->assertOk()->assertSee($this->code('applications', $app));
        $this->get("/applications/$app")->assertOk()->assertSee($this->code('applications', $app));
    }

    // T19：スキルシートの画面（名前を伏せた版も）に E- が出ない
    public function test_t19_sheet_has_no_code(): void
    {
        $eid = $this->engId(self::EG1);
        $code = $this->code('engineers', $eid);
        $this->as(self::ADMIN);
        $this->get("/engineers/$eid/sheet")->assertOk()->assertDontSee($code);
        $this->get("/engineers/$eid/sheet?anon=1")->assertOk()->assertDontSee($code);
        $this->as(self::EG1)->get("/engineers/$eid/sheet?anon=1")->assertOk()->assertDontSee($code);
    }

    // T20：ID で探す（ゆれ）。無い ID は決まった文
    public function test_t20_find_admin(): void
    {
        $eid = $this->engId(self::EG1);
        $n = (int) substr($this->code('engineers', $eid), 2);
        $this->as(self::ADMIN)->get('/find?code=e'.$n)->assertRedirect(url("/engineers/$eid"));
        $this->get('/find?code='.urlencode('Ｅ－'.$n))->assertRedirect(url("/engineers/$eid"));
        $cid = $this->mkCase();
        $this->get('/find?code='.urlencode($this->code('cases', $cid)))->assertRedirect(url("/cases/$cid"));
        $this->notFound(self::ADMIN, 'C-999999');
        $this->notFound(self::ADMIN, 'でたらめ');
        $this->notFound(self::ADMIN, '');
    }

    // T21：SES が探せないもの（他社の人・他社の請求書・管理者）は、無い ID と同じ文。自社の人は開く
    public function test_t21_find_ses(): void
    {
        $e2 = $this->code('engineers', $this->engId(self::EG2));
        $e1id = $this->engId(self::EG1);
        $b = $this->sesId(self::SES2);
        $inv = $this->draftInvoice($b, '2026-03');
        DB::table('invoices')->where('id', $inv)->update(['status' => 'issued', 'issued_at' => now_str()]);
        $ivCode = Ids::assign('IV', $inv);
        $adm = $this->code('users', (int) $this->user(self::ADMIN)->id, 'admin_code');
        $this->notFound(self::SES1, $e2);
        $this->notFound(self::SES1, $ivCode);
        $this->notFound(self::SES1, $adm);
        $this->as(self::SES1)->get('/find?code='.urlencode($this->code('engineers', $e1id)))->assertRedirect(url("/engineers/$e1id"));
        // 自社の会社 ID は自社の画面へ。他社の会社 ID は開けない
        $this->get('/find?code='.urlencode($this->code('ses_companies', $this->sesId(self::SES1))))->assertRedirect(url('/ses/company'));
        $this->notFound(self::SES1, $this->code('ses_companies', $b));
    }

    // T22：エンジニアは、自分の応募だけ。兼任の人は、今の立場で結果が変わる
    public function test_t22_find_engineer_and_dual(): void
    {
        $cid = $this->mkCase();
        $e1 = $this->engId(self::EG1); $e2 = $this->engId(self::EG2);
        $app1 = W::apply($cid, $e1, ['start_ok' => 1], $this->user(self::EG1));
        $app2 = W::apply($cid, $e2, ['start_ok' => 1], $this->user(self::EG2));
        $a = $this->sesId(self::SES1);
        $this->as(self::EG1)->get('/find?code='.urlencode($this->code('applications', $app1)))->assertRedirect(url("/applications/$app1"));
        $this->notFound(self::EG1, $this->code('applications', $app2));
        $this->notFound(self::EG1, $this->code('ses_companies', $a));
        // 兼任（担当者＋エンジニア）：エンジニアの立場では会社 ID は開けず、担当者の立場では自社の画面へ
        $uid = (int) $this->user(self::EG1)->id;
        W::addSesRole($uid, $this->user(self::ADMIN));
        DB::table('users')->where('id', $uid)->update(['secrecy_agreed_at' => now_str(), 'phone' => '03-0000-0004']); // 担当者としての必須項目
        $this->notFound(self::EG1, $this->code('ses_companies', $a), 'eg');
        $this->as(self::EG1, 'ses')->get('/find?code='.urlencode($this->code('ses_companies', $a)))->assertRedirect(url('/ses/company'));
    }

    // ---------- 段階4：メール・請求書・版 ----------
    // T23：お知らせ・メールの題に［ID］が付く（案件の配信・応募・請求書の発行）
    public function test_t23_titles_have_ids(): void
    {
        $eid = $this->engId(self::EG1);
        $cid = $this->mkCase();
        $c = $this->code('cases', $cid);
        W::distribute($cid, [$eid], $this->user(self::ADMIN));
        $this->assertTrue(DB::table('app_notifications')->where('title', 'like', "あなたに合う案件が届きました：%［{$c}］")->exists());
        $this->assertTrue(DB::table('mail_outbox')->where('subject', 'like', "%あなたに合う案件が届きました：%［{$c}］")->exists());
        $app = W::apply($cid, $eid, ['start_ok' => 1], $this->user(self::EG1));
        $ap = $this->code('applications', $app);
        $this->assertTrue(DB::table('app_notifications')->where('title', 'like', "応募がありました：%［{$ap}］")->exists());
        $this->assertTrue(DB::table('mail_outbox')->where('subject', 'like', "%貴社のエンジニアが応募しました：%［{$ap}］")->exists());
        $inv = $this->draftInvoice($this->sesId(self::SES1), '2026-04');
        $this->as(self::ADMIN)->post("/admin/invoices/$inv/issue")->assertRedirect();
        $iv = $this->code('invoices', $inv);
        $this->assertTrue(DB::table('app_notifications')->where('title', "2026-04 分のご請求書［{$iv}］")->exists());
        $this->assertTrue(DB::table('mail_outbox')->where('subject', 'like', "%2026-04 分のご請求書［{$iv}］")->exists());
    }

    // T24：案件の案内の既定の本文に、案件 ID の行がある。送る前の確認では実際の ID に変わる
    public function test_t24_case_mail_body_has_case_id(): void
    {
        $this->as(self::SES1)->post('/cases', ['title' => '架空の案件T24', 'price' => 60, 'status' => 'open'])->assertRedirect();
        $cid = (int) DB::table('cases')->where('title', '架空の案件T24')->value('id');
        $c = $this->code('cases', $cid);
        $this->get("/cases/$cid/mail")->assertOk()->assertSee('■案件 ID：{案件ID}', false);
        $this->post("/cases/$cid/mail/preview", ['mode' => 'same', 'subject' => '件名', 'body' => "{呼び名} さん\n■案件 ID：{案件ID}", 'engineer_id' => [$this->engId(self::EG1)]])
            ->assertOk()->assertSee('■案件 ID：'.$c);
    }

    // T25：請求書に請求書番号。下書きは「発行時に付けます」
    public function test_t25_invoice_page_shows_number(): void
    {
        $inv = $this->draftInvoice($this->sesId(self::SES1), '2026-05');
        $this->as(self::ADMIN)->get("/invoices/$inv")->assertOk()->assertSee('請求書番号')->assertSee('発行時に付けます');
        $this->post("/admin/invoices/$inv/issue")->assertRedirect();
        $iv = $this->code('invoices', $inv);
        $this->get("/invoices/$inv")->assertOk()->assertSee('請求書番号')->assertSee($iv)->assertDontSee('発行時に付けます');
        $this->get('/admin/invoices')->assertOk()->assertSee($iv);
        $this->as(self::SES1)->get("/invoices/$inv")->assertOk()->assertSee($iv);
    }

    // T26：版
    public function test_t26_version(): void
    {
        $this->assertSame('v0.9.0', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.9.0');
    }
}
