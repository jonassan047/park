<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Workflow as W;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// v0.2.0 段階 3：通しのテスト（PLAN 2026-10-31 の S3-10・S3-12、S2-29）。
// S3-10：登録する立場ごとに 仮登録メール → 本登録（リンク）→ 確認コード → 参加中 → K25 の相手への知らせ → 一覧の状態 を1本ずつたどる。
// S3-12・S2-29：マニュアルに「招待」の古い言い方が残っていない／本登録の説明がある。アドレスはすべて example.com か見本のもの
class RegistrationFlowsTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    // 状態の表示としての「仮登録」（バッジなど、タグで囲まれた「仮登録」「仮登録（…）」）
    private const PROV = '/>\s*仮登録(（[^<]*）)?\s*</u';

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

    private function uid(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('id');
    }

    private function notes(int $userId): int
    {
        return DB::table('app_notifications')->where('user_id', $userId)->where('title', 'like', '本登録が完了しました%')->count();
    }

    private function mails(string $email): int
    {
        return DB::table('mail_outbox')->where('to_email', $email)->where('subject', 'like', '%本登録が完了しました%')->count();
    }

    // [登録する人, 登録される立場, 知らせが1通ずつ届く人（これ以外の人には 0 通）, 一覧の画面]
    public static function flows(): array
    {
        return [
            '管理者 → 管理者' => ['admin@park.example', 'admin', ['admin@park.example', 'admin2-flow@example.com'], ['/admin/admins']],
            '管理者 → SES' => ['admin@park.example', 'ses', ['admin@park.example', 'admin2-flow@example.com'], ['/admin/ses']],
            '管理者 → 担当者' => ['admin@park.example', 'staff', ['admin@park.example', 'ses1@park.example'], ['/admin/ses/1']],
            '管理者 → EG' => ['admin@park.example', 'eg', ['admin@park.example', 'ses1@park.example'], ['/admin/engineers']],
            'SES → 担当者' => ['ses1@park.example', 'staff', ['ses1@park.example'], ['/ses/company']],
            'SES → EG' => ['ses1@park.example', 'eg', ['ses1@park.example'], ['/ses/engineers']],
            '担当者 → EG' => ['mate-flow@example.com', 'eg', ['mate-flow@example.com', 'ses1@park.example'], ['/ses/engineers']],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('flows')]
    public function test_s3_10_registration_flow(string $by, string $kind, array $notified, array $lists): void
    {
        // 準備：2人目の管理者と、本登録が済んだ担当者
        $second = W::createUser('admin2-flow@example.com', '第二 管理', 'admin', 'park1234');
        $this->completeProfile($second);
        $mate = W::createUser('mate-flow@example.com', '同僚 一郎', 'ses', 'park1234', ['ses_id' => 1]);
        $this->completeProfile($mate);

        $email = 'newcomer-flow@example.com';
        $isAdmin = $by === 'admin@park.example';
        $sei = '通し';
        $mei = '花子';

        // 1 仮登録（登録する人が送る）
        $this->as($by);
        switch ($kind) {
            case 'admin':
                $this->post('/admin/admins', ['company_name' => '通し企業', 'name_sei' => $sei, 'name_mei' => $mei, 'email' => $email])->assertSessionHas('flash.type', 'ok');
                break;
            case 'ses':
                $this->post('/admin/ses', ['name' => '通し商事株式会社', 'rep_sei' => $sei, 'rep_mei' => $mei, 'email' => $email])->assertRedirect();
                break;
            case 'staff':
                $this->post($isAdmin ? '/admin/ses/1/users' : '/ses/company/users', ['name_sei' => $sei, 'name_mei' => $mei, 'email' => $email])->assertSessionHas('flash.type', 'ok');
                break;
            case 'eg':
                $this->post('/engineers', ($isAdmin ? ['ses_id' => '1'] : []) + ['name_sei' => $sei, 'name_mei' => $mei, 'email' => $email])->assertSessionHas('flash.type', 'ok');
                break;
        }
        $u = DB::table('users')->where('email', $email)->first();
        $this->assertNotNull($u, '仮登録の行がない');
        $this->assertNull($u->registered_at);
        $this->assertNull($u->password);
        $this->assertSame('register', $u->token_kind);
        $this->assertSame($this->uid($by), (int) $u->registered_by);

        // 2 仮登録メール：件名・リンク・期限。「ご招待」の古い言い方がない
        $m = DB::table('mail_outbox')->where('to_email', $email)->get();
        $this->assertCount(1, $m);
        $this->assertStringContainsString('仮登録のご案内', $m[0]->subject);
        $this->assertStringContainsString(url('/invite/'.$u->token), $m[0]->body);
        $this->assertStringContainsString('7日以内', $m[0]->body);
        $this->assertStringNotContainsString('ご招待', $m[0]->subject.$m[0]->body);

        // 3 仮登録のうちは、一覧に「仮登録」で出る。知らせはまだ誰にも届いていない
        foreach ($lists as $url) $this->assertMatchesRegularExpression(self::PROV, $this->get($url)->assertOk()->getContent(), $url);
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'like', '本登録が完了しました%')->count());

        // 4 リンクで本登録 → 確認コード
        $this->guest();
        $this->get('/invite/'.$u->token)->assertOk();
        $payload = match ($kind) {
            'admin' => ['company_name' => '通し企業', 'name_sei' => $sei, 'name_mei' => $mei, 'phone' => '03-1234-5678'],
            'ses' => ['ses_name' => '通し商事株式会社', 'name_sei' => $sei, 'name_mei' => $mei, 'phone' => '03-1234-5678', 'postal_code' => '100-0001', 'address' => '東京都千代田区1-1', 'agree' => '1'],
            'staff' => ['name_sei' => $sei, 'name_mei' => $mei, 'phone' => '03-1111-2222', 'agree' => '1'],
            'eg' => ['name_sei' => $sei, 'name_mei' => $mei, 'kana_sei' => 'とおし', 'kana_mei' => 'はなこ', 'phone' => '090-1111-2222', 'postal_code' => '100-0001', 'address' => '東京都千代田区1-1', 'consent' => '1'],
        };
        $this->post('/invite/'.$u->token, $payload + ['password' => 'password12', 'password_confirmation' => 'password12'])->assertRedirect(url('/two-factor/challenge'));
        // 確認コードの前は、まだ本登録になっていない・知らせも出ない
        $this->assertNull(DB::table('users')->find($u->id)->registered_at);
        $this->assertSame(0, DB::table('app_notifications')->where('title', 'like', '本登録が完了しました%')->count());
        $this->post('/two-factor/challenge', ['code' => $this->lastTwoFactorCode()])->assertRedirect();

        // 5 参加中
        $after = DB::table('users')->find($u->id);
        $this->assertNotNull($after->registered_at);
        $this->assertNotNull($after->password);
        $this->assertNull($after->token);
        $this->assertSame('参加中', user_state($after));
        if ($kind === 'ses') {
            $c = DB::table('ses_companies')->find($after->ses_id);
            $this->assertSame('active', $c->status);
            $this->assertNotNull($c->joined_at);
        }
        if ($kind === 'eg') {
            $this->assertNotNull(DB::table('engineers')->find($after->engineer_id)->registered_at);
        }
        // 本登録が済んだリンクは、もう使えない
        $this->guest();
        $this->get('/invite/'.$u->token)->assertDontSee('name="password"', false);

        // 6 K25 の相手への知らせ（ベルとメールが1通ずつ。本人と、それ以外の人には出ない）
        $ids = ['admin@park.example' => $this->uid('admin@park.example'), 'admin2-flow@example.com' => $second, 'ses1@park.example' => $this->uid('ses1@park.example'), 'mate-flow@example.com' => $mate];
        foreach ($ids as $e => $id) {
            $want = in_array($e, $notified, true) ? 1 : 0;
            $this->assertSame($want, $this->notes($id), "ベル $e");
            $this->assertSame($want, $this->mails($e), "メール $e");
        }
        $this->assertSame(0, $this->notes((int) $u->id), '本人にはベルを出さない');
        $this->assertSame(0, $this->mails($email), '本人にはメールを出さない');

        // 7 一覧の状態：参加中に変わり、送り直しは出ない
        $this->as($by);
        foreach ($lists as $url) {
            $html = $this->get($url)->assertOk()->assertSee($sei)->getContent();
            $this->assertDoesNotMatchRegularExpression(self::PROV, $html, $url); // 「SES を仮登録」のボタンの文字は数えない
            $this->assertStringNotContainsString('/people/'.$u->id.'/resend', $html, $url);
            $this->assertStringContainsString('参加中', $html, $url);
        }
    }

    // ---------- S3-12・S2-29：マニュアル ----------

    public function test_s3_12_manuals_have_no_old_invitation_words(): void
    {
        $kinds = ['admin', 'admin-steps', 'engineer', 'engineer-steps', 'ses', 'ses-steps'];
        // ファイルがここに書いた全部であること（増えたらテストにも足す）
        $files = array_map(fn ($p) => basename($p, '.md'), glob(base_path('resources/manuals/*.md')));
        sort($files);
        $this->assertSame($kinds, $files);

        $this->as('admin@park.example');
        foreach ($kinds as $k) {
            $html = $this->get("/manual/$k")->assertOk()->getContent();
            $this->assertStringNotContainsString('ご招待', $html, $k);
            $this->assertStringNotContainsString('パスワードを設定してください', $html, $k);
            $raw = file_get_contents(base_path("resources/manuals/$k.md"));
            $this->assertStringNotContainsString('ご招待', $raw, "$k.md");
            $this->assertStringNotContainsString('パスワードを設定してください', $raw, "$k.md");
        }
    }

    public function test_s2_29_ses_and_engineer_manuals_explain_full_registration(): void
    {
        $this->guest();
        foreach (['ses', 'engineer'] as $k) $this->get("/manual/$k")->assertOk()->assertSee('本登録');
        $this->as('ses1@park.example');
        foreach (['ses', 'engineer', 'ses-steps', 'engineer-steps'] as $k) $this->get("/manual/$k")->assertOk()->assertSee('本登録');
        $this->as('eg1@park.example');
        foreach (['engineer', 'engineer-steps'] as $k) $this->get("/manual/$k")->assertOk()->assertSee('本登録');
        // 管理者のマニュアルはほかの立場には出ない
        $this->get('/manual/admin')->assertForbidden();
        $this->get('/manual/admin-steps')->assertForbidden();
    }
}
