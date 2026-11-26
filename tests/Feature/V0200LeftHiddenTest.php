<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Ids;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.20.0 退会済みの SES・担当者・エンジニアを一覧でグレーにし、既定では隠す（PLAN-v0200.md の A1〜A15）。名前・アドレスはすべて架空
// 会社：A＝見本の SES1、B＝見本の SES2、C＝退会にした会社、D＝付け替え元の形（退会済みで人がいない）
class V0200LeftHiddenTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const STAFF = 'v20staff@park.example';  // A の担当者（代表でない）
    private const GONE = 'v20gone@park.example';    // A の退会済みの担当者

    private int $a;
    private int $b;
    private int $c;
    private int $d;
    private int $staff;
    private int $gone;
    private int $dual;
    private int $egOk;
    private int $egLeft;     // A の退会済み（ログインあり）
    private int $egNoLogin;  // A の退会済み（ログインなし）
    private int $egOther;    // B の退会済み

    protected function setUp(): void
    {
        parent::setUp();
        $this->a = $this->sesIdOf(self::SES1);
        $this->b = $this->sesIdOf('ses2@park.example');

        $this->staff = $this->mkStaff($this->a, self::STAFF, '架空 現役担当');
        $this->gone = $this->mkStaff($this->a, self::GONE, '架空 去就担当', true);
        $this->dual = W::createUser('v20dual@park.example', '架空 兼任退会', 'admin', 'park1234', ['ses_id' => $this->a]);
        DB::table('users')->where('id', $this->dual)->update(['active' => 0, 'left_at' => now_str(), 'staff_code' => Ids::next('T')]);

        $this->egOk = $this->mkEg($this->a, '架空 参加太郎', 'v20ok@park.example', true);
        $this->egLeft = $this->mkEg($this->a, '架空 退会ログイン', 'v20left@park.example', true, true);
        $this->egNoLogin = $this->mkEg($this->a, '架空 退会未送信', 'v20nologin@park.example', false, true);
        $this->egOther = $this->mkEg($this->b, '架空 他社退会', 'v20other@park.example', false, true);

        // C は管理者の［退会］で作る。D は park:move-ses の元の会社の形
        $this->c = $this->mkSes('架空退会商事');
        $this->mkStaff($this->c, 'v20cstaff@park.example', '架空 商事担当');
        $this->mkEg($this->c, '架空 商事社員', 'v20ceg@park.example', false);
        $this->as(self::ADMIN)->post("/admin/ses/{$this->c}/delete");
        $this->d = $this->mkSes('架空移管商事', 'left');
    }

    // ---------- 下ごしらえ ----------

    private function as(string $email): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function sesIdOf(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('ses_id');
    }

    private function mkSes(string $name, string $status = 'active'): int
    {
        return (int) DB::table('ses_companies')->insertGetId(['name' => $name, 'kind' => 'ses', 'status' => $status, 'code' => Ids::next('S'),
            'agreed_at' => now_str(), 'self_confirmed_at' => now_str(), 'joined_at' => today_str(), 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    private function mkStaff(int $sid, string $email, string $name, bool $left = false): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($id);
        if ($left) DB::table('users')->where('id', $id)->update(['active' => 0, 'left_at' => now_str()]);
        return $id;
    }

    private function mkEg(int $sid, string $name, string $email, bool $login, bool $left = false): int
    {
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], User::where('email', self::ADMIN)->firstOrFail(), false);
        if ($login) {
            DB::table('engineers')->where('id', $id)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
            $uid = W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
            $this->completeProfile($uid);
            if ($left) DB::table('users')->where('id', $uid)->update(['active' => 0, 'left_at' => now_str()]);
        }
        if ($left) DB::table('engineers')->where('id', $id)->update(['status' => 'left']);
        return $id;
    }

    // 表の行（<tr>〜</tr>）のうち、$needle を含むもの。無ければ null
    private function row(string $html, string $needle): ?string
    {
        preg_match_all('#<tr[ >].*?</tr>#s', $html, $m);
        foreach ($m[0] as $tr) if (str_contains($tr, $needle)) return $tr;
        return null;
    }

    private function page(string $as, string $url): string
    {
        return $this->as($as)->get($url)->assertOk()->getContent();
    }

    private function assertGray(string $html, string $needle): void
    {
        $tr = $this->row($html, $needle);
        $this->assertNotNull($tr, "$needle の行がない");
        $this->assertStringContainsString('class="is-left"', $tr, "$needle の行がグレーでない");
        $this->assertStringContainsString('退会済み', $tr);
    }

    private function assertNotGray(string $html, string $needle): void
    {
        $tr = $this->row($html, $needle);
        $this->assertNotNull($tr, "$needle の行がない");
        $this->assertStringNotContainsString('is-left', $tr);
    }

    // ---------- A1〜A3：一覧 ----------

    public function test_ses_list_hides_left_by_default(): void
    {
        $html = $this->page(self::ADMIN, '/admin/ses');
        $this->assertStringNotContainsString('架空退会商事', $html);
        $this->assertStringNotContainsString('架空移管商事', $html);
        $this->assertStringContainsString('退会済みを表示しない', $html);
        $this->assertStringContainsString('退会済み 2 件は表示していません。', $html);

        $html = $this->page(self::ADMIN, '/admin/ses?left=1');
        $this->assertGray($html, '架空退会商事');
        $this->assertGray($html, '架空移管商事');
        $this->assertNotGray($html, DB::table('ses_companies')->where('id', $this->a)->value('name'));
        $this->assertStringContainsString('退会済み 2 件を含みます（グレーの行）。', $html);
    }

    public function test_admin_engineers_and_ses_detail(): void
    {
        $html = $this->page(self::ADMIN, '/admin/engineers');
        $this->assertStringNotContainsString('架空 退会ログイン', $html);
        $this->assertStringNotContainsString('架空 退会未送信', $html);
        $this->assertStringContainsString('架空 参加太郎', $html);
        $html = $this->page(self::ADMIN, '/admin/engineers?left=1');
        $this->assertGray($html, '架空 退会ログイン');
        $this->assertNotGray($html, '架空 参加太郎');

        $html = $this->page(self::ADMIN, "/admin/ses/{$this->a}");
        foreach (['架空 去就担当', '架空 兼任退会', '架空 退会ログイン', '架空 退会未送信'] as $n) $this->assertStringNotContainsString($n, $html);
        $this->assertStringContainsString('架空 現役担当', $html);
        $this->assertStringContainsString('退会済み 2 件は表示していません。', $html); // 担当者の表（兼任の管理者を含む）もエンジニアの表も 2 件
        $html = $this->page(self::ADMIN, "/admin/ses/{$this->a}?left=1");
        foreach (['架空 去就担当', '架空 兼任退会', '架空 退会ログイン', '架空 退会未送信'] as $n) $this->assertGray($html, $n);
        $this->assertNotGray($html, '架空 現役担当');
    }

    public function test_ses_side_lists_for_rep_and_staff(): void
    {
        foreach ([self::SES1, self::STAFF] as $who) {
            $html = $this->page($who, '/ses/engineers');
            $this->assertStringNotContainsString('架空 退会ログイン', $html);
            $this->assertStringContainsString('架空 参加太郎', $html);
            $html = $this->page($who, '/ses/engineers?left=1');
            $this->assertGray($html, '架空 退会ログイン');
            $this->assertStringNotContainsString('架空 他社退会', $html); // A12：他社は出ない

            $html = $this->page($who, '/ses/company');
            foreach (['架空 去就担当', '架空 兼任退会', '架空 退会ログイン', self::GONE, 'v20left@park.example'] as $n) $this->assertStringNotContainsString($n, $html); // A13
            $html = $this->page($who, '/ses/company?left=1');
            foreach (['架空 去就担当', '架空 兼任退会', '架空 退会ログイン'] as $n) $this->assertGray($html, $n);
            $this->assertStringNotContainsString('架空 他社退会', $html);
        }
    }

    // ---------- A4：件数の文 ----------

    public function test_note_text_and_link(): void
    {
        $html = $this->page(self::SES1, '/ses/engineers');
        $this->assertStringContainsString('退会済み 2 件は表示していません。', $html);
        $this->assertStringContainsString(url('ses/engineers').'?left=1', $html);
        $this->assertStringContainsString('退会済み 2 件を含みます', $this->page(self::SES1, '/ses/engineers?left=1'));

        $e = $this->mkSes('架空平穏商事');
        $this->mkStaff($e, 'v20peace@park.example', '架空 平穏担当');
        $this->mkEg($e, '架空 平穏社員', 'v20peaceeg@park.example', false);
        $html = $this->page('v20peace@park.example', '/ses/engineers');
        $this->assertStringNotContainsString('件は表示していません', $html);
        $this->assertStringNotContainsString('件を含みます', $html);
    }

    // ---------- A5・A6：検索・状態の絞り込み ----------

    public function test_status_filter_and_search(): void
    {
        $html = $this->page(self::ADMIN, '/admin/engineers?status=left');
        $this->assertGray($html, '架空 退会ログイン');
        $this->assertGray($html, '架空 退会未送信');

        $html = $this->page(self::ADMIN, '/admin/engineers?q='.urlencode('退会ログイン'));
        $this->assertNull($this->row($html, '架空 退会ログイン'));
        $this->assertStringContainsString('退会済み 1 件は表示していません。', $html);
        $this->assertStringNotContainsString('まだありません', $html);
    }

    // ---------- A7：ページ送り ----------

    public function test_pager_counts_only_shown_rows(): void
    {
        $e = $this->mkSes('架空大所帯商事');
        $this->mkStaff($e, 'v20big@park.example', '架空 大所帯担当');
        foreach (range(1, 24) as $i) {
            DB::table('engineers')->insert(['ses_id' => $e, 'name' => "架空 社員$i", 'status' => $i > 21 ? 'left' : 'available', 'code' => Ids::next('E'), 'created_at' => now_str(), 'updated_at' => now_str()]);
        }
        $this->as('v20big@park.example')->get('/ses/engineers')->assertOk()->assertSee('全 21 件')->assertDontSee('全 24 件');
        $this->as('v20big@park.example')->get('/ses/engineers?left=1')->assertOk()->assertSee('全 24 件')->assertSee('left=1&amp;page=2', false);
        $this->as('v20big@park.example')->get('/ses/engineers?left=1&page=99')->assertOk()->assertSee('全 24 件');
    }

    // ---------- A8：戻り先 ----------

    public function test_back_to_list_keeps_left(): void
    {
        $this->as(self::ADMIN)->post("/engineers/{$this->egOk}/delete", ['back' => 'list', 'back_query' => 'left=1'])->assertRedirect('/admin/engineers?left=1');
        $this->assertSame('エンジニア「架空 参加太郎」を退会済みにしました。', session('flash')['msg']); // A11
        $this->assertSame('/p?left=1', list_back('/p', 'left=1'));
    }

    // ---------- A9・A10：詳細のタイトル ----------

    private function assertTitle(string $as, string $url, string $title): void
    {
        $this->as($as)->get($url)->assertOk()->assertSee("<title>$title | ", false)->assertSee("<h1>$title</h1>", false);
    }

    public function test_detail_titles(): void
    {
        $c = DB::table('ses_companies')->find($this->c);
        $this->assertTitle(self::ADMIN, "/admin/ses/{$this->c}", "{$c->code}　{$c->name}　退会済み");
        $u = DB::table('users')->find($this->gone);
        $this->assertTitle(self::ADMIN, "/admin/ses/{$this->a}/users/{$this->gone}", "{$u->staff_code}　{$u->name}　退会済み");
        $e = DB::table('engineers')->find($this->egLeft);
        $this->assertTitle(self::ADMIN, "/engineers/{$this->egLeft}", "{$e->code}　{$e->name}　退会済み");
        $this->assertTitle(self::SES1, "/engineers/{$this->egLeft}", "{$e->code}　{$e->name}　退会済み");

        // 退会済みでなければ、今のまま
        $s = DB::table('ses_companies')->find($this->a);
        $this->assertTitle(self::ADMIN, "/admin/ses/{$this->a}", "{$s->code}　{$s->name}");
        $m = DB::table('users')->find($this->staff);
        $this->assertTitle(self::ADMIN, "/admin/ses/{$this->a}/users/{$this->staff}", "{$m->staff_code}　{$m->name}");
        $ok = DB::table('engineers')->find($this->egOk);
        $this->assertTitle(self::ADMIN, "/engineers/{$this->egOk}", "{$ok->code}　{$ok->name}");
        $this->assertTitle(self::SES1, '/ses/company', "{$s->code}　{$s->name}");

        $this->assertSame('SE-000011　AAA　退会済み', id_title('SE-000011', 'AAA', true));
    }

    // ---------- A11・A14：語とアカウントの欄 ----------

    public function test_word_and_account_column(): void
    {
        $this->assertSame('退会済み', user_state(DB::table('users')->find($this->gone)));
        $tr = $this->row($this->page(self::ADMIN, '/admin/engineers?left=1'), "/engineers/{$this->egNoLogin}\"");
        $this->assertStringContainsString('<td>退会済み<br>', $tr);
        $this->assertStringNotContainsString("/engineers/{$this->egNoLogin}/invite", $tr);
        $this->assertStringNotContainsString('仮登録', $tr);
    }

    // ---------- レビューの指摘：詳細の「アカウント」欄と、SES が 0 件のときの空の文 ----------

    public function test_engineer_detail_account_says_left(): void
    {
        // ログインの無い退会済み EG も、ログインのある退会済み EG も「退会済み」（「なし」にしない）
        foreach ([self::ADMIN, self::SES1] as $who) {
            foreach ([$this->egNoLogin, $this->egLeft] as $id) {
                $this->assertStringContainsString('<dt>アカウント</dt><dd>退会済み', $this->page($who, "/engineers/$id"));
            }
        }
        $this->assertStringNotContainsString('<dt>アカウント</dt><dd>退会済み', $this->page(self::ADMIN, "/engineers/{$this->egOk}"));
    }

    public function test_empty_ses_list_shows_one_message(): void
    {
        DB::table('ses_companies')->where('kind', 'ses')->update(['kind' => 'direct']); // 一覧に出る SES が 0 件の形
        $html = $this->page(self::ADMIN, '/admin/ses');
        $this->assertSame(1, substr_count($html, 'まだありません'));
        $this->assertStringContainsString('SES はまだありません。「SES を仮登録」から入れます。', $html);
        $this->assertStringNotContainsString('件は表示していません', $html);
    }

    // ---------- A12：権限 ----------

    public function test_left_param_does_not_widen_permission(): void
    {
        foreach ([self::SES1, self::STAFF, 'eg1@park.example'] as $who) {
            $this->as($who)->get('/admin/ses?left=1')->assertForbidden();
            $this->as($who)->get('/admin/engineers?left=1')->assertForbidden();
        }
        $this->as('eg1@park.example')->get('/ses/engineers?left=1')->assertForbidden();
        $this->as('eg1@park.example')->get('/ses/company?left=1')->assertForbidden();
    }

    // ---------- A15：おかしな値 ----------

    public function test_odd_left_values_keep_hidden(): void
    {
        foreach (['left=0', 'left=', 'left[]=1', 'left=yes', 'left='.str_repeat('1', 500)] as $q) {
            $this->assertStringNotContainsString('架空 退会ログイン', $this->page(self::ADMIN, "/admin/engineers?$q"), $q);
            $this->assertStringNotContainsString('架空退会商事', $this->page(self::ADMIN, "/admin/ses?$q"), $q);
        }
    }

    // ---------- 回帰：SES の詳細の数え・交代の候補・「次の SES」は全件で計算 ----------

    public function test_ses_detail_calculations_use_all_users(): void
    {
        $e = $this->mkSes('架空空席商事');
        $this->mkStaff($e, 'v20empty@park.example', '架空 空席担当', true);
        $html = $this->page(self::ADMIN, "/admin/ses/$e");
        $this->assertStringContainsString('使える担当者がいません。担当者を登録してください。', $html);
        $this->assertStringContainsString('退会済み 1 件は表示していません。', $html);
        $this->assertStringNotContainsString('架空 空席担当', $html);

        $next = $this->mkStaff($e, 'v20next@park.example', '架空 次期担当');
        DB::table('users')->where('id', $next)->update(['registered_at' => null]);
        DB::table('ses_companies')->where('id', $e)->update(['next_rep_user_id' => $next]);
        $this->assertStringContainsString('次の SES：架空 次期担当', $this->page(self::ADMIN, "/admin/ses/$e"));

        // 本登録が済んだ担当者は、交代の候補に出る
        $html = $this->page(self::ADMIN, "/admin/ses/{$this->a}");
        $this->assertStringContainsString('<option value="'.$this->staff.'"', $html);
        $this->assertStringNotContainsString('<option value="'.$this->gone.'"', $html);
    }

    // ---------- 追加（tester）：切り替えの部品・表ごとの件数の文・リンク ----------

    private function leftNote(string $html, int $n): int
    {
        return substr_count($html, "退会済み {$n} 件は表示していません。");
    }

    public function test_one_toggle_per_screen_and_selected_value(): void
    {
        $screens = [[self::ADMIN, '/admin/ses'], [self::ADMIN, '/admin/engineers'], [self::ADMIN, "/admin/ses/{$this->a}"], [self::SES1, '/ses/engineers'], [self::STAFF, '/ses/company']];
        foreach ($screens as [$who, $url]) {
            $off = $this->page($who, $url);
            $this->assertSame(1, substr_count($off, 'name="left"'), $url);
            $this->assertDoesNotMatchRegularExpression('#<option value="1"[^>]*selected[^>]*>退会済みを表示する#u', $off, $url);
            $on = $this->page($who, "$url?left=1");
            $this->assertSame(1, substr_count($on, 'name="left"'), $url);
            $this->assertMatchesRegularExpression('#<option value="1"[^>]*selected[^>]*>退会済みを表示する#u', $on, $url);
            $this->assertStringNotContainsString('name="page"', $on, $url); // 切り替えるとページ 1 に戻る
        }
        // L3・L5 は表ごとに件数の文
        $this->assertSame(2, $this->leftNote($this->page(self::ADMIN, "/admin/ses/{$this->a}"), 2));
        $this->assertSame(2, $this->leftNote($this->page(self::SES1, '/ses/company'), 2));
        $this->assertSame(2, substr_count($this->page(self::SES1, '/ses/company?left=1'), '退会済み 2 件を含みます（グレーの行）。'));
    }

    public function test_note_link_keeps_conditions_and_combined_filters(): void
    {
        $q = urlencode('退会');
        $html = $this->page(self::ADMIN, "/admin/engineers?q=$q&page=3");
        $this->assertStringContainsString('href="'.url('admin/engineers')."?q=$q&amp;left=1\"", $html); // 条件は残し、page は外す

        // 検索＋切り替え：検索に合う退会済みだけが出る
        $html = $this->page(self::ADMIN, "/admin/engineers?q=$q&left=1");
        $this->assertGray($html, '架空 退会ログイン');
        $this->assertGray($html, '架空 退会未送信');
        $this->assertNull($this->row($html, '架空 参加太郎'));

        // 状態の絞り込みが退会済み以外なら、退会済みは数えない
        $html = $this->page(self::ADMIN, '/admin/engineers?status=available');
        $this->assertStringNotContainsString('件は表示していません', $html);
        $this->assertStringNotContainsString('架空 退会ログイン', $html);
        $html = $this->page(self::ADMIN, '/admin/engineers?status=available&left=1');
        $this->assertStringNotContainsString('架空 退会ログイン', $html);
    }

    public function test_hidden_rows_leak_no_name_or_mail(): void
    {
        $secret = ['架空 退会ログイン', '架空 退会未送信', '架空 去就担当', '架空 兼任退会', 'v20left@park.example', 'v20nologin@park.example', self::GONE, 'v20dual@park.example'];
        foreach ([[self::ADMIN, '/admin/engineers'], [self::ADMIN, "/admin/ses/{$this->a}"], [self::SES1, '/ses/engineers'], [self::STAFF, '/ses/engineers'], [self::STAFF, '/ses/company']] as [$who, $url]) {
            $html = $this->page($who, $url);
            foreach ($secret as $s) $this->assertStringNotContainsString($s, $html, "$who $url");
        }
    }

    // ---------- 追加：ほかの SES・立場 ----------

    public function test_other_ses_cannot_see_or_operate(): void
    {
        $this->mkStaff($this->b, 'v20bgone@park.example', '架空 他社去就', true);
        foreach ([self::SES1, self::STAFF] as $who) {
            foreach (["/ses/company?left=1&ses={$this->b}", "/ses/engineers?left=1&ses={$this->b}"] as $url) {
                $html = $this->page($who, $url);
                $this->assertStringNotContainsString('架空 他社去就', $html);
                $this->assertStringNotContainsString('架空 他社退会', $html);
            }
        }
        // ほかの会社の SES は、A の退会済みを見られない・操作できない
        $b = 'ses2@park.example';
        $html = $this->page($b, '/ses/company?left=1');
        $this->assertGray($html, '架空 他社去就');
        foreach (['架空 去就担当', '架空 兼任退会', '架空 退会ログイン'] as $n) $this->assertStringNotContainsString($n, $html);
        $this->as($b)->get("/engineers/{$this->egLeft}")->assertForbidden();
        $this->as($b)->post("/engineers/{$this->egOk}/delete", ['back' => 'list', 'back_query' => 'left=1'])->assertForbidden();
        $this->assertSame('available', DB::table('engineers')->where('id', $this->egOk)->value('status'));
        $this->as($b)->get("/admin/ses/{$this->a}?left=1")->assertForbidden();
        $this->as($b)->get("/admin/ses/{$this->a}/users/{$this->gone}")->assertForbidden();

        // 担当者（代表でない）も、自社の退会済みの詳細は同じタイトル
        $e = DB::table('engineers')->find($this->egLeft);
        $this->assertTitle(self::STAFF, "/engineers/{$this->egLeft}", "{$e->code}　{$e->name}　退会済み");
    }

    // ---------- 追加：A8 操作のあとの一覧・二重に押す ----------

    public function test_ses_side_actions_keep_left_and_hidden_count_grows(): void
    {
        $back = ['back' => 'list', 'back_query' => 'left=1'];
        foreach (['suspend', 'resume'] as $op) {
            $this->as(self::STAFF)->post("/engineers/{$this->egOk}/$op", $back)->assertRedirect('/ses/engineers?left=1');
        }
        $this->as(self::STAFF)->post("/engineers/{$this->egOk}/delete", ['back' => 'list', 'back_query' => ''])->assertRedirect('/ses/engineers');
        $html = $this->page(self::STAFF, '/ses/engineers');
        $this->assertStringNotContainsString('架空 参加太郎', $html);
        $this->assertGreaterThanOrEqual(1, preg_match('#退会済み \d+ 件は表示していません。#u', $html));

        // 管理者の SES の一覧の戻り先
        foreach (['suspend', 'resume', 'delete'] as $op) {
            $this->as(self::ADMIN)->post("/admin/ses/{$this->d}/$op", $back)->assertRedirect('/admin/ses?left=1');
        }
    }

    public function test_last_engineer_left_shows_only_note(): void
    {
        $e = $this->mkSes('架空一人商事');
        $this->mkStaff($e, 'v20one@park.example', '架空 一人担当');
        $id = $this->mkEg($e, '架空 最後社員', 'v20lasteg@park.example', false);
        $this->as('v20one@park.example')->post("/engineers/$id/delete", ['back' => 'list', 'back_query' => ''])->assertRedirect('/ses/engineers');
        $html = $this->page('v20one@park.example', '/ses/engineers');
        $this->assertSame(1, $this->leftNote($html, 1));
        $this->assertStringNotContainsString('まだありません', $html);
        $this->assertStringNotContainsString('<table>', $html);
    }

    public function test_pressing_leave_twice(): void
    {
        $this->as(self::ADMIN)->post("/engineers/{$this->egLeft}/delete");
        $this->assertSame('すでに退会済みです。', session('flash')['msg']);
        $this->as(self::ADMIN)->post("/admin/ses/{$this->c}/delete");
        $this->assertSame('すでに退会済みです。', session('flash')['msg']);
        $before = DB::table('users')->where('id', $this->gone)->value('left_at');
        foreach (['delete', 'suspend', 'resume'] as $op) {
            $this->as(self::ADMIN)->post("/admin/ses/{$this->a}/users/{$this->gone}/$op");
            $this->assertSame('退会済みの人は操作できません。', session('flash')['msg'], $op);
        }
        $this->assertSame($before, DB::table('users')->where('id', $this->gone)->value('left_at'));

        // 参加中の担当者を代表が退会 → メッセージと、表から消えて件数が増える
        $this->as(self::SES1)->post("/admin/ses/{$this->a}/users/{$this->staff}/delete");
        $this->assertSame('架空 現役担当 さんを退会済みにしました。', session('flash')['msg']);
        $html = $this->page(self::SES1, '/ses/company');
        $this->assertStringNotContainsString('架空 現役担当', $html);
        $this->assertSame(1, $this->leftNote($html, 3));
    }

    // ---------- 追加：A14 SES 側と SES の詳細の「アカウント」欄 ----------

    public function test_account_column_on_other_lists(): void
    {
        foreach ([[self::SES1, '/ses/engineers?left=1'], [self::STAFF, '/ses/company?left=1'], [self::ADMIN, "/admin/ses/{$this->a}?left=1"]] as [$who, $url]) {
            $tr = $this->row($this->page($who, $url), '架空 退会未送信');
            $this->assertStringContainsString('<td>退会済み<br>', $tr, $url);
            $this->assertStringNotContainsString('仮登録', $tr, $url);
            foreach (['invite', 'delete', 'suspend'] as $op) $this->assertStringNotContainsString("/engineers/{$this->egNoLogin}/$op", $tr, $url);
        }
    }

    // ---------- 追加：境界 ----------

    public function test_all_left_company_detail(): void
    {
        $html = $this->page(self::ADMIN, "/admin/ses/{$this->c}");
        $this->assertStringNotContainsString('架空 商事担当', $html);
        $this->assertStringNotContainsString('架空 商事社員', $html);
        $this->assertSame(2, $this->leftNote($html, 1));
        $html = $this->page(self::ADMIN, "/admin/ses/{$this->c}?left=1");
        $this->assertGray($html, '架空 商事担当');
        $this->assertGray($html, '架空 商事社員');
    }

    public function test_real_move_ses_source_company(): void
    {
        $before = glob(storage_path('app/backups/move-ses-*.json')) ?: [];
        try {
            $from = $this->mkSes('架空引越元商事');
            $this->mkStaff($from, 'v20move@park.example', '架空 引越担当');
            $this->mkEg($from, '架空 引越社員', 'v20moveeg@park.example', false);
            $this->artisan('park:move-ses', ['from' => $from, 'to' => $this->b, '--yes' => true])->assertSuccessful();
            $this->assertStringNotContainsString('架空引越元商事', $this->page(self::ADMIN, '/admin/ses'));
            $this->assertGray($this->page(self::ADMIN, '/admin/ses?left=1'), '架空引越元商事');
            $html = $this->page(self::ADMIN, "/admin/ses/$from");
            $this->assertStringContainsString('　架空引越元商事　退会済み</h1>', $html);
            $this->assertStringNotContainsString('件は表示していません', $html);
            // 移した人は退会済みにならない
            $this->assertNotGray($this->page(self::ADMIN, '/admin/engineers'), '架空 引越社員');
        } finally {
            foreach (glob(storage_path('app/backups/move-ses-*.json')) ?: [] as $f) if (!in_array($f, $before, true)) @unlink($f);
        }
    }

    public function test_suspended_is_not_treated_as_left(): void
    {
        DB::table('engineers')->where('id', $this->egOk)->update(['suspended_at' => now_str()]);
        DB::table('users')->where('id', $this->staff)->update(['active' => 0]);
        DB::table('ses_companies')->where('id', $this->b)->update(['status' => 'suspended']);
        $this->assertNotGray($this->page(self::ADMIN, '/admin/engineers'), '架空 参加太郎');
        $html = $this->page(self::SES1, '/ses/company');
        $this->assertNotGray($html, '架空 参加太郎');
        $this->assertNotGray($html, '架空 現役担当');
        $this->assertNotGray($this->page(self::ADMIN, '/admin/ses'), DB::table('ses_companies')->where('id', $this->b)->value('name'));
    }

    public function test_left_company_people_cannot_see_untitled_company_page(): void
    {
        // D4：会社が退会済みになった人はふつう入れない。status だけを直接変えた形で確かめる
        DB::table('ses_companies')->where('id', $this->a)->update(['status' => 'left']);
        $res = $this->as(self::SES1)->get('/ses/company');
        if ($res->getStatusCode() === 200) {
            $s = DB::table('ses_companies')->find($this->a);
            $res->assertSee("<h1>{$s->code}　{$s->name}　退会済み</h1>", false);
        } else {
            $this->assertContains($res->getStatusCode(), [302, 403]);
        }
    }

    // ---------- 追加：A11 語（画面・マニュアル・文言に「退会済」だけの形が残っていない） ----------

    public function test_no_old_word_in_views_manuals_and_app(): void
    {
        $files = glob(resource_path('manuals/*.md'));
        foreach ([resource_path('views'), app_path()] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)) as $f) $files[] = (string) $f;
        }
        $bad = [];
        foreach ($files as $path) {
            foreach (file($path) as $i => $line) {
                $line = preg_replace(['#\{\{--.*?--\}\}#u', '#(^|\s)//.*$#u'], '', $line); // コメントは対象外
                if (preg_match('#退会済(?!み)#u', $line)) $bad[] = basename($path).':'.($i + 1);
            }
        }
        $this->assertSame([], $bad);
    }
}
