<?php

namespace Tests\Feature;

use App\Services\DirectMail as DM;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

// v0.8.0 連絡メール（PLAN.md 6章 T1〜T23）。件名・本文・名前・会社は、テストの中で作る架空のものだけ
class V080DirectMailTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    private const ADMIN = 'admin@park.example';
    private const SES1 = 'ses1@park.example';
    private const SES2 = 'ses2@park.example';
    private const SES3 = 'ses3@park.example';
    private const EG1 = 'eg1@park.example';
    private const EG2 = 'eg2@park.example';
    private const EG3 = 'eg3@park.example';

    private array $backupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupsBefore = glob(storage_path('app/backups/purge-samples-*.json')) ?: [];
    }

    protected function tearDown(): void
    {
        // T21 で作ったバックアップは消す
        foreach (glob(storage_path('app/backups/purge-samples-*.json')) ?: [] as $f) {
            if (!in_array($f, $this->backupsBefore, true)) @unlink($f);
        }
        parent::tearDown();
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function sesId(string $email): int
    {
        return (int) $this->user($email)->ses_id;
    }

    // 本登録が済んだ形のエンジニア（アカウント付き）。$over で engineers の列を上書きできる
    private function mkEg(string $sesEmail, string $name, string $email, array $over = []): int
    {
        $sid = $this->sesId($sesEmail);
        $id = W::createEngineer($sid, ['name' => $name, 'kana' => 'てすと たろう', 'email' => $email, 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        W::createUser($email, $name, 'eg', 'park1234', ['ses_id' => $sid, 'engineer_id' => $id]);
        return $id;
    }

    // 同じ会社の担当者（本登録済み）
    private function mkStaff(string $sesEmail, string $email, string $name): int
    {
        $uid = W::createUser($email, $name, 'ses', 'park1234', ['ses_id' => $this->sesId($sesEmail)]);
        DB::table('users')->where('id', $uid)->update(['secrecy_agreed_at' => now_str(), 'phone' => '03-0000-0004']);
        return $uid;
    }

    // エンジニアを停止する（本登録済みでなくなる＝送れなくなる）
    private function stop(int $eid): void
    {
        DB::table('engineers')->where('id', $eid)->update(['suspended_at' => now_str()]);
        DB::table('users')->where('engineer_id', $eid)->update(['active' => 0]);
    }

    private function send(string $as, array $ids, string $subject = '架空の件名', string $body = "架空の本文です。\n二行目です。", ?string $key = null): array
    {
        return DM::send($this->user($as), $ids, $subject, $body, $key ?? Str::random(32));
    }

    private function counts(): array
    {
        return [DB::table('direct_mails')->count(), DB::table('direct_mail_recipients')->count(), DB::table('mail_outbox')->count()];
    }

    public function test_t1_engineer_targets(): void
    {
        $eg1 = $this->engId(self::EG1); $eg2 = $this->engId(self::EG2); $eg3 = $this->engId(self::EG3);
        $prov = $this->mkEg(self::SES1, '仮登録 一郎', 'prov@example.test', ['registered_at' => null]);
        $stop = $this->mkEg(self::SES1, '停止 二郎', 'stop@example.test', ['suspended_at' => now_str()]);
        $left = $this->mkEg(self::SES1, '退会 三郎', 'left@example.test', ['status' => 'left']);

        // 管理者：全社の使える人。仮登録・停止・退会は unusable、いない id は outside
        $t = DM::engineerTargets($this->user(self::ADMIN), [$eg1, $eg2, $eg3, $prov, $stop, $left, 999999]);
        $this->assertSame([$eg1, $eg2, $eg3], array_keys($t['ok']));
        $this->assertEqualsCanonicalizing([$prov, $stop, $left], $t['unusable']);
        $this->assertSame([999999], $t['outside']);
        $this->assertSame('山田 太郎', $t['ok'][$eg1]->eg_name);

        // SES：自社だけ。他社は outside
        $t = DM::engineerTargets($this->user(self::SES1), [$eg1, $eg2, $prov]);
        $this->assertSame([$eg1], array_keys($t['ok']));
        $this->assertSame([$eg2], $t['outside']);
        $this->assertSame([$prov], $t['unusable']);

        // エンジニアは、この道では送れない
        $this->assertSame([$eg2], DM::engineerTargets($this->user(self::EG1), [$eg2])['outside']);

        // 自分自身（兼任の人）は宛先に出ない
        DB::table('users')->where('email', self::SES1)->update(['engineer_id' => $eg1]);
        $t = DM::engineerTargets($this->user(self::SES1), [$eg1]);
        $this->assertSame([$eg1], $t['outside']);
        $this->assertSame([], $t['ok']);
    }

    public function test_t2_staff_targets(): void
    {
        $rep = (int) $this->user(self::SES1)->id;
        $staff = $this->mkStaff(self::SES1, 'staff1@example.test', 'あ 担当');
        $stopped = $this->mkStaff(self::SES1, 'staff2@example.test', 'い 停止');
        DB::table('users')->where('id', $stopped)->update(['active' => 0]);

        // 自社の代表が先、使える担当者が続く。停止中の人・他社の人は出ない
        $this->assertSame([$rep, $staff], array_keys(DM::staffTargets($this->user(self::EG1))));

        // 直属は管理者
        DB::table('ses_companies')->where('id', $this->sesId(self::SES3))->update(['kind' => 'direct']);
        $this->assertSame([(int) $this->user(self::ADMIN)->id], array_keys(DM::staffTargets($this->user(self::EG3))));

        // 会社が停止中なら、その会社の人は出ない
        DB::table('ses_companies')->where('id', $this->sesId(self::SES2))->update(['status' => 'suspended']);
        $this->assertSame([], DM::staffTargets($this->user(self::EG2)));

        // 管理者・SES は、この道では使えない
        $this->assertSame([], DM::staffTargets($this->user(self::SES1)));
    }

    public function test_t3_send_by_ses(): void
    {
        $eg1 = $this->engId(self::EG1);
        $eg1b = $this->mkEg(self::SES1, '山本 二郎', 'eg1b@example.test');
        $res = $this->send(self::SES1, [$eg1, $eg1b], '架空の件名ABC', "架空の本文です。\nXYZ");
        $this->assertSame(2, $res['sent']);
        $id = $res['ok'];

        $mails = DB::table('mail_outbox')->where('kind', 'direct-mail')->get();
        $this->assertCount(2, $mails); // 宛先ごとに1通ずつ
        foreach ($mails as $m) {
            $this->assertStringContainsString('架空の件名ABC', $m->subject);
            $this->assertStringContainsString("XYZ", $m->body);
            $this->assertStringContainsString('様', $m->body);
            $this->assertStringContainsString('みどり情報システム 緑川 一郎 から、PARK の連絡メールでお送りしています。', $m->body);
            $this->assertStringContainsString(url("/mails/$id"), $m->body);
            $this->assertSame('みどり情報システム 緑川 一郎', $m->from_name);
        }
        $this->assertStringStartsWith('山田 太郎 様', DB::table('mail_outbox')->where('kind', 'direct-mail')->where('to_email', self::EG1)->value('body'));

        $dm = DB::table('direct_mails')->find($id);
        $this->assertSame(['ses', 2, 'みどり情報システム 緑川 一郎'], [$dm->from_role, (int) $dm->to_count, $dm->from_label]);
        $rec = DB::table('direct_mail_recipients')->where('direct_mail_id', $id)->get();
        $this->assertCount(2, $rec);
        foreach ($rec as $r) {
            $this->assertNotNull($r->outbox_id);
            $this->assertSame('eg', $r->to_role);
        }
        $audit = DB::table('audit_log')->where('action', 'direct-mail')->first();
        $this->assertNotNull($audit);
        $this->assertStringContainsString("#{$id}", $audit->detail);
        $this->assertStringNotContainsString('架空の件名ABC', $audit->detail); // 件名・本文は記録に書かない
    }

    public function test_t4_reply_to(): void
    {
        $eg1 = $this->engId(self::EG1); $eg2 = $this->engId(self::EG2);
        $rep1 = (int) $this->user(self::SES1)->id;
        // SES → 自社のエンジニア：送った人のアドレス
        $this->send(self::SES1, [$eg1]);
        $this->assertSame(self::SES1, DB::table('mail_outbox')->where('kind', 'direct-mail')->where('to_email', self::EG1)->value('reply_to'));
        // エンジニア → 自社の SES：エンジニアのアドレス
        $this->send(self::EG1, [$rep1]);
        $this->assertSame(self::EG1, DB::table('mail_outbox')->where('kind', 'direct-mail')->where('to_email', self::SES1)->value('reply_to'));
        // 管理者 → エンジニア：返信先を付けない（個人のアドレスを見せない）
        $this->send(self::ADMIN, [$eg2]);
        $this->assertNull(DB::table('mail_outbox')->where('kind', 'direct-mail')->where('to_email', self::EG2)->value('reply_to'));
        // 直属のエンジニア → 管理者：エンジニアのアドレス
        DB::table('ses_companies')->where('id', $this->sesId(self::SES3))->update(['kind' => 'direct']);
        $this->send(self::EG3, [(int) $this->user(self::ADMIN)->id]);
        $this->assertSame(self::EG3, DB::table('mail_outbox')->where('kind', 'direct-mail')->where('to_email', self::ADMIN)->value('reply_to'));

        // 別の会社どうしは付けない。管理者が送るときも付けない。管理者あては付ける
        $a = (object) ['role' => 'ses', 'ses_id' => 1, 'engineer_id' => null];
        $b = (object) ['role' => 'eg', 'ses_id' => 2, 'engineer_id' => null];
        $c = (object) ['role' => 'eg', 'ses_id' => 1, 'engineer_id' => null];
        $adm = (object) ['role' => 'admin', 'ses_id' => null, 'engineer_id' => null];
        $this->assertFalse(DM::replyToAllowed($a, $b));
        $this->assertTrue(DM::replyToAllowed($a, $c));
        $this->assertFalse(DM::replyToAllowed($adm, $c));
        $this->assertTrue(DM::replyToAllowed($b, $adm));
    }

    public function test_t5_notifications(): void
    {
        $eg1 = $this->engId(self::EG1);
        $otherBefore = DB::table('mail_outbox')->where('kind', '!=', 'direct-mail')->count();
        $res = $this->send(self::SES1, [$eg1]);
        $n = DB::table('app_notifications')->where('user_id', $this->user(self::EG1)->id)->where('link', '/mails/'.$res['ok'])->first();
        $this->assertNotNull($n);
        $this->assertSame('eg', $n->for_role);
        // お知らせのメールは重ねて送らない
        $this->assertSame($otherBefore, DB::table('mail_outbox')->where('kind', '!=', 'direct-mail')->count());
    }

    public function test_t6_limits(): void
    {
        $before = $this->counts();
        $eg1 = $this->engId(self::EG1);
        $this->assertArrayHasKey('bad', $this->send(self::ADMIN, range(1, 51)));
        $this->assertArrayHasKey('bad', $this->send(self::ADMIN, [$eg1], str_repeat('あ', 101)));
        $this->assertArrayHasKey('bad', $this->send(self::ADMIN, [$eg1], '件名', ''));
        $this->assertArrayHasKey('bad', $this->send(self::ADMIN, [$eg1], '', '本文'));
        $this->assertArrayHasKey('bad', $this->send(self::ADMIN, [$eg1], '件名', str_repeat('あ', 5001)));
        $this->assertSame($before, $this->counts());
        // 上限ちょうどは送れる
        $this->assertArrayHasKey('ok', $this->send(self::ADMIN, [$eg1], str_repeat('あ', 100), str_repeat('あ', 5000)));
    }

    public function test_t7_double_send(): void
    {
        $eg1 = $this->engId(self::EG1);
        $k = Str::random(32);
        $first = $this->send(self::SES1, [$eg1], '架空の件名', '本文', $k);
        $second = $this->send(self::SES1, [$eg1], '架空の件名', '本文', $k);
        $this->assertSame($first['ok'], $second['dup']);
        $this->assertSame(1, DB::table('direct_mails')->count());
        $this->assertSame(1, DB::table('mail_outbox')->where('kind', 'direct-mail')->count());
    }

    public function test_t8_dummy_address_goes_to_the_mailbox(): void
    {
        config(['mail.default' => 'smtp', 'park.dummy_mailbox' => 'box@example.test']);
        $cap = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $cb) use (&$cap) {
            $msg = new class {
                public $to; public $subject;
                public function to($a, $n = null) { $this->to = $a; return $this; }
                public function subject($s) { $this->subject = $s; return $this; }
                public function replyTo($a) { return $this; }
                public function from($a, $n = null) { return $this; }
            };
            $cb($msg);
            $cap = ['to' => $msg->to, 'subject' => $msg->subject];
        });
        $this->send(self::ADMIN, [$this->engId(self::EG1)]);
        $this->assertSame('box@example.test', $cap['to']);
        $this->assertStringContainsString('[ダミー宛：'.self::EG1.']', $cap['subject']);
    }

    public function test_t9_dual_account(): void
    {
        $sid = $this->sesId(self::SES1);
        $eD = W::createEngineer($sid, ['name' => '兼任 三郎', 'kana' => 'けんにん さぶろう', 'email' => 'dual@example.test', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user(self::ADMIN), false);
        DB::table('engineers')->where('id', $eD)->update(['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str()]);
        $uid = W::createUser('dual@example.test', '兼任 三郎', 'ses', 'park1234', ['ses_id' => $sid, 'engineer_id' => $eD]);

        $res = $this->send(self::ADMIN, [$eD]);
        $this->assertSame(1, $res['sent']);
        $this->assertSame(1, DB::table('mail_outbox')->where('kind', 'direct-mail')->where('to_email', 'dual@example.test')->count());
        $r = DB::table('direct_mail_recipients')->where('direct_mail_id', $res['ok'])->first();
        $this->assertSame([$uid, 'eg'], [(int) $r->to_user_id, $r->to_role]);
        $this->assertSame('eg', DB::table('app_notifications')->where('user_id', $uid)->value('for_role'));
    }

    public function test_t10_compose_screen(): void
    {
        $eg1 = $this->engId(self::EG1);
        config(['mail.default' => 'smtp']);
        $this->as(self::ADMIN)->get('/mails/new?eg[]='.$eg1)->assertOk()
            ->assertSee('山田 太郎（みどり情報システム）')->assertSee('name="subject"', false)->assertSee('name="body"', false)
            ->assertSee('name="send_key"', false)->assertSee('名に送ります。よろしいですか', false)->assertDontSee('保存のみ');
        config(['mail.default' => 'log']);
        $this->as(self::ADMIN)->get('/mails/new?eg[]='.$eg1)->assertOk()->assertSee('保存のみ');
        // 選んでいなければ、一覧へ戻す
        $this->as(self::ADMIN)->get('/mails/new')->assertRedirect(url('/admin/engineers'));
    }

    public function test_p5_guest_goes_to_login(): void
    {
        $before = $this->counts();
        $this->get('/mails')->assertRedirect();
        $this->get('/mails/new')->assertRedirect();
        $this->post('/mails', ['eg' => [1], 'subject' => '件名', 'body' => '本文', 'send_key' => Str::random(32)])->assertRedirect();
        $this->assertSame($before, $this->counts());
    }

    public function test_t11_post_goes_to_the_screen(): void
    {
        $eg1 = $this->engId(self::EG1); $eg2 = $this->engId(self::EG2);
        $k = Str::random(32);
        $this->as(self::ADMIN)->post('/mails', ['eg' => [$eg1, $eg2], 'subject' => '架空の件名', 'body' => '架空の本文', 'send_key' => $k])->assertSessionHas('flash.msg', '2名に連絡メールを送りました。');
        $id = (int) DB::table('direct_mails')->value('id');
        $this->as(self::ADMIN)->post('/mails', ['eg' => [$eg1, $eg2], 'subject' => '架空の件名', 'body' => '架空の本文', 'send_key' => $k])
            ->assertRedirect(url("/mails/$id"))->assertSessionHas('flash.msg', 'この連絡メールは、もう送っています。');
        $this->assertSame(1, DB::table('direct_mails')->count());
        $this->as(self::ADMIN)->get("/mails/$id")->assertOk()->assertSee('架空の件名');
    }

    public function test_t12_lists(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->send(self::SES1, [$eg1], '架空の連絡Q');
        $this->as(self::SES1)->get('/mails?box=out')->assertOk()->assertSee('架空の連絡Q');
        $this->as(self::EG1)->get('/mails')->assertOk()->assertSee('架空の連絡Q');
        $r = $this->as(self::ADMIN)->get('/mails?box=all')->assertOk()->assertSee('架空の連絡Q');
        $this->assertSame('all', $r->viewData('box'));
        // 管理者でなければ「すべて」は「受け取った」になる
        $r = $this->as(self::SES1)->get('/mails?box=all')->assertOk()->assertDontSee('架空の連絡Q');
        $this->assertSame('in', $r->viewData('box'));
        $this->as(self::SES2)->get('/mails?box=out')->assertOk()->assertDontSee('架空の連絡Q');
    }

    public function test_t13_show_and_recipient_visibility(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->mkEg(self::SES1, '山本 二郎', 'eg1b@example.test');
        $eg1b = (int) DB::table('engineers')->where('email', 'eg1b@example.test')->value('id');
        $res = $this->send(self::SES1, [$eg1, $eg1b], '架空の件名', "架空の本文Z");
        $id = $res['ok'];
        $this->as(self::SES1)->get("/mails/$id")->assertOk()->assertSee('架空の本文Z')->assertSee('山田 太郎')->assertSee('山本 二郎');
        $this->as(self::ADMIN)->get("/mails/$id")->assertOk()->assertSee('山田 太郎')->assertSee('山本 二郎');
        // 受け取った人には、ほかの宛先の名前は出ない
        $this->as(self::EG1)->get("/mails/$id")->assertOk()->assertSee('架空の本文Z')->assertDontSee('山本 二郎');
    }

    public function test_t14_ses_cannot_reach_other_company(): void
    {
        $eg1 = $this->engId(self::EG1); $eg2 = $this->engId(self::EG2);
        $before = $this->counts();
        $this->as(self::SES1)->get('/mails/new?eg[]='.$eg2)->assertForbidden();
        $this->as(self::SES1)->get('/mails/new?eg[]='.$eg1.'&eg[]='.$eg2)->assertForbidden();
        $this->as(self::SES1)->post('/mails', ['eg' => [$eg1, $eg2], 'subject' => '件名', 'body' => '本文', 'send_key' => Str::random(32)])->assertForbidden();
        $this->assertSame($before, $this->counts());
    }

    public function test_t15_engineer_cannot_reach_outside(): void
    {
        $before = $this->counts();
        $post = fn (array $to) => $this->as(self::EG1)->post('/mails', ['to' => $to, 'subject' => '件名', 'body' => '本文', 'send_key' => Str::random(32)]);
        $post([(int) $this->user(self::SES2)->id])->assertForbidden();   // 他社の SES
        $post([(int) $this->user(self::ADMIN)->id])->assertForbidden();  // 直属でないので、管理者には送れない
        $post([(int) $this->user(self::EG2)->id])->assertForbidden();    // ほかのエンジニア
        $this->assertSame($before, $this->counts());
        // 自社の SES には送れる
        $post([(int) $this->user(self::SES1)->id])->assertRedirect();
        $this->assertSame($before[0] + 1, DB::table('direct_mails')->count());
    }

    public function test_t16_skipped_and_empty(): void
    {
        $eg1 = $this->engId(self::EG1);
        $eg1b = $this->mkEg(self::SES1, '山本 二郎', 'eg1b@example.test');
        $this->stop($eg1b); // 選んだあとで、1人が停止になった
        $this->as(self::SES1)->post('/mails', ['eg' => [$eg1, $eg1b], 'subject' => '件名', 'body' => '本文', 'send_key' => Str::random(32)])
            ->assertSessionHas('flash.msg', '1名に連絡メールを送りました。送れない 1 名を外しました。');
        $this->assertSame(1, DB::table('direct_mail_recipients')->count());
        // 全員が送れない
        $before = $this->counts();
        $this->as(self::SES1)->post('/mails', ['eg' => [$eg1b], 'subject' => '件名', 'body' => '本文', 'send_key' => Str::random(32)])
            ->assertSessionHas('flash.msg', '送れる相手がいません。');
        // 何も選ばない
        $this->as(self::SES1)->post('/mails', ['subject' => '件名', 'body' => '本文', 'send_key' => Str::random(32)])
            ->assertRedirect(url('/ses/engineers'))->assertSessionHas('flash.msg', '送る相手を選んでください。');
        $this->assertSame($before, $this->counts());
    }

    public function test_t17_others_get_404(): void
    {
        $eg1 = $this->engId(self::EG1);
        $this->mkStaff(self::SES1, 'staff1@example.test', 'あ 担当');
        $id = $this->send(self::SES1, [$eg1])['ok'];
        foreach ([self::SES2, 'staff1@example.test', self::EG2] as $other) {
            $this->as($other)->get("/mails/$id")->assertNotFound();
        }
        foreach ([self::SES1, self::EG1, self::ADMIN] as $ok) {
            $this->as($ok)->get("/mails/$id")->assertOk();
        }
    }

    public function test_t18_admin_list_checkboxes(): void
    {
        $eg1 = $this->engId(self::EG1);
        $prov = $this->mkEg(self::SES1, '仮登録 一郎', 'prov@example.test', ['registered_at' => null]);
        $stop = $this->mkEg(self::SES1, '停止 二郎', 'stop@example.test', ['suspended_at' => now_str()]);
        $r = $this->as(self::ADMIN)->get('/admin/engineers')->assertOk()
            ->assertSee('name="eg[]" value="'.$eg1.'" form="dm"', false)
            ->assertDontSee('value="'.$prov.'" form="dm"', false)->assertDontSee('value="'.$stop.'" form="dm"', false)
            ->assertSee('全選択')->assertSee('選んだ人にメールを送る')->assertSee('id="dm"', false);
        $this->assertArrayHasKey($eg1, $r->viewData('mailable'));
    }

    public function test_t19_ses_list_and_detail(): void
    {
        $eg1 = $this->engId(self::EG1); $eg2 = $this->engId(self::EG2);
        $stop = $this->mkEg(self::SES1, '停止 二郎', 'stop@example.test', ['suspended_at' => now_str()]);
        $staff = $this->mkStaff(self::SES1, 'staff1@example.test', 'あ 担当');
        $this->as('staff1@example.test')->get('/ses/engineers')->assertOk()
            ->assertSee('name="eg[]" value="'.$eg1.'" form="dm"', false)->assertDontSee('value="'.$stop.'" form="dm"', false)->assertSee('選んだ人にメールを送る');
        $this->as('staff1@example.test')->get('/engineers/'.$eg1)->assertOk()->assertSee('mails/new?eg[]='.$eg1, false);
        $this->as('staff1@example.test')->get('/engineers/'.$stop)->assertOk()->assertDontSee('mails/new?eg[]=', false);
        $this->as(self::ADMIN)->get('/engineers/'.$eg2)->assertOk()->assertSee('mails/new?eg[]='.$eg2, false);
        // 本人には出さない
        $this->as(self::EG1)->get('/engineers/'.$eg1)->assertOk()->assertDontSee('mails/new?eg[]=', false);
    }

    public function test_t20_engineer_home_and_compose(): void
    {
        $this->as(self::EG1)->get('/eg')->assertOk()->assertSee('担当者にメールを送る');
        $this->as(self::EG1)->get('/mails/new')->assertOk()->assertSee('みどり情報システム 緑川 一郎')->assertSee('（代表）');
        // 直属は管理者
        DB::table('ses_companies')->where('id', $this->sesId(self::SES3))->update(['kind' => 'direct']);
        $this->as(self::EG3)->get('/mails/new')->assertOk()->assertSee('（管理者）');
        // 送れる相手がいない
        DB::table('users')->where('ses_id', $this->sesId(self::SES2))->where('role', 'ses')->update(['active' => 0]);
        $this->as(self::EG2)->get('/mails/new')->assertOk()->assertSee('今は送れる相手がいません');
    }

    public function test_t21_sample_purge(): void
    {
        $eg1 = $this->engId(self::EG1); $eg2 = $this->engId(self::EG2);
        $realId = W::createUser('real-admin@example.com', '実在 管理', 'admin', 'abcd1234');
        $real = DB::table('users')->find($realId);
        $sampleMail = $this->send(self::ADMIN, [$eg2])['ok'];            // 見本の人が送ったもの：消える
        $realMail = DM::send($real, [$eg1], '架空の件名', '本文', Str::random(32))['ok']; // 見本の人あて：宛先の行だけ消える
        $plan = \App\Services\SamplePurge::plan();
        $this->assertContains($sampleMail, $plan['del']['direct_mails']);
        $this->assertNotContains($realMail, $plan['del']['direct_mails']);
        $this->assertSame(2, count($plan['del']['direct_mail_recipients']));

        $this->assertSame(0, Artisan::call('park:purge-samples', ['--yes' => true]));
        $this->assertSame([$realMail], DB::table('direct_mails')->pluck('id')->map(fn ($i) => (int) $i)->all());
        $this->assertSame(0, DB::table('direct_mail_recipients')->count());
    }

    public function test_t22_version(): void
    {
        $this->assertSame('v0.9.0', config('park.version'));
        $this->get('/login')->assertOk()->assertSee('v0.9.0');
    }

    public function test_t23_existing_mail_flows_remain(): void
    {
        // 案内メール・やり取りの入口は、今までどおり開ける
        $eg1 = $this->engId(self::EG1);
        $this->as(self::EG1)->get('/eg/messages')->assertRedirect();
        $this->as(self::ADMIN)->get('/engineers/'.$eg1.'/messages')->assertOk();
        $this->as(self::ADMIN)->get('/admin/mail')->assertOk();
    }
}
