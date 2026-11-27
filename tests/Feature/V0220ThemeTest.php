<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.22.0：緑の基調・若枝・派手なトップ・区切りのページ（PLAN-v0220.md の AC1〜AC14）。足す名前・アドレスはすべて架空
class V0220ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES = 'ses1@park.example';
    private const GEN = 'v22gen@park.example';   // 一般担当者（このテストで作る）
    private const EG = 'eg1@park.example';

    // ---------- 道具 ----------

    private function as(string $email, ?string $acting = null): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        $s = [TwoFactor::SESSION_KEY => $u->id];
        if ($acting) $s['acting_role'] = $acting;
        return $this->actingAs($u->fresh())->withSession($s);
    }

    private function guest_(): static
    {
        $this->flushSession();
        $this->app['auth']->guard()->logout();
        return $this;
    }

    private function row(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function mkGeneral(): void
    {
        $sid = (int) $this->row(self::SES)->ses_id;
        $id = W::createUser(self::GEN, '架空 一般', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($id);
        $this->assertSame('一般担当者', staff_kind(DB::table('users')->find($id)));
    }

    // SES 担当者（ses1）を、エンジニアも兼ねる人にする
    private function mkDual(): void
    {
        $this->as(self::SES, 'ses')->post('/engineers', ['name_sei' => '緑川', 'name_mei' => '本人', 'email' => self::SES])->assertRedirect();
        $eid = (int) $this->row(self::SES)->engineer_id;
        $this->assertGreaterThan(0, $eid);
        DB::table('engineers')->where('id', $eid)->update(['kana_sei' => 'てすと', 'kana_mei' => 'ほんにん', 'phone' => '090-0000-0000',
            'postal_code' => '100-0001', 'address' => '東京都（テスト）', 'registered_at' => now_str(), 'consent_at' => now_str(), 'self_confirmed_at' => now_str()]);
        $this->guest_();
    }

    private function code(string $email): string
    {
        $subject = DB::table('mail_outbox')->where('to_email', $email)->where('kind', '2fa')->orderByDesc('id')->value('subject');
        preg_match('/(\d{6})/', (string) $subject, $m);
        return $m[1];
    }

    private function loginPassword(string $email): void
    {
        $this->post('/login', ['email' => $email, 'password' => 'park1234'])->assertRedirect(url('/two-factor/challenge'));
    }

    private function enterCode(string $email)
    {
        return $this->post('/two-factor/challenge', ['code' => $this->code($email)]);
    }

    private function confirmed(string $email): void
    {
        DB::table('users')->where('email', $email)->update(['two_factor_confirmed_at' => now_str()]);
    }

    // ボタンの並びの部分だけを取り出す（ヘッダーやメニューのリンクと混ざらないように）
    private function actions(string $html): string
    {
        $this->assertSame(1, preg_match('#<p class="start-actions">(.*?)</p>#s', $html, $m), 'ボタンの並びが無い');
        return $m[1];
    }

    private function link(string $path, string $label): string
    {
        return '#<a [^>]*href="'.preg_quote(url($path), '#').'"[^>]*>'.preg_quote($label, '#').'</a>#';
    }

    private function css(): string
    {
        return file_get_contents(public_path('css/park.css'));
    }

    // ---------- AC1・AC2：色 ----------

    public function test_ac1_ac2_each_role_has_its_body_class(): void
    {
        $this->mkGeneral();
        $this->get('/')->assertOk()->assertSee('role-guest', false);
        foreach ([[self::ADMIN, '/admin', 'admin'], [self::SES, '/ses', 'ses'], [self::GEN, '/ses', 'ses'], [self::EG, '/eg', 'eg']] as [$email, $url, $role]) {
            $html = $this->as($email)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<body class="\s*role-'.$role.'"#', $html, $email);
        }
    }

    public function test_ac1_ac2_css_changes_only_the_role_color(): void
    {
        $css = preg_replace('#/\*.*?\*/#s', '', $this->css());
        foreach (['admin' => '#3b6fb6', 'ses' => '#3d9465', 'eg' => '#8a5bb5'] as $role => $color) {
            // 役割ごとの body の決まりは --role だけ
            preg_match_all('#body\.role-'.$role.'\s*\{([^}]*)\}#', $css, $m);
            $this->assertSame(['--role: '.$color.';'], array_map('trim', $m[1]), "body.role-$role");
        }
        $this->assertMatchesRegularExpression('#:root\s*\{[^}]*--role:\s*\#3d9465#', $css);
        // --role を使うのは、ヘッダーの帯・ログイン中の帯・役割の札だけ
        preg_match_all('#([^{}]+)\{[^{}]*var\(--role\)[^{}]*\}#', $css, $m);
        $selectors = array_map('trim', $m[1]);
        sort($selectors);
        $this->assertSame(['.loginbar', '.rolepill', 'body.role-admin header.top, body.role-ses header.top, body.role-eg header.top'], $selectors);
    }

    public function test_ac1_no_blue_or_purple_left_in_css(): void
    {
        $bad = [];
        foreach (explode("\n", preg_replace('#/\*.*?\*/#s', '', $this->css())) as $line) {
            // 立場の色（AC2）と区切りのページの担当の札（仕様 4-2）
            if (preg_match('#^\s*(body\.role-(admin|eg)\s*\{|\.flow-steps \.owner\.(admin|eg)\b)#', $line)) continue;
            // テーマでない、意味を持つ文字色（以前の依頼で足したもの）：マッチした点の青・合格の赤紫
            if (preg_match('#^\s*(\.hit-text|\.result-passed)\b#', $line)) continue;
            preg_match_all('#\#([0-9a-fA-F]{6}|[0-9a-fA-F]{3})\b|rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)#', $line, $m, PREG_SET_ORDER);
            foreach ($m as $c) {
                if (($c[1] ?? '') !== '') {
                    $h = strlen($c[1]) === 3 ? $c[1][0].$c[1][0].$c[1][1].$c[1][1].$c[1][2].$c[1][2] : $c[1];
                    [$r, $g, $b] = array_map('hexdec', str_split($h, 2));
                } else {
                    [$r, $g, $b] = [(int) $c[2], (int) $c[3], (int) $c[4]];
                }
                $max = max($r, $g, $b);
                $d = $max - min($r, $g, $b);
                if ($d < 12) continue; // ほぼ灰色
                $hue = 60 * ($max === $r ? fmod(($g - $b) / $d + 6, 6) : ($max === $g ? ($b - $r) / $d + 2 : ($r - $g) / $d + 4));
                if ($hue >= 185 && $hue <= 330) $bad[] = trim($line);
            }
        }
        $this->assertSame([], $bad, '青・紫が残っている');
    }

    // ---------- AC3・AC4：若枝 ----------

    public function test_ac3_ac4_sprig_beside_title_and_in_logo(): void
    {
        $this->assertFileExists(public_path('img/sprig.jpg'));
        $img = 'src="'.asset('img/sprig.jpg').'" alt=""';
        foreach ([[self::ADMIN, '/admin/ses/1'], [self::SES, '/ses/engineers'], [self::EG, '/engineers/1']] as [$email, $url]) {
            $html = $this->as($email)->get($url)->assertOk()->getContent();
            $this->assertMatchesRegularExpression('#<div class="ttl"><img class="sprig" '.preg_quote($img, '#').'><h1>[^<]+</h1></div>#', $html, $url);
            $this->assertStringContainsString('<img class="mark sprig" '.$img.'>', $html, $url);
            $this->assertStringNotContainsString('🌳', $html, $url);
        }
        // ログインの前の画面でも出る
        $this->guest_()->get('/login')->assertOk()->assertSee('<div class="ttl"><img class="sprig" '.$img.'>', false)->assertSee('<img class="mark sprig" '.$img.'>', false);
    }

    public function test_ac3_print_pages_have_no_sprig(): void
    {
        $this->as(self::ADMIN)->get('/engineers/1/sheet')->assertOk()->assertDontSee('sprig', false);
        $this->post('/admin/invoices/build', ['period' => date('Y-m')])->assertRedirect();
        $this->get('/invoices/1')->assertOk()->assertSee('ご請求書')->assertDontSee('sprig', false);
    }

    // AC5（360px で重ならない）はブラウザでの確認

    // ---------- AC6・AC12：トップ ----------

    public function test_ac6_ac12_home_is_big_hero_and_spots_go_to_start_pages(): void
    {
        $html = $this->get('/')->assertOk()->getContent();
        $this->assertStringContainsString('<section class="hero big cover">', $html);
        $this->assertStringContainsString('<img class="hero-dome" src="'.asset('img/dome.png').'" alt="">', $html);
        foreach (['木漏れ日の下で、仕事と仲間がつながる庭', '<span class="nb">SES企業とエンジニア</span><span class="brand">PARK</span><span class="nb">みなさまが集う庭</span>', 'エンジニアの成約率を上げること', 'PARK の歩き方', 'グループで、仕事を回す', '合う案件が、届く', '利用は無料です。'] as $t) {
            $this->assertStringContainsString($t, $html, $t);
        }
        preg_match_all('#<a class="spot" href="([^"]+)"#', $html, $m);
        $this->assertSame([url('/start/ses'), url('/start/engineer')], $m[1]);
    }

    // ---------- AC7：ダッシュボードの帯 ----------

    public function test_ac7_dashboards_have_role_hero_and_keep_h1(): void
    {
        $this->mkGeneral();
        $cases = [
            [self::ADMIN, '/admin', 'グループの案件と、みなさんの進み具合を見守ります。'],
            [self::SES, '/ses', 'グループで、仕事を回す。'],
            [self::GEN, '/ses', 'グループで、仕事を回す。'],
            [self::EG, '/eg', '合う案件が、届く。'],
        ];
        foreach ($cases as [$email, $url, $word]) {
            $html = $this->as($email)->get($url)->assertOk()->getContent();
            $this->assertSame(1, preg_match('#<div class="role-hero"[^>]*>(.*?)</div>#s', $html, $m), "$email $url");
            $this->assertStringContainsString('img/hero.jpg', $html);
            $this->assertStringContainsString('img/sprig.jpg', $m[1]);
            $this->assertStringContainsString('<p>'.$word.'</p>', $m[1], $email);
            $this->assertStringNotContainsString('<h1', $m[1]);
            $this->assertSame(1, substr_count($html, '<h1>'), "$email $url の h1");
        }
    }

    // ---------- AC8・AC9：区切りのページ ----------

    public function test_ac8_start_pages_are_public_and_others_404(): void
    {
        $this->get('/start/ses')->assertOk();
        $this->get('/start/engineer')->assertOk();
        foreach (['/start/admin', '/start/eg', '/start/x', '/start', '/start/SES'] as $url) $this->get($url)->assertNotFound();
        $this->as(self::ADMIN)->get('/start/admin')->assertNotFound();
    }

    public function test_ac9_headings_and_facts_without_eg_word(): void
    {
        foreach (['ses', 'engineer'] as $kind) {
            $html = $this->get("/start/$kind")->assertOk()->getContent();
            // 1stアクセス画面の帯（2026-11-27）
            $this->assertStringContainsString('<h1><span>はじめて PARK システムへアクセスされた方へ</span><span class="l2">PARK システムの概要をご説明いたします。</span></h1>', $html);
            $this->assertStringNotContainsString('class="hero-bar"', $html);
            foreach (['PARK のプレイヤー', '管理者', 'SES代表担当者', '一般担当者', 'エンジニア', 'プロバイダー'] as $t) $this->assertStringContainsString($t, $html);
            preg_match('#<main.*?</main>#s', $html, $m);
            $this->assertDoesNotMatchRegularExpression('/\bEG\b/', strip_tags($m[0]), $kind);
        }
        $this->get('/start/ses')->assertSee('一般担当者の登録（SES代表担当者が行います）')->assertSee('契約と請求の確認');
        $this->get('/start/engineer')->assertSee('面談スクリプトで準備する')->assertSee('成約したら経験票を更新する');
    }

    public function test_ac9_no_personal_data_for_any_role(): void
    {
        $this->mkGeneral();
        $secrets = [];
        foreach (DB::table('users')->get() as $u) array_push($secrets, $u->email, $u->name, $u->staff_code ?? null);
        foreach (DB::table('ses_companies')->where('kind', 'ses')->get() as $s) array_push($secrets, $s->name, $s->code);
        foreach (DB::table('engineers')->get() as $e) array_push($secrets, $e->name, $e->code);
        // 公開の相談先と、画面の文にもともとある語は除く
        $secrets = array_diff(array_unique(array_filter($secrets, fn ($v) => mb_strlen((string) $v) >= 3)), [contact_email(), '管理者']);
        $this->assertGreaterThan(10, count($secrets));
        foreach ([null, self::ADMIN, self::SES, self::GEN, self::EG] as $who) {
            foreach (['/start/ses', '/start/engineer'] as $url) {
                $this->guest_();
                if ($who) $this->as($who);
                $html = $this->get($url)->assertOk()->getContent();
                foreach ($secrets as $v) $this->assertStringNotContainsString($v, $html, ($who ?? '未ログイン')." $url");
                $this->assertDoesNotMatchRegularExpression('/\b(SE|EG|ST|AD)-\d{4,}/', $html);
                $this->assertStringNotContainsString('class="loginbar"', $html);
            }
        }
    }

    // ---------- AC10：区切りのページのボタン ----------

    public function test_ac10_guest_gets_login_and_manual(): void
    {
        foreach (['ses', 'engineer'] as $kind) {
            // 1stアクセス画面：未ログインでも「作業を始める」。押すとログインへ（2026-11-27）
            $a = $this->actions($this->get("/start/$kind")->getContent());
            $this->assertMatchesRegularExpression($this->link('/login', '作業を始める（ダッシュボードへ）'), $a);
        }
    }

    public function test_ac10_logged_in_same_role_and_other_role(): void
    {
        $this->mkGeneral();
        $start = '作業を始める（ダッシュボードへ）';
        foreach ([[self::SES, 'ses', '/ses'], [self::GEN, 'ses', '/ses'], [self::EG, 'engineer', '/eg']] as [$email, $kind, $home]) {
            $a = $this->actions($this->as($email)->get("/start/$kind")->getContent());
            $this->assertMatchesRegularExpression($this->link($home, $start), $a, $email);
            $this->assertStringNotContainsString('ログイン', $a);
        }
        foreach ([[self::EG, 'ses', '/eg'], [self::SES, 'engineer', '/ses'], [self::GEN, 'engineer', '/ses'], [self::ADMIN, 'ses', '/admin'], [self::ADMIN, 'engineer', '/admin']] as [$email, $kind, $home]) {
            $a = $this->actions($this->as($email)->get("/start/$kind")->getContent());
            $this->assertMatchesRegularExpression($this->link($home, $start), $a, "$email $kind");
        }
    }

    public function test_ac10_password_only_is_treated_as_not_logged_in(): void
    {
        $this->loginPassword(self::SES);
        $a = $this->actions($this->get('/start/ses')->assertOk()->getContent());
        $this->assertMatchesRegularExpression($this->link('/login', '作業を始める（ダッシュボードへ）'), $a);
        // 区切りのページを開いただけでは、確認コードを通ったことにならない
        $this->get('/ses')->assertRedirect(url('/two-factor/challenge'));
    }

    public function test_ac10_dual_role(): void
    {
        $this->mkDual();
        foreach (['ses', 'engineer'] as $kind) {
            $a = $this->actions($this->as(self::SES)->get("/start/$kind")->getContent());
            $this->assertMatchesRegularExpression($this->link('/login/role', '立場を選ぶ'), $a, $kind);
            $this->assertStringNotContainsString('作業を始める', $a);
        }
        $a = $this->actions($this->as(self::SES, 'eg')->get('/start/engineer')->getContent());
        $this->assertMatchesRegularExpression($this->link('/eg', '作業を始める（ダッシュボードへ）'), $a);
        $a = $this->actions($this->as(self::SES, 'eg')->get('/start/ses')->getContent());
        $this->assertMatchesRegularExpression($this->link('/eg', '作業を始める（ダッシュボードへ）'), $a);
        $a = $this->actions($this->as(self::SES, 'ses')->get('/start/ses')->getContent());
        $this->assertMatchesRegularExpression($this->link('/ses', '作業を始める（ダッシュボードへ）'), $a);
    }

    // ---------- AC11：仕事の流れ ----------

    public function test_ac11_flow_steps_highlight_by_role(): void
    {
        foreach (['ses' => ['①', '②', '③', '④', '⑤'], 'engineer' => ['①', '③', '④', '⑤']] as $kind => $on) {
            $html = $this->get("/start/$kind")->getContent();
            $this->assertSame(1, preg_match('#<nav class="flow-steps[^"]*".*?</nav>#s', $html, $nav));
            preg_match_all('#<li class="([^"]*)"><b>(.)#u', $nav[0], $m);
            $this->assertSame(['①', '②', '③', '④', '⑤'], $m[2]);
            $this->assertSame($on, array_values(array_filter($m[2], fn ($n, $i) => $m[1][$i] === 'on', ARRAY_FILTER_USE_BOTH)), $kind);
            foreach (['一般担当者', 'SES代表担当者', 'SES の作業', 'エンジニアの作業', 'owner ses', 'owner eg', 'owner admin'] as $t) $this->assertStringContainsString($t, $nav[0]);
            $this->assertDoesNotMatchRegularExpression('/\bEG\b/', strip_tags($nav[0]));
        }
    }

    // ---------- AC13：確認コードのあとの行き先 ----------

    public function test_ac13_default_destination_by_role(): void
    {
        $this->mkGeneral();
        foreach ([[self::SES, '/ses'], [self::GEN, '/ses'], [self::EG, '/eg'], [self::ADMIN, '/admin']] as [$email, $to]) {
            $this->flushSession();
            $this->loginPassword($email);
            $this->enterCode($email)->assertRedirect(url($to));
            $this->get($to)->assertOk();
            $this->post('/logout');
        }
    }

    public function test_ac13_intended_wins_after_code(): void
    {
        foreach ([[self::SES, '/ses/engineers'], [self::EG, '/eg/offers'], [self::ADMIN, '/admin/ses']] as [$email, $url]) {
            $this->flushSession();
            $this->get($url)->assertRedirect(url('/login'));
            $this->loginPassword($email);
            $this->enterCode($email)->assertRedirect(url($url));
            $this->post('/logout');
        }
    }

    // 確認コードを聞かないログイン（一度通った人）：パスワードのあと、確認コードの画面がそのまま先へ送る
    public function test_ac13_confirmed_login_goes_to_start_page(): void
    {
        $this->mkGeneral();
        foreach ([[self::SES, '/ses'], [self::GEN, '/ses'], [self::EG, '/eg'], [self::ADMIN, '/admin']] as [$email, $to]) {
            $this->confirmed($email);
            $this->flushSession();
            $this->loginPassword($email);
            $this->get('/two-factor/challenge')->assertRedirect(url($to));
            $this->post('/logout');
        }
        $this->assertSame(0, DB::table('mail_outbox')->where('kind', '2fa')->count());
    }

    public function test_ac13_confirmed_login_honours_intended(): void
    {
        foreach ([[self::SES, '/ses/engineers'], [self::EG, '/eg/offers']] as [$email, $url]) {
            $this->confirmed($email);
            $this->flushSession();
            $this->get($url)->assertRedirect(url('/login'));
            $this->loginPassword($email);
            $this->get('/two-factor/challenge')->assertRedirect(url($url));
            $this->post('/logout');
        }
    }

    // 省く設定（PARK_SKIP_2FA_DOMAIN）の人も同じ
    public function test_ac13_skipped_login_goes_to_start_page(): void
    {
        config(['app.skip_2fa_domain' => 'park.example']);
        foreach ([[self::SES, '/ses'], [self::EG, '/eg'], [self::ADMIN, '/admin']] as [$email, $to]) {
            $this->flushSession();
            $this->loginPassword($email);
            $this->get('/two-factor/challenge')->assertRedirect(url($to));
            $this->post('/logout');
        }
    }

    // 確認コードの画面を開いた中で、通ったことになる道（コードも、通った印も無いセッション）
    public function test_ac13_passed_while_opening_challenge_form(): void
    {
        foreach ([[self::SES, '/ses'], [self::EG, '/eg'], [self::ADMIN, '/admin']] as [$email, $to]) {
            $this->confirmed($email);
            $this->flushSession();
            $u = User::where('email', $email)->firstOrFail();
            $this->actingAs($u)->get('/two-factor/challenge')->assertRedirect(url($to));
        }
        $this->flushSession();
        $u = User::where('email', self::EG)->firstOrFail();
        $this->actingAs($u)->withSession(['url.intended' => url('/eg/offers')])->get('/two-factor/challenge')->assertRedirect(url('/eg/offers'));
    }

    // 兼任の人は今のまま（ホームへ行き、そこで立場を選ぶ）
    public function test_ac13_dual_keeps_old_destination(): void
    {
        $this->mkDual();
        $this->loginPassword(self::SES);
        $this->enterCode(self::SES)->assertRedirect(url('/ses'));
        $this->get('/ses')->assertRedirect(url('/login/role'));
        $this->post('/login/role', ['to' => 'eg'])->assertRedirect('/eg');
        $this->post('/logout');
        // コードを聞かない2回目からも同じ
        $this->flushSession();
        $this->loginPassword(self::SES);
        $this->get('/two-factor/challenge')->assertRedirect(url('/ses'));
        $this->get('/ses')->assertRedirect(url('/login/role'));
    }

    // 兼任でも、使える立場が1つだけなら、その立場の区切りのページ
    public function test_ac13_dual_with_one_usable_role(): void
    {
        $this->mkDual();
        DB::table('engineers')->where('id', $this->row(self::SES)->engineer_id)->update(['suspended_at' => now_str()]);
        $this->loginPassword(self::SES);
        $this->enterCode(self::SES)->assertRedirect(url('/ses'));
        $this->get('/ses')->assertOk();
    }

    // 招待からの本登録の完了は、今のまま（区切りのページを挟まない）
    public function test_ac13_registration_completion_keeps_home(): void
    {
        $admin = User::where('email', self::ADMIN)->first();
        W::createEngineer(1, ['name' => '架空 花子', 'email' => 'v22invite@park.example'], $admin);
        $token = DB::table('users')->where('email', 'v22invite@park.example')->value('token');
        $this->post("/invite/$token", ['name_sei' => '架空', 'name_mei' => '花子', 'kana_sei' => 'かくう', 'kana_mei' => 'はなこ', 'phone' => '090-0000-0000',
            'postal_code' => '100-0001', 'address' => '東京都千代田区', 'password' => 'abcd1234', 'password_confirmation' => 'abcd1234', 'consent' => '1'])
            ->assertRedirect(url('/two-factor/challenge'));
        $this->enterCode('v22invite@park.example')->assertRedirect(url('/eg'));
        $this->assertNotNull($this->row('v22invite@park.example')->registered_at);
    }

    // ---------- AC14 ----------

    public function test_ac14_version(): void
    {
        $this->assertSame('v0.22.0', config('park.version'));
        foreach (['/', '/login', '/start/ses', '/start/engineer'] as $url) $this->get($url)->assertOk()->assertSee('v0.22.0');
    }
}
