<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ids;
use App\Services\Workflow as W;
use App\Support\Requirements;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.21.0「SES・担当者一覧」と、登録順・企業ごとの並び（PLAN-v0210.md の A1〜A18）。名前・アドレスはすべて架空
// 会社：A＝代表・次の代表・一般2人・退会済みの一般・兼任の管理者、B＝代表・一般、C＝申込中（人なし）、D＝退会にした会社（代表・一般・兼任の管理者）
class V0210StaffListTest extends TestCase
{
    use RefreshDatabase;

    // DB の作り直しは、テスト全体で最初の1回だけ（見本データが残る）。このファイルだけで流しても同じ前提になるように入れる
    protected $seed = true;

    private const ADMIN = 'v21admin@park.example';
    private const A_NAME = '架空青葉システム';
    private const B_NAME = '架空紅葉テック';

    private array $ses = [];  // 'a' => 会社の id
    private array $user = []; // 'aRep' => users の id
    private array $eg = [];   // 'a1' => engineers の id

    protected function setUp(): void
    {
        parent::setUp();
        // 見本の会社は人の一覧の対象外（kind≠ses）にして、担当者と会社の数を、ここで作る分だけにする
        DB::table('ses_companies')->update(['kind' => 'direct']);
        $admin = W::createUser(self::ADMIN, '架空 管理者', 'admin', 'park1234');
        $this->completeProfile($admin);

        $this->ses = ['a' => $this->mkSes(self::A_NAME), 'b' => $this->mkSes(self::B_NAME), 'c' => $this->mkSes('架空若葉商会', 'pending'), 'd' => $this->mkSes('架空枯葉工業')];
        // 作った順に ST- が振られる（会社をまたいで交互に作る）
        $this->user['aRep'] = $this->mkStaff('a', 'aRep', '架空 青代表', 'ろ');
        $this->user['bRep'] = $this->mkStaff('b', 'bRep', '架空 紅代表', 'あ');
        $this->user['aGen1'] = $this->mkStaff('a', 'aGen1', '架空 青一般一', 'は');
        $this->user['bGen'] = $this->mkStaff('b', 'bGen', '架空 紅一般', 'い');
        $this->user['aNext'] = $this->mkStaff('a', 'aNext', '架空 青次代表', 'に');
        $this->user['aGen2'] = $this->mkStaff('a', 'aGen2', '架空 青一般二', 'ほ');
        $this->user['aLeft'] = $this->mkStaff('a', 'aLeft', '架空 青去就', 'と');
        $this->user['aDual'] = $this->mkDual('a', 'aDual', '架空 青兼任', 'へ');
        $this->user['dRep'] = $this->mkStaff('d', 'dRep', '架空 枯代表', 'ち');
        $this->user['dGen'] = $this->mkStaff('d', 'dGen', '架空 枯一般', 'り');
        $this->user['dDual'] = $this->mkDual('d', 'dDual', '架空 枯兼任', 'ぬ');

        foreach (['a' => 'aRep', 'b' => 'bRep', 'd' => 'dRep'] as $s => $rep) DB::table('ses_companies')->where('id', $this->ses[$s])->update(['rep_user_id' => $this->user[$rep]]);
        DB::table('ses_companies')->where('id', $this->ses['a'])->update(['next_rep_user_id' => $this->user['aNext']]);
        // 次の代表は本登録の前
        DB::table('users')->where('id', $this->user['aNext'])->update(['registered_at' => null, 'token' => 'v21token', 'token_expires' => date('Y-m-d H:i:s', time() + 86400)]);
        DB::table('users')->where('id', $this->user['aLeft'])->update(['active' => 0, 'left_at' => now_str()]);
        $this->as(self::ADMIN)->post("/admin/ses/{$this->ses['d']}/delete");

        // EG- も作った順
        $this->eg['a1'] = $this->mkEg('a', '架空 青社員一', 'ろ');
        $this->eg['b1'] = $this->mkEg('b', '架空 紅社員一', 'は');
        $this->eg['a2'] = $this->mkEg('a', '架空 青社員二', 'い');
        $this->eg['b2'] = $this->mkEg('b', '架空 紅社員二', 'あ');
    }

    // ---------- 下ごしらえ ----------

    private function as(string $email): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function mail(string $key): string
    {
        return 'v21'.strtolower($key).'@park.example';
    }

    private function mkSes(string $name, string $status = 'active'): int
    {
        return (int) DB::table('ses_companies')->insertGetId(['name' => $name, 'kind' => 'ses', 'status' => $status, 'code' => Ids::next('S'),
            'phone' => '03-0000-0000', 'postal_code' => '100-0001', 'address' => '東京都（テスト）',
            'agreed_at' => now_str(), 'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    private function mkStaff(string $ses, string $key, string $name, string $kana): int
    {
        $id = W::createUser($this->mail($key), $name, 'ses', 'park1234', ['ses_id' => $this->ses[$ses]]);
        $this->completeProfile($id);
        DB::table('users')->where('id', $id)->update(['kana' => $kana]);
        return $id;
    }

    private function mkDual(string $ses, string $key, string $name, string $kana): int
    {
        $id = W::createUser($this->mail($key), $name, 'admin', 'park1234', ['ses_id' => $this->ses[$ses]]);
        $this->completeProfile($id);
        DB::table('users')->where('id', $id)->update(['kana' => $kana, 'staff_code' => Ids::next('T')]);
        return $id;
    }

    private function mkEg(string $ses, string $name, string $kana): int
    {
        $id = W::createEngineer($this->ses[$ses], ['name' => $name, 'email' => 'v21eg'.(count($this->eg) + 1).'@park.example'], User::where('email', self::ADMIN)->firstOrFail(), false);
        DB::table('engineers')->where('id', $id)->update(['kana' => $kana, 'consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        return $id;
    }

    private function st(string $key): string
    {
        return (string) DB::table('users')->where('id', $this->user[$key])->value('staff_code');
    }

    private function sts(string ...$keys): array
    {
        return array_map(fn ($k) => $this->st($k), $keys);
    }

    private function egs(string ...$keys): array
    {
        return array_map(fn ($k) => (string) DB::table('engineers')->where('id', $this->eg[$k])->value('code'), $keys);
    }

    private function se(string $key): string
    {
        return (string) DB::table('ses_companies')->where('id', $this->ses[$key])->value('code');
    }

    private function page(string $as, string $url): string
    {
        return $this->as($as)->get($url)->assertOk()->getContent();
    }

    // 表の行（<tr>〜</tr>）を上から順に見て、各行の最初の ID（$prefix）を並べる。エンジニアは、ここで作った人だけ（見本の人は除く）
    private function codes(string $html, string $prefix): array
    {
        preg_match_all('#<tr[ >].*?</tr>#s', $html, $m);
        $out = [];
        foreach ($m[0] as $tr) if (preg_match('#'.$prefix.'-\d{6}#', $tr, $c)) $out[] = $c[0];
        return $prefix === 'EG' ? array_values(array_intersect($out, $this->egs(...array_keys($this->eg)))) : $out;
    }

    // $needle を含む行。無ければ null
    private function row(string $html, string $needle): ?string
    {
        preg_match_all('#<tr[ >].*?</tr>#s', $html, $m);
        foreach ($m[0] as $tr) if (str_contains($tr, $needle)) return $tr;
        return null;
    }

    // ---------- 段階1：ヘルパー ----------

    public function test_helpers(): void
    {
        $this->assertSame('SES代表担当者', staff_kind(DB::table('users')->find($this->user['aRep'])));
        $this->assertSame('一般担当者', staff_kind(DB::table('users')->find($this->user['aNext'])));
        $this->assertSame('一般担当者', staff_kind(DB::table('users')->find($this->user['aDual'])));

        $rows = collect([(object) ['id' => 3, 'code' => null], (object) ['id' => 2, 'code' => 'EG-000002'], (object) ['id' => 1, 'code' => 'EG-000010']]);
        $this->assertSame([2, 1, 3], sort_reg($rows, 'code')->pluck('id')->all());

        $this->assertTrue(is_left_row('person', (object) ['left_at' => null, 'ses_status' => 'left']));
        $this->assertTrue(is_left_row('person', (object) ['left_at' => now_str(), 'ses_status' => 'active']));
        $this->assertFalse(is_left_row('person', (object) ['left_at' => null, 'ses_status' => 'suspended']));

        $this->assertSame('/p?view=company&order=kana&kind=rep', list_back('/p', 'view=company&order=kana&kind=rep&x=1'));
        $this->assertSame('担当者', lbl('role', 'ses'));
    }

    // ---------- A1〜A6：メニュー・人の表示・会社の表示 ----------

    public function test_menu_and_people_list(): void
    {
        $this->as(self::ADMIN)->get('/admin')->assertOk()->assertSee('>SES・担当者</a>', false)->assertDontSee('SES一覧');

        $a = $this->ses['a'];
        $html = $this->page(self::ADMIN, '/admin/ses');
        $this->assertStringContainsString('<h1>SES・担当者一覧</h1>', $html);
        $this->assertCount(7, $this->codes($html, 'ST'));

        $rep = $this->row($html, $this->st('aRep'));
        $this->assertStringContainsString('<td>SES代表担当者', $rep);
        $this->assertStringContainsString($this->se('a'), $rep);
        $this->assertStringContainsString(self::A_NAME, $rep);
        $this->assertStringContainsString('href="'.url("/admin/ses/$a/users/{$this->user['aRep']}").'"', $rep);
        $this->assertStringContainsString('href="'.url("/admin/ses/$a").'"', $rep);
        $this->assertStringContainsString('<td>一般担当者', $this->row($html, $this->st('aGen1')));
        $next = $this->row($html, $this->st('aNext'));
        $this->assertStringContainsString('<td>一般担当者', $next);
        $this->assertStringContainsString('次のSES代表担当者', $next);
        $dual = $this->row($html, $this->st('aDual'));
        $this->assertStringContainsString('管理者（兼任）', $dual);
        $this->assertStringContainsString('href="'.url("/admin/admins/{$this->user['aDual']}").'"', $dual);

        // 担当者がまだいない会社は数だけ（C の1社）。退会済みを表示しても同じ
        $this->assertStringContainsString('担当者がまだいない会社が 1 社あります。', $html);
        $this->assertStringNotContainsString('架空若葉商会', $html);
        $this->assertStringContainsString('担当者がまだいない会社が 1 社あります。', $this->page(self::ADMIN, '/admin/ses?left=1'));
    }

    public function test_company_view(): void
    {
        $a = $this->ses['a'];
        $b = $this->ses['b'];
        $this->as(self::ADMIN)->get('/admin/ses?view=company')->assertOk()->assertSee('SES を仮登録')->assertSee('SES代表担当者')
            ->assertSee(self::A_NAME)->assertSee(self::B_NAME)->assertSee('架空若葉商会')
            ->assertSee("/admin/ses/$a/suspend", false)->assertSee("/admin/ses/$b/delete", false);
        $this->assertStringContainsString($this->st('aRep'), $this->row($this->page(self::ADMIN, '/admin/ses?view=company'), self::A_NAME));
    }

    // ---------- A7：検索・絞り込み ----------

    public function test_search_and_filters(): void
    {
        $html = $this->page(self::ADMIN, '/admin/ses?q='.urlencode('紅葉'));
        $this->assertSame($this->sts('bRep', 'bGen'), $this->codes($html, 'ST'));
        $this->assertSame($this->sts('bRep', 'bGen'), $this->codes($this->page(self::ADMIN, '/admin/ses?q='.$this->se('b')), 'ST'));
        $this->assertSame($this->sts('aRep', 'bRep'), $this->codes($this->page(self::ADMIN, '/admin/ses?kind=rep'), 'ST'));
        $this->assertSame($this->sts('aNext'), $this->codes($this->page(self::ADMIN, '/admin/ses?status=provisional'), 'ST'));
    }

    // ---------- A8：人の並び ----------

    public function test_people_order(): void
    {
        $reg = $this->sts('aRep', 'bRep', 'aGen1', 'bGen', 'aNext', 'aGen2', 'aDual');
        $this->assertSame($reg, $this->codes($this->page(self::ADMIN, '/admin/ses'), 'ST'));
        $this->assertSame($reg, $this->codes($this->page(self::ADMIN, '/admin/ses?order=reg'), 'ST'));
        $this->assertSame($this->sts('aRep', 'aNext', 'aGen1', 'aGen2', 'aDual', 'bRep', 'bGen'), $this->codes($this->page(self::ADMIN, '/admin/ses?order=company'), 'ST'));
        $this->assertSame($this->sts('bRep', 'bGen', 'aNext', 'aGen1', 'aDual', 'aGen2', 'aRep'), $this->codes($this->page(self::ADMIN, '/admin/ses?order=kana'), 'ST'));
    }

    // ---------- A9〜A11：エンジニアの並び ----------

    public function test_engineer_order_and_ids(): void
    {
        $html = $this->page(self::ADMIN, '/admin/engineers');
        $this->assertSame($this->egs('a1', 'b1', 'a2', 'b2'), $this->codes($html, 'EG'));
        $tr = $this->row($html, '架空 青社員一');
        $this->assertStringContainsString($this->se('a'), $tr);
        $this->assertStringContainsString($this->st('aRep'), $tr);
        $this->assertSame($this->egs('a1', 'a2', 'b1', 'b2'), $this->codes($this->page(self::ADMIN, '/admin/engineers?order=company'), 'EG'));
        $this->assertSame($this->egs('b2', 'a2', 'b1', 'a1'), $this->codes($this->page(self::ADMIN, '/admin/engineers?order=kana'), 'EG'));

        $gen = $this->mail('aGen1');
        $html = $this->page($gen, '/ses/engineers');
        $this->assertSame($this->egs('a1', 'a2'), $this->codes($html, 'EG'));
        $this->assertStringContainsString($this->st('aRep'), $this->row($html, '架空 青社員一'));
        $this->assertStringContainsString('value="kana"', $html);
        $this->assertStringNotContainsString('企業ごと', $html);
        $this->assertStringNotContainsString('架空 紅社員', $html);
        $this->assertSame($this->egs('a2', 'a1'), $this->codes($this->page($gen, '/ses/engineers?order=kana'), 'EG'));
        // 並びと退会済みの切り替えは、互いに引き継ぐ
        $this->assertSame($this->egs('a2', 'a1'), $this->codes($this->page($gen, '/ses/engineers?order=kana&left=1'), 'EG'));
    }

    // ---------- A12：1社の表の並び ----------

    public function test_one_company_tables(): void
    {
        $screens = [[self::ADMIN, "/admin/ses/{$this->ses['a']}"], [$this->mail('aRep'), '/ses/company']];
        foreach ($screens as [$who, $url]) {
            $html = $this->page($who, $url);
            $this->assertSame($this->sts('aRep', 'aNext', 'aGen1', 'aGen2', 'aDual'), $this->codes($html, 'ST'), $url);
            $this->assertSame($this->egs('a1', 'a2'), $this->codes($html, 'EG'), $url);
            $this->assertStringNotContainsString('name="order"', $html, $url);
            $this->assertMatchesRegularExpression('#<dt>SES代表担当者</dt><dd>\s*<span class="code">'.$this->st('aRep').'</span>#', $html, $url);
        }
    }

    // ---------- A13：退会済み ----------

    public function test_left_people(): void
    {
        $hidden = ['架空 枯代表', '架空 枯一般', '架空 枯兼任', '架空 青去就'];
        $html = $this->page(self::ADMIN, '/admin/ses');
        foreach ($hidden as $n) $this->assertStringNotContainsString($n, $html);
        $this->assertStringContainsString('退会済み 4 件は表示していません。', $html);

        $html = $this->page(self::ADMIN, '/admin/ses?left=1');
        foreach ($hidden as $n) $this->assertStringContainsString('class="is-left"', $this->row($html, $n), $n);

        $html = $this->page(self::ADMIN, '/admin/ses?status=left');
        foreach ($hidden as $n) $this->assertNotNull($this->row($html, $n), $n);
        $this->assertCount(4, $this->codes($html, 'ST'));

        $this->assertStringContainsString('退会済み 1 件は表示していません。', $this->page(self::ADMIN, '/admin/ses?q='.urlencode(self::A_NAME)));
    }

    // ---------- A14：引き継ぎ ----------

    public function test_conditions_are_kept(): void
    {
        foreach (range(1, 20) as $i) $this->mkStaff('a', "many$i", "架空 青多数$i", 'わ');

        $q = urlencode(self::A_NAME);
        $this->as(self::ADMIN)->get("/admin/ses?order=company&q=$q")->assertOk()
            ->assertSee("order=company&amp;q=$q&amp;page=2", false)
            ->assertSee("order=company&amp;q=$q&amp;left=1\"", false);

        $this->as(self::ADMIN)->post("/admin/ses/{$this->ses['b']}/suspend", ['back' => 'list', 'back_query' => 'view=company&left=1'])
            ->assertRedirect('/admin/ses?left=1&view=company');
        $this->as(self::ADMIN)->post("/engineers/{$this->eg['a1']}/suspend", ['back' => 'list', 'back_query' => 'order=company'])
            ->assertRedirect('/admin/engineers?order=company');

        // 切り替えのフォームは、表示を引き継ぎ、ページは持ち越さない
        $html = $this->page(self::ADMIN, '/admin/ses?view=company&page=2');
        $this->assertStringContainsString('<input type="hidden" name="view" value="company">', $html);
        $this->assertStringNotContainsString('name="page"', $html);
    }

    // ---------- A15：呼び方 ----------

    public function test_words(): void
    {
        $this->assertSame('SES代表担当者', Requirements::roleName('ses', Requirements::isRep(DB::table('users')->find($this->user['aRep']))));
        $this->assertSame('一般担当者', Requirements::roleName('ses', Requirements::isRep(DB::table('users')->find($this->user['aGen1']))));

        $html = $this->page(self::ADMIN, "/admin/ses/{$this->ses['a']}");
        $this->assertStringContainsString('SES代表担当者の交代', $html);
        $this->assertStringNotContainsString('<span class="badge info">SES</span>', $html);
        $this->assertStringNotContainsString('次の SES', $html);
        $this->assertStringNotContainsString('SES の交代', $html);

        $this->as(self::ADMIN)->post("/admin/ses/{$this->ses['a']}/users", ['name_sei' => '架空', 'name_mei' => '新人', 'email' => 'v21new@park.example'])
            ->assertSessionHas('flash.msg', '一般担当者を仮登録し、仮登録のご案内を送りました。');

        // エンジニアを兼ねる一般担当者：ログインの立場の名前
        DB::table('users')->where('id', $this->user['aGen2'])->update(['engineer_id' => $this->eg['a2']]);
        $this->as($this->mail('aGen2'))->get('/login/role')->assertOk()->assertSee('一般担当者（'.self::A_NAME.'）');

        $this->artisan('park:status')->expectsOutputToContain('SES代表担当者のいない会社')->assertExitCode(0);
    }

    // ---------- A16・A17：権限と個人情報 ----------

    public function test_permissions_and_privacy(): void
    {
        $egUser = W::createUser('v21eguser@park.example', '架空 青社員一', 'eg', 'park1234', ['ses_id' => $this->ses['a'], 'engineer_id' => $this->eg['a1']]);
        $this->completeProfile($egUser);

        foreach ([$this->mail('aRep'), $this->mail('aGen1'), 'v21eguser@park.example'] as $who) {
            foreach (['/admin/ses', '/admin/ses?view=company', '/admin/ses?order=company'] as $url) $this->as($who)->get($url)->assertForbidden();
        }
        $this->as('v21eguser@park.example')->get('/ses/engineers?order=kana')->assertForbidden();

        $html = $this->page($this->mail('aRep'), '/ses/engineers?order=company&left=1');
        foreach (['架空 紅代表', '架空 紅一般', '架空 紅社員一', $this->mail('bRep'), $this->mail('bGen')] as $s) $this->assertStringNotContainsString($s, $html);

        $html = $this->page(self::ADMIN, '/admin/ses');
        foreach (['dRep', 'dGen', 'dDual', 'aLeft'] as $k) $this->assertStringNotContainsString($this->mail($k), $html);
    }

    // ---------- A18：おかしな値 ----------

    public function test_odd_values_are_ignored(): void
    {
        $reg = $this->sts('aRep', 'bRep', 'aGen1', 'bGen', 'aNext', 'aGen2', 'aDual');
        foreach (['view[]=company', 'order=x', 'order[]=reg', 'kind=zzz', 'kind[]=rep', 'status[]=left', 'status=zzz', 'q[]=a', 'view='.str_repeat('a', 500)] as $q) {
            $this->assertSame($reg, $this->codes($this->page(self::ADMIN, "/admin/ses?$q"), 'ST'), $q);
        }
        $this->assertSame($this->egs('a1', 'b1', 'a2', 'b2'), $this->codes($this->page(self::ADMIN, '/admin/engineers?order[]=kana&status[]=left&q[]=a'), 'EG'));
        $this->assertSame($this->egs('a1', 'a2'), $this->codes($this->page($this->mail('aRep'), '/ses/engineers?order=company'), 'EG'));
        $this->page(self::ADMIN, '/admin/ses?view=company&order=kana&order[]=1');
    }

    // ---------- 回帰：会社の詳細の注意と交代の候補は今のまま ----------

    public function test_company_detail_calculations(): void
    {
        $html = $this->page(self::ADMIN, "/admin/ses/{$this->ses['a']}");
        $this->assertStringNotContainsString('使える担当者がいません', $html);
        foreach (['aGen1', 'aGen2'] as $k) $this->assertStringContainsString('<option value="'.$this->user[$k].'"', $html);
        foreach (['aRep', 'aNext', 'aLeft', 'aDual'] as $k) $this->assertStringNotContainsString('<option value="'.$this->user[$k].'"', $html);
    }

    // ---------- 追加：並び×退会済み×検索×ページ送り ----------

    private function many(int $n): array
    {
        $keys = [];
        foreach (range(1, $n) as $i) {
            $this->user["many$i"] = $this->mkStaff('a', "many$i", "架空 青多数$i", 'わ');
            $keys[] = "many$i";
        }
        return $keys;
    }

    public function test_people_pages_continue_in_company_order(): void
    {
        $many = $this->many(20);
        $pages = fn (string $q) => array_merge(
            $this->codes($this->page(self::ADMIN, "/admin/ses?$q"), 'ST'),
            $this->codes($this->page(self::ADMIN, "/admin/ses?$q&page=2"), 'ST'));

        $this->assertSame($this->sts('aRep', 'aNext', 'aGen1', 'aGen2', ...$many, ...['aDual', 'bRep', 'bGen']), $pages('order=company'));
        // 退会済みを表示すると、退会済みの人も同じ並びの中に入る
        $this->assertSame($this->sts('aRep', 'aNext', 'aGen1', 'aGen2', 'aLeft', ...$many, ...['aDual', 'bRep', 'bGen', 'dRep', 'dGen', 'dDual']), $pages('order=company&left=1'));
        // 範囲の外・文字のページは、端のページ
        $this->assertSame($this->codes($this->page(self::ADMIN, '/admin/ses?page=2'), 'ST'), $this->codes($this->page(self::ADMIN, '/admin/ses?page=999'), 'ST'));
        $first = $this->codes($this->page(self::ADMIN, '/admin/ses'), 'ST');
        foreach (['page=0', 'page=-1', 'page=abc', 'left[]=1'] as $q) $this->assertSame($first, $this->codes($this->page(self::ADMIN, "/admin/ses?$q"), 'ST'), $q);
        // 区分・並びを持ったままの「表示する」のリンクは、ページを持ち越さない
        $this->as(self::ADMIN)->get('/admin/ses?order=kana&kind=general&page=2')->assertOk()->assertSee('order=kana&amp;kind=general&amp;left=1"', false);
    }

    public function test_people_filters_combine(): void
    {
        DB::table('users')->where('id', $this->user['aGen1'])->update(['active' => 0]);
        $get = fn (string $q) => $this->codes($this->page(self::ADMIN, "/admin/ses?$q"), 'ST');

        $this->assertSame($this->sts('aGen1'), $get('status=suspended'));
        $this->assertSame($this->sts('aRep', 'bRep', 'bGen', 'aGen2', 'aDual'), $get('status=active'));
        $this->assertSame($this->sts('aGen2', 'aDual'), $get('status=active&kind=general&q='.urlencode(self::A_NAME).'&order=company'));
        $this->assertSame($this->sts('bGen'), $get('q='.strtolower($this->st('bGen'))));
        $this->assertSame($this->sts('bGen'), $get('q='.$this->mail('bGen')));
        $this->assertSame($this->sts('aDual'), $get('q='.urlencode('へ')));

        // 隠した件数は、区分の絞り込みに合うものだけ（代表で隠れるのは D の代表）
        $this->assertStringContainsString('退会済み 1 件は表示していません。', $this->page(self::ADMIN, '/admin/ses?kind=rep'));
        // 当たりが退会済みだけのときは、件数の文だけ（「まだいません」は出さない）
        $html = $this->page(self::ADMIN, '/admin/ses?q='.urlencode('枯葉'));
        $this->assertStringContainsString('退会済み 3 件は表示していません。', $html);
        $this->assertStringNotContainsString('担当者はまだいません', $html);
        // 当たりが無ければ 0 行で、件数の文も出ない
        $html = $this->page(self::ADMIN, '/admin/ses?q=zzzzzz&order=kana');
        $this->assertSame([], $this->codes($html, 'ST'));
        $this->assertStringNotContainsString('件は表示していません', $html);
    }

    public function test_company_without_staff_count_follows_left_switch(): void
    {
        $this->mkSes('架空移管商会', 'left');
        $this->assertStringContainsString('担当者がまだいない会社が 1 社あります。', $this->page(self::ADMIN, '/admin/ses'));
        $html = $this->page(self::ADMIN, '/admin/ses?left=1');
        $this->assertStringContainsString('担当者がまだいない会社が 2 社あります。', $html);
        $this->assertStringContainsString('/admin/ses?view=company&amp;left=1', $html);
        $this->assertStringNotContainsString('架空移管商会', $html);
    }

    public function test_engineer_lists_with_left_search_and_order(): void
    {
        $this->as(self::ADMIN)->post("/engineers/{$this->eg['a2']}/delete");
        $get = fn (string $who, string $url) => $this->codes($this->page($who, $url), 'EG');

        $this->assertSame($this->egs('a1', 'b1', 'b2'), $get(self::ADMIN, '/admin/engineers'));
        $this->assertSame($this->egs('a1', 'b1', 'b2'), $get(self::ADMIN, '/admin/engineers?order=company'));
        $this->assertSame($this->egs('a1', 'a2', 'b1', 'b2'), $get(self::ADMIN, '/admin/engineers?order=company&left=1'));
        $this->assertSame($this->egs('a2'), $get(self::ADMIN, '/admin/engineers?order=company&status=left'));
        $this->assertSame($this->egs('a1'), $get(self::ADMIN, '/admin/engineers?order=kana&q='.$this->se('a')));
        $this->assertStringContainsString('退会済み 1 件は表示していません。', $this->page(self::ADMIN, '/admin/engineers?q='.$this->se('a')));
        $this->assertStringNotContainsString('件は表示していません', $this->page(self::ADMIN, '/admin/engineers?q='.$this->se('b')));

        $gen = $this->mail('aGen1');
        $this->assertSame($this->egs('a1'), $get($gen, '/ses/engineers?order=kana'));
        $this->assertSame($this->egs('a2', 'a1'), $get($gen, '/ses/engineers?order=kana&left=1'));
        $this->assertStringContainsString('order=kana&amp;left=1"', $this->page($gen, '/ses/engineers?order=kana'));
    }

    public function test_ses_engineer_pages_sort_before_slicing(): void
    {
        $keys = [];
        foreach (range(1, 20) as $i) {
            $this->eg["m$i"] = $this->mkEg('a', "架空 青多数社員$i", 'わ');
            $keys[] = "m$i";
        }
        $gen = $this->mail('aGen1');

        // 名前順：い(a2) → ろ(a1) → わ。並べてから 20 件で切る
        $p1 = $this->page($gen, '/ses/engineers?order=kana&left=1');
        $this->assertSame($this->egs('a2', 'a1'), array_slice($this->codes($p1, 'EG'), 0, 2));
        $this->assertStringContainsString('order=kana&amp;left=1&amp;page=2', $p1);
        $this->assertCount(2, $this->codes($this->page($gen, '/ses/engineers?order=kana&left=1&page=2'), 'EG'));
        $this->assertSame($this->egs(...array_slice($keys, 18)), $this->codes($this->page($gen, '/ses/engineers?page=2'), 'EG'));
        // 切り替えのフォームは、ページを持ち越さず、並びを二重に送らない
        $html = $this->page($gen, '/ses/engineers?order=kana&page=2');
        $this->assertStringNotContainsString('name="page"', $html);
        $this->assertSame(1, substr_count($html, 'name="order"'));
    }

    // ---------- 追加：操作のあとの戻り先・二重に押す・他社の操作 ----------

    public function test_back_to_the_same_list_after_actions(): void
    {
        $a1 = $this->eg['a1'];
        $back = ['back' => 'list', 'back_query' => 'order=company&left=1&page=2&evil=https://example.com'];
        // 一覧の行の操作は、今の問い合わせを送る
        $this->assertStringContainsString('name="back_query" value="left=1&amp;order=kana"', $this->page($this->mail('aGen1'), '/ses/engineers?order=kana&left=1'));

        foreach ([self::ADMIN => '/admin/engineers', $this->mail('aGen1') => '/ses/engineers', $this->mail('aRep') => '/ses/engineers'] as $who => $list) {
            foreach (['suspend', 'suspend', 'resume', 'resume'] as $act) { // 二重に押しても同じ戻り先
                $this->as($who)->post("/engineers/$a1/$act", $back)->assertRedirect("$list?page=2&left=1&order=company");
            }
        }
        foreach (range(1, 2) as $i) $this->as(self::ADMIN)->post("/engineers/$a1/delete", $back)->assertRedirect('/admin/engineers?page=2&left=1&order=company');
        // 行が減ってページが無くなっても、開ける
        $this->assertSame($this->egs('b1', 'a2', 'b2'), $this->codes($this->page(self::ADMIN, '/admin/engineers?page=2'), 'EG'));

        foreach (range(1, 2) as $i) {
            $this->as(self::ADMIN)->post("/admin/ses/{$this->ses['b']}/delete", ['back' => 'list', 'back_query' => 'view=company&left=1'])
                ->assertRedirect('/admin/ses?left=1&view=company');
        }
    }

    public function test_others_cannot_operate(): void
    {
        $egUser = W::createUser('v21eguser@park.example', '架空 青社員一', 'eg', 'park1234', ['ses_id' => $this->ses['a'], 'engineer_id' => $this->eg['a1']]);
        $this->completeProfile($egUser);
        $a = $this->ses['a'];
        $back = ['back' => 'list', 'back_query' => 'order=company'];

        foreach ([$this->mail('bRep'), $this->mail('bGen'), 'v21eguser@park.example'] as $who) {
            foreach (['suspend', 'delete'] as $act) {
                $this->assertContains($this->as($who)->post("/engineers/{$this->eg['a1']}/$act", $back)->status(), [403, 404], "$who $act");
                $this->assertContains($this->as($who)->post("/admin/ses/$a/$act", $back)->status(), [403, 404], "$who ses $act");
            }
            $this->assertContains($this->as($who)->get("/engineers/{$this->eg['a2']}/edit")->status(), [403, 404], $who);
        }
        $this->assertNull(DB::table('engineers')->where('id', $this->eg['a1'])->value('suspended_at'));
        $this->assertNotSame('left', DB::table('engineers')->where('id', $this->eg['a1'])->value('status'));
        $this->assertSame('active', DB::table('ses_companies')->where('id', $a)->value('status'));

        // 4つの立場：管理者の画面と人の詳細は管理者だけ
        foreach ([$this->mail('aRep'), $this->mail('aGen1'), 'v21eguser@park.example'] as $who) {
            foreach (['/admin/engineers?order=company&left=1', "/admin/ses/$a", "/admin/ses/$a/users/{$this->user['aGen2']}", "/admin/admins/{$this->user['aDual']}"] as $url) {
                $this->as($who)->get($url)->assertForbidden();
            }
        }
        $this->as(self::ADMIN)->get('/ses/engineers?order=kana')->assertForbidden();
        $this->as('v21eguser@park.example')->get('/ses/company?left=1')->assertForbidden();
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->get('/admin/ses?view=company')->assertRedirect();
    }

    public function test_other_company_ids_and_mails_are_not_shown(): void
    {
        $secret = [$this->se('b'), self::B_NAME, ...$this->sts('bRep', 'bGen'), ...$this->egs('b1', 'b2'),
            $this->mail('bRep'), $this->mail('bGen'), 'v21eg2@park.example', 'v21eg4@park.example', $this->mail('aDual')];
        foreach ([$this->mail('aRep'), $this->mail('aGen1')] as $who) {
            foreach (['/ses/company', '/ses/company?left=1', '/ses/engineers?left=1&order=kana', '/ses/engineers?view=company&order=company'] as $url) {
                $html = $this->page($who, $url);
                foreach ($secret as $s) $this->assertStringNotContainsString($s, $html, "$who $url");
            }
        }
    }

    // ---------- 追加：付け替えの記録の ID の出し分け ----------

    public function test_staff_log_ids_by_viewer(): void
    {
        $a1 = $this->eg['a1'];
        DB::table('engineer_staff_logs')->where('engineer_id', $a1)->delete();
        $admin = (int) User::where('email', self::ADMIN)->value('id');
        $adm = (string) DB::table('users')->where('id', $admin)->value('admin_code');
        $dualAdm = (string) DB::table('users')->where('id', $this->user['aDual'])->value('admin_code');
        $this->assertNotSame('', $adm);
        // 前の会社の人（bGen）→ 自社の人（aGen1）：した人は兼任でない管理者
        \App\Services\EgStaff::log($a1, $this->user['bGen'], $this->user['aGen1'], $admin, 'move');
        // 兼任の管理者 → 自社の人
        \App\Services\EgStaff::log($a1, $this->user['aDual'], $this->user['aRep'], $this->user['aRep'], 'manual');
        $log = fn (string $who) => explode('</table>', explode('付け替えの記録', $this->page($who, "/engineers/$a1/edit"), 2)[1], 2)[0];
        $code = fn (string $c) => '<span class="code">'.$c.'</span>';

        $h = $log(self::ADMIN);
        foreach ([...$this->sts('bGen', 'aGen1', 'aRep', 'aDual'), $adm] as $c) $this->assertStringContainsString($code($c), $h);
        if ($dualAdm !== '') $this->assertStringNotContainsString($dualAdm, $h); // 担当者を兼ねる管理者は ST-

        foreach ([$this->mail('aRep'), $this->mail('aGen1')] as $who) {
            $h = $log($who);
            foreach ($this->sts('aGen1', 'aRep', 'aDual') as $c) $this->assertStringContainsString($code($c), $h, $who);
            $this->assertStringNotContainsString($this->st('bGen'), $h, $who);
            $this->assertStringNotContainsString('AD-', $h, $who);
            $this->assertStringNotContainsString('@park.example', $h, $who);
        }

        // エンジニア本人には、担当者の ID も記録も出ない。管理者・担当者の詳細には ST-
        $egUser = W::createUser('v21eguser@park.example', '架空 青社員一', 'eg', 'park1234', ['ses_id' => $this->ses['a'], 'engineer_id' => $a1]);
        $this->completeProfile($egUser);
        $st = $this->st('aRep');
        foreach ([self::ADMIN, $this->mail('aGen1')] as $who) $this->assertStringContainsString($st, $this->page($who, "/engineers/$a1"), $who);
        $mine = (string) $this->as('v21eguser@park.example')->get("/engineers/$a1")->getContent();
        $this->assertStringNotContainsString('ST-', $mine);
        $edit = (string) $this->as('v21eguser@park.example')->get("/engineers/$a1/edit")->getContent();
        $this->assertStringNotContainsString('付け替えの記録', $edit);
        $this->assertStringNotContainsString('ST-', $edit);
    }

    // ---------- 追加：立場の札の呼び方 ----------

    public function test_role_words_in_header_and_login_choice(): void
    {
        $this->assertStringContainsString('<span class="rolepill">SES代表担当者</span>', $this->page($this->mail('aRep'), '/ses'));
        $this->assertStringContainsString('<span class="rolepill">一般担当者</span>', $this->page($this->mail('aGen1'), '/ses'));
        DB::table('users')->where('id', $this->user['aRep'])->update(['engineer_id' => $this->eg['a1']]);
        $this->as($this->mail('aRep'))->get('/login/role')->assertOk()->assertSee('SES代表担当者（'.self::A_NAME.'）');
    }
}
