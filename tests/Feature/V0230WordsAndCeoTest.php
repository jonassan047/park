<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

// v0.23.0（PLAN.md 5章 受け入れ条件 A〜E）：戻るボタン・呼び名・企業代表者・状況の言葉
class V0230WordsAndCeoTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function guest(): void
    {
        Auth::logout();
        $this->flushSession();
    }

    private function staff(string $email, string $name): int
    {
        $id = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => 1]);
        $this->completeProfile($id);
        return $id;
    }

    private function backCount(string $html): int
    {
        return substr_count($html, 'ttl-back');
    }

    // ---------------- A 戻るボタン ----------------

    public function test_a1_back_button_shown_once_for_every_role(): void
    {
        foreach ([['admin@park.example', '/admin/reports'], ['admin@park.example', '/admin/ses'], ['ses1@park.example', '/ses/company'],
                  ['ses1@park.example', '/ses/engineers'], ['eg1@park.example', '/eg/offers'], ['eg1@park.example', '/eg/applications']] as [$who, $path]) {
            $html = $this->as($who)->get($path)->assertOk()->getContent();
            $this->assertSame(1, preg_match_all('#<a class="btn ttl-back noprint"#', $html), "$who $path");
        }
        // 一般担当者
        $this->staff('mate@example.com', '同僚 一郎');
        $html = $this->as('mate@example.com')->get('/ses/company')->assertOk()->getContent();
        $this->assertSame(1, preg_match_all('#<a class="btn ttl-back noprint"#', $html));
    }

    public function test_a2_back_button_not_shown_on_dashboards_and_guest_pages(): void
    {
        foreach ([['admin@park.example', '/admin'], ['ses1@park.example', '/ses'], ['eg1@park.example', '/eg']] as [$who, $path]) {
            $this->assertSame(0, $this->backCount($this->as($who)->get($path)->assertOk()->getContent()), $path);
        }
        $this->guest();
        foreach (['/login', '/apply', '/'] as $p) {
            $this->assertSame(0, $this->backCount($this->get($p)->assertOk()->getContent()), $p);
        }
        // 先に済ませる手続き：確認コード前（セッションに通過の印がない）は出ない
        $u = User::where('email', 'ses1@park.example')->first();
        $this->actingAs($u);
        $r = $this->get('/ses/company');
        if ($r->getStatusCode() === 200) $this->assertSame(0, $this->backCount($r->getContent()));
    }

    public function test_a3_back_targets(): void
    {
        $href = fn (string $html) => preg_match('#<a class="btn ttl-back noprint"([^>]*)>#', $html, $m) ? $m[1] : '';
        $this->as('ses1@park.example');
        $this->assertStringContainsString('href="'.url('/ses').'"', $href($this->get('/ses/company')->getContent()));
        $this->assertStringContainsString('href="'.url('/ses/company').'"', $href($this->get('/ses/company/edit')->getContent()));
        $this->as('admin@park.example');
        $this->assertStringContainsString('href="'.url('/admin').'"', $href($this->get('/admin/reports')->getContent()));
        $this->assertStringContainsString('href="'.url('/admin/ses').'?view=company"', str_replace('&amp;', '&', $href($this->get('/admin/ses/1')->getContent())));
        $this->assertStringContainsString('href="'.url('/admin/ses/1').'"', $href($this->get('/admin/ses/1/edit')->getContent()));
        $this->assertStringContainsString('href="'.url('/admin/ses').'"', $href($this->get('/admin/ses/new')->getContent()));
        $this->assertStringContainsString('href="'.url('/admin/admins').'"', $href($this->get('/admin/admins/new')->getContent()));
        $this->as('eg1@park.example');
        $this->assertStringContainsString('href="'.url('/eg').'"', $href($this->get('/eg/offers')->getContent()));
    }

    public function test_a5_back_button_is_hidden_in_print_and_css_js_exist(): void
    {
        $this->assertStringContainsString('ttl-back', file_get_contents(public_path('css/park.css')));
        $this->assertStringContainsString('data-back', file_get_contents(public_path('js/park.js')));
        $this->assertMatchesRegularExpression('#class="btn ttl-back noprint"#', $this->as('ses1@park.example')->get('/ses/company')->getContent());
    }

    // ---------------- B 呼び名 ----------------

    private function scanFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(resource_path('views'), \FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) if ($f->isFile()) $files[] = $f->getPathname();
        foreach (glob(resource_path('manuals/*.md')) as $f) $files[] = $f;
        $files[] = base_path('README.md');
        foreach (['app/Support/RegistrationText.php', 'app/Support/Requirements.php', 'app/Services/Affiliation.php', 'app/Services/Registration.php'] as $f) $files[] = base_path($f);
        return $files;
    }

    private function strip(string $s): string
    {
        $s = preg_replace('/\{\{--.*?--\}\}/su', '', $s);
        return str_replace(['株式会社', '有限会社', '合同会社', '子会社', '証券会社', 'カード会社'], '', $s);
    }

    public function test_b4_old_words_are_gone_from_views_manuals_readme(): void
    {
        $bad = [];
        foreach ($this->scanFiles() as $f) {
            foreach (preg_split('/\R/u', $this->strip(file_get_contents($f))) as $i => $line) {
                if (str_contains($line, '会社') && !str_contains($line, '「SES代表担当者」は「PARK窓口担当者」') && !preg_match('#^\s*(//|\*|/\*)#', $line)) $bad[] = basename($f).':'.($i + 1).' '.mb_substr(trim($line), 0, 60);
                if (str_contains($line, 'SES代表担当者') && !str_contains($line, '「SES代表担当者」は「PARK窓口担当者」')) $bad[] = basename($f).':'.($i + 1).' [SES代表担当者] '.mb_substr(trim($line), 0, 60);
            }
        }
        $this->assertSame([], $bad, "旧語が残っています\n".implode("\n", $bad));
    }

    public function test_b_screens_use_new_words(): void
    {
        $h = $this->as('admin@park.example')->get('/admin/ses/1')->assertOk()->getContent();
        $this->assertStringContainsString('企業情報', $h);
        $this->assertStringNotContainsString('会社情報', $h);
        $this->assertStringNotContainsString('SES代表担当者', $h);
        $h = $this->as('ses1@park.example')->get('/ses')->assertOk()->getContent();
        $this->assertStringContainsString('PARK窓口担当者', $h);
        $this->assertSame('PARK窓口担当者', staff_kind(User::where('email', 'ses1@park.example')->first()));
        // 列・URL は変えない
        $this->assertTrue(Schema::hasColumn('ses_companies', 'rep_user_id'));
        $this->as('admin@park.example')->get('/admin/ses?view=company')->assertOk();
    }

    // ---------------- C 企業代表者 ----------------

    public function test_c6_migration_default_is_same_for_existing_companies(): void
    {
        $this->assertSame(0, DB::table('ses_companies')->where('ceo_same', '!=', 1)->count());
        $this->assertSame(0, DB::table('ses_companies')->whereNotNull('ceo_name')->count());
        // 列を足す前から居た企業と同じ形（列の省略 = 窓口に同じ）
        $id = DB::table('ses_companies')->insertGetId(['name' => '旧企業', 'kind' => 'ses', 'status' => 'active', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $r = DB::table('ses_companies')->find($id);
        $this->assertSame([1, null], [(int) $r->ceo_same, $r->ceo_name]);
    }

    public function test_c_existing_company_shows_rep_name_same_as_contact(): void
    {
        $s = DB::table('ses_companies')->find(1);
        $name = \App\Services\Registration::repName($s)[0];
        $this->assertNotEmpty($name);
        $h = $this->as('ses1@park.example')->get('/ses/company')->assertOk()->getContent();
        $this->assertStringContainsString('企業代表者', $h);
        $this->assertStringContainsString($name.'（窓口に同じ）', $h);
        $this->assertMatchesRegularExpression('#企業代表者</dt><dd>.*ST-\d+.*'.preg_quote($name, '#').'（窓口に同じ）#su', $h);
        // 足りない項目の画面に急に回されない
        $this->get('/ses')->assertOk();
        $h = $this->as('admin@park.example')->get('/admin/ses/1')->assertOk()->getContent();
        $this->assertStringContainsString($name.'（窓口に同じ）', $h);
    }

    public function test_c1_apply_validation_and_default(): void
    {
        $base = ['name' => '代表申込商事', 'contact_sei' => '申込', 'contact_mei' => '花子', 'email' => 'ceo-apply@example.com', 'agree' => '1'];
        $this->get('/apply')->assertOk()->assertSee('企業代表者')->assertSee('窓口に同じ')->assertSee('name="ceo_same" value="1" checked', false);
        // 記入で氏名が空
        $this->post('/apply', $base + ['ceo_same' => '0', 'ceo_name' => ''])->assertSessionHas('flash', fn ($f) => $f['msg'] === '企業代表者の氏名を入れてください。' && $f['type'] === 'bad');
        $this->assertSame(0, DB::table('ses_companies')->where('name', '代表申込商事')->count());
        // 51 文字は断る（50 まで）
        $this->post('/apply', $base + ['ceo_same' => '0', 'ceo_name' => str_repeat('あ', 51)])->assertSessionHas('flash.type', 'bad');
        $this->assertSame(0, DB::table('ses_companies')->where('name', '代表申込商事')->count());
        // 記入：通る
        $this->post('/apply', $base + ['ceo_same' => '0', 'ceo_name' => '  社長　一郎  '])->assertOk();
        $r = DB::table('ses_companies')->where('name', '代表申込商事')->first();
        $this->assertSame([0, '社長　一郎'], [(int) $r->ceo_same, $r->ceo_name]);
        // ceo_same が来ない（古い画面）は「窓口に同じ」
        $this->post('/apply', ['name' => '古い画面商事', 'contact_sei' => '古', 'contact_mei' => '太郎', 'email' => 'ceo-old@example.com', 'agree' => '1'])->assertOk();
        $r = DB::table('ses_companies')->where('name', '古い画面商事')->first();
        $this->assertSame([1, null], [(int) $r->ceo_same, $r->ceo_name]);
        // 窓口に同じなら、氏名が送られても使わない
        $this->post('/apply', ['name' => '同じ商事', 'contact_sei' => '同', 'contact_mei' => '太郎', 'email' => 'ceo-same@example.com', 'agree' => '1', 'ceo_same' => '1', 'ceo_name' => '使わない'])->assertOk();
        $r = DB::table('ses_companies')->where('name', '同じ商事')->first();
        $this->assertSame([1, null], [(int) $r->ceo_same, $r->ceo_name]);
        // 受付メールは企業名：、企業代表者は足さない
        $m = DB::table('mail_outbox')->where('to_email', 'ceo-apply@example.com')->first();
        $this->assertNotNull($m);
        $this->assertStringContainsString('企業名：', $m->body);
        $this->assertStringNotContainsString('会社名：', $m->body);
        $this->assertStringNotContainsString('企業代表者', $m->body);
    }

    public function test_c2_admin_register_approve_and_edit(): void
    {
        $this->as('admin@park.example');
        $this->get('/admin/ses/new')->assertOk()->assertSee('企業代表者');
        $p = ['name' => '管理登録商事', 'rep_sei' => '窓口', 'rep_mei' => '太郎', 'email' => 'ceo-admin@example.com'];
        $this->post('/admin/ses', $p + ['ceo_same' => '0', 'ceo_name' => ''])->assertSessionHas('flash', fn ($f) => $f['msg'] === '企業代表者の氏名を入れてください。');
        $this->assertSame(0, DB::table('ses_companies')->where('name', '管理登録商事')->count());
        $this->post('/admin/ses', $p + ['ceo_same' => '0', 'ceo_name' => '代表 三郎'])->assertRedirect();
        $id = (int) DB::table('ses_companies')->where('name', '管理登録商事')->value('id');
        $this->assertSame([0, '代表 三郎'], [(int) DB::table('ses_companies')->find($id)->ceo_same, DB::table('ses_companies')->find($id)->ceo_name]);
        // 表示：記入した氏名
        $h = $this->get("/admin/ses/$id")->assertOk()->getContent();
        $this->assertStringContainsString('代表 三郎', $h);
        $this->assertStringNotContainsString('（窓口に同じ）', explode('企業代表者', $h, 2)[1] ?? '');
        // 直し（仮登録中）：窓口に同じへ
        $this->get("/admin/ses/$id/edit")->assertOk()->assertSee('企業代表者')->assertSee('代表 三郎');
        $this->post("/admin/ses/$id", ['name' => '管理登録商事', 'rep_sei' => '窓口', 'rep_mei' => '太郎', 'email' => 'ceo-admin@example.com', 'ceo_same' => '1', 'ceo_name' => '残さない'])->assertRedirect("/admin/ses/$id");
        $r = DB::table('ses_companies')->find($id);
        $this->assertSame([1, null], [(int) $r->ceo_same, $r->ceo_name]);
        // 申込 → 承認：申込の値を初めから入れる
        $this->guest();
        $this->post('/apply', ['name' => '承認商事', 'contact_sei' => '申込', 'contact_mei' => '花子', 'email' => 'ceo-appr@example.com', 'agree' => '1', 'ceo_same' => '0', 'ceo_name' => '承認 代表'])->assertOk();
        $aid = (int) DB::table('ses_companies')->where('name', '承認商事')->value('id');
        $this->as('admin@park.example')->get("/admin/ses/$aid/approve")->assertOk()->assertSee('承認 代表')->assertSee('name="ceo_same" value="0" checked', false);
        $this->post("/admin/ses/$aid/approve", ['name' => '承認商事', 'rep_sei' => '代表', 'rep_mei' => '花子', 'email' => 'ceo-appr@example.com', 'ceo_same' => '0', 'ceo_name' => ''])->assertSessionHas('flash.type', 'bad');
        $this->post("/admin/ses/$aid/approve", ['name' => '承認商事', 'rep_sei' => '代表', 'rep_mei' => '花子', 'email' => 'ceo-appr@example.com', 'ceo_same' => '0', 'ceo_name' => '承認 代表'])->assertRedirect("/admin/ses/$aid");
        $this->assertSame('承認 代表', DB::table('ses_companies')->find($aid)->ceo_name);
        // 本登録後の編集（mode）：参加中の企業 1
        $this->get('/admin/ses/1/edit')->assertOk()->assertSee('企業代表者');
    }

    public function test_c2_company_edit_by_rep_and_ceo_validation(): void
    {
        $s = DB::table('ses_companies')->find(1);
        $in = ['name' => $s->name, 'rep_sei' => $s->rep_sei, 'rep_mei' => $s->rep_mei, 'phone' => $s->phone, 'postal_code' => $s->postal_code, 'address' => $s->address];
        $this->as('ses1@park.example')->get('/ses/company/edit')->assertOk()->assertSee('企業代表者');
        $this->post('/ses/company', $in + ['ceo_same' => '0', 'ceo_name' => ''])->assertSessionHas('flash', fn ($f) => $f['type'] === 'bad' && str_contains($f['msg'], '企業代表者'));
        $this->assertSame(1, (int) DB::table('ses_companies')->find(1)->ceo_same);
        $this->post('/ses/company', $in + ['ceo_same' => '0', 'ceo_name' => '直した 代表'])->assertRedirect(url('/ses/company'));
        $this->assertSame([0, '直した 代表'], [(int) DB::table('ses_companies')->find(1)->ceo_same, DB::table('ses_companies')->find(1)->ceo_name]);
        $h = $this->get('/ses/company')->assertOk()->getContent();
        $this->assertStringContainsString('直した 代表', $h);
        $h = $this->as('admin@park.example')->get('/admin/ses/1')->assertOk()->getContent();
        $this->assertStringContainsString('直した 代表', $h);
        // 窓口に戻す
        $this->as('ses1@park.example')->post('/ses/company', $in + ['ceo_same' => '1', 'ceo_name' => '消える'])->assertRedirect();
        $this->assertSame([1, null], [(int) DB::table('ses_companies')->find(1)->ceo_same, DB::table('ses_companies')->find(1)->ceo_name]);
    }

    public function test_c4_permissions(): void
    {
        $this->staff('mate@example.com', '同僚 一郎');
        $s = DB::table('ses_companies')->find(1);
        $in = ['name' => $s->name, 'rep_sei' => $s->rep_sei, 'rep_mei' => $s->rep_mei, 'phone' => $s->phone, 'postal_code' => $s->postal_code, 'address' => $s->address, 'ceo_same' => '0', 'ceo_name' => '勝手 代表'];
        // 一般担当者：見るだけ（編集画面・保存は 403）。表示はある
        $this->as('mate@example.com')->get('/ses/company')->assertOk()->assertSee('企業代表者')->assertDontSee('/ses/company/edit', false);
        $this->get('/ses/company/edit')->assertForbidden();
        $this->post('/ses/company', $in)->assertForbidden();
        $this->assertSame(1, (int) DB::table('ses_companies')->find(1)->ceo_same);
        // 他社の SES は、自社の画面にしか触れない（他社の企業情報は見えない）
        $h = $this->as('ses2@park.example')->get('/ses/company')->assertOk()->getContent();
        $this->assertStringNotContainsString('勝手 代表', $h);
        $this->get('/admin/ses/1')->assertForbidden();
        $this->post('/admin/ses/1', ['name' => 'x'])->assertForbidden();
        $this->post('/ses/company', $in)->assertRedirect();
        $this->assertSame(1, (int) DB::table('ses_companies')->find(1)->ceo_same, '他社の保存が企業 1 に届いた');
        $this->assertSame(0, DB::table('ses_companies')->find(2)->ceo_same);
        // エンジニア：企業代表者は見えない・触れない
        $this->as('eg1@park.example');
        foreach (['/eg', '/eg/offers', '/eg/applications', '/engineers/1'] as $p) {
            $r = $this->get($p);
            if ($r->getStatusCode() === 200) $this->assertStringNotContainsString('企業代表者', $r->getContent(), $p);
        }
        $this->get('/ses/company')->assertForbidden();
        $this->get('/admin/ses/1')->assertForbidden();
        $this->post('/ses/company', $in)->assertForbidden();
        $this->assertSame(1, (int) DB::table('ses_companies')->find(1)->ceo_same);
        // 管理者の画面はゲスト（未ログイン）で見えない
        $this->guest();
        $this->get('/admin/ses/1')->assertRedirect();
    }

    public function test_c5_same_follows_new_rep_and_written_stays(): void
    {
        $mate = $this->staff('mate@example.com', '同僚 一郎');
        $this->as('admin@park.example')->post('/admin/ses/1/rep', ['user_id' => $mate])->assertRedirect('/admin/ses/1');
        $this->assertSame('ok', session('flash')['type'] ?? null, (string) (session('flash')['msg'] ?? ''));
        $h = $this->get('/admin/ses/1')->assertOk()->getContent();
        $rep = \App\Services\Registration::repName(DB::table('ses_companies')->find(1))[0];
        $this->assertStringContainsString('同僚', (string) $rep);
        $this->assertStringContainsString($rep.'（窓口に同じ）', $h);
        // 通知文に一文が入る
        $texts = DB::table('app_notifications')->pluck('body')->merge(DB::table('mail_outbox')->pluck('body'))->implode("\n");
        $this->assertStringContainsString('企業代表者は「窓口に同じ」のため、企業代表者の表示も新しいPARK窓口担当者の氏名になります。', $texts);

        // 記入の企業は変わらず、一文も出ない
        DB::table('ses_companies')->where('id', 2)->update(['ceo_same' => 0, 'ceo_name' => '固定 代表']);
        $u2 = User::where('email', 'ses2@park.example')->first();
        $m2 = W::createUser('mate2@example.com', '二人目 次郎', 'ses', 'park1234', ['ses_id' => 2]);
        $this->completeProfile($m2);
        DB::table('mail_outbox')->delete();
        DB::table('app_notifications')->delete();
        $this->as('admin@park.example')->post('/admin/ses/2/rep', ['user_id' => $m2])->assertRedirect('/admin/ses/2');
        $this->assertStringContainsString('固定 代表', $this->get('/admin/ses/2')->getContent());
        $texts = DB::table('app_notifications')->pluck('body')->merge(DB::table('mail_outbox')->pluck('body'))->implode("\n");
        $this->assertStringNotContainsString('企業代表者は「窓口に同じ」', $texts);
    }

    public function test_c_ceo_label_helper(): void
    {
        $same = (object) ['ceo_same' => 1, 'ceo_name' => null, 'rep_sei' => '窓口', 'rep_mei' => '太郎', 'rep_name' => '窓口 太郎'];
        $this->assertSame('窓口 太郎（窓口に同じ）', ceo_label($same));
        $empty = (object) ['ceo_same' => 1, 'ceo_name' => null, 'rep_sei' => null, 'rep_mei' => null, 'rep_name' => null, 'rep_user_id' => null];
        $this->assertSame('—（窓口に同じ）', ceo_label($empty));
        $w = (object) ['ceo_same' => 0, 'ceo_name' => '記入 氏名'];
        $this->assertSame('記入 氏名', ceo_label($w));
    }

    // ---------------- D 状況の言葉 ----------------

    public function test_d_headings_and_legends(): void
    {
        $h = $this->as('admin@park.example')->get('/admin/ses?view=company')->assertOk()->getContent();
        $this->assertStringContainsString('参加状況', $h);
        $this->assertStringContainsString('<b>仮登録中</b>', $h);
        $this->assertStringContainsString('<b>申込済み</b>', $h);
        $h = $this->get('/admin/engineers')->assertOk()->getContent();
        foreach (['稼働状況', '登録状況', 'すべての稼働状況', '<b>待機中</b>', '<b>未送信</b>', '<b>エンジニアの項目が未入力</b>'] as $w) $this->assertStringContainsString($w, $h, $w);
        $this->assertStringNotContainsString('すべての状態', $h);
        // 稼働状況の絞り込みに「退会済み」を出さない
        if (preg_match('#<select[^>]*name="status".*?</select>#su', $h, $m)) $this->assertStringNotContainsString('退会済み', $m[0]);
        $this->get('/admin/engineers?status=left')->assertOk(); // 壊れたリンクにしない
        $h = $this->get('/admin/admins')->assertOk()->getContent();
        $this->assertStringContainsString('登録状況', $h);
        $this->assertStringContainsString('すべての登録状況', $this->get('/admin/ses')->assertOk()->getContent());
        $h = $this->get('/engineers/1')->assertOk()->getContent();
        $this->assertStringContainsString('稼働状況', $h);
        $this->assertStringContainsString('登録状況', $h);
        $h = $this->as('ses1@park.example')->get('/ses/company')->assertOk()->getContent();
        $this->assertStringContainsString('参加状況', $h);
        $this->assertStringContainsString('登録状況', $h);
        $h = $this->as('eg1@park.example')->get('/eg')->assertOk()->getContent();
        $this->assertStringContainsString('稼働状況', $h);
        $this->assertStringNotContainsString('今の状態', $h);
        // 凡例の語は .badge の span にしない
        $legend = view('components.status-legend', ['kind' => 'account', 'eg' => false])->render();
        $this->assertStringNotContainsString('badge', $legend);
        $this->assertStringNotContainsString('（期限切れ）</b>', $legend);
        $this->assertStringNotContainsString('<b>未送信</b>', $legend);
        $this->assertStringContainsString('<b>未送信</b>', view('components.status-legend', ['kind' => 'account', 'eg' => true])->render());
        $this->assertSame('', trim(view('components.status-legend', ['kind' => 'nothing'])->render()));
    }

    public function test_d3_suspended_engineer_state_and_left_work(): void
    {
        $e = (object) ['status' => 'available', 'suspended_at' => '2026-01-01 00:00:00', 'registered_at' => '2026-01-01'];
        $this->assertSame(PARK_ST_SUSPENDED, engineer_state($e, null));
        $this->assertSame(PARK_ST_SUSPENDED, engineer_state($e, (object) ['active' => 1, 'password' => 'x', 'left_at' => null, 'registered_at' => 'x']));
        $left = (object) ['status' => 'left', 'suspended_at' => '2026-01-01 00:00:00'];
        $this->assertSame(PARK_ST_LEFT, engineer_state($left, null));
        // 退会したエンジニアの稼働状況は「—」
        $this->assertStringContainsString('—', work_badge($left));
        $this->assertStringNotContainsString('停止中', work_badge($e));
        // 画面：停止したエンジニアは稼働状況の欄に「停止中」の札が出ない
        DB::table('engineers')->where('id', 1)->update(['suspended_at' => now_str()]);
        $h = $this->as('admin@park.example')->get('/engineers/1')->assertOk()->getContent();
        $this->assertStringContainsString('停止中', $h);
    }

    public function test_d5_other_statuses_unchanged(): void
    {
        $h = $this->as('admin@park.example')->get('/admin/cases')->assertOk()->getContent();
        $this->assertStringNotContainsString('参加状況', $h);
        $this->assertStringNotContainsString('稼働状況', $h);
        $this->assertStringContainsString('状態', $h);
        $this->get('/admin/applications')->assertOk();
        $this->get('/admin/contracts')->assertOk();
    }

    // ---------------- E 全体 ----------------

    public function test_e1_version_and_zero_cases_pages_open(): void
    {
        $this->assertSame('v0.23.0', config('park.version'));
        $this->assertStringContainsString('v0.23.0', $this->get('/login')->getContent());
        // 0 件でも開く（新しい凡例・企業代表者の行で壊れない）
        $this->as('admin@park.example')->get('/admin/engineers')->assertOk();
        $this->as('ses1@park.example')->get('/ses/engineers')->assertOk();
        $this->get('/ses/company')->assertOk();
    }
}
