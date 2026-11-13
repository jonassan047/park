<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Matcher;
use App\Services\Notifier as N;
use App\Services\Registration;
use App\Services\Workflow as W;
use App\Support\RegistrationText as T;
use App\Support\Requirements;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

// v0.2.0 仮登録 → 本登録の土台（PLAN 2026-10-31 の S1-01〜S1-10・S1-29〜S1-32・S1-34）
class RegistrationFoundationTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function as(string $email): static
    {
        $u = User::where('email', $email)->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    // 移行の前の形の人（本登録の日時などの新しい列は空）を足す
    private function oldUser(string $email, array $cols = []): int
    {
        // 引数の列を先にして、既定の値（active=1 など）を上書きできるようにする
        return DB::table('users')->insertGetId($cols + ['email' => $email, 'name' => 'テスト 太郎', 'role' => 'ses', 'active' => 1, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
    }

    private function company(string $name): int
    {
        return DB::table('ses_companies')->insertGetId(['name' => $name, 'kind' => 'ses', 'status' => 'active', 'rep_name' => '手書きの SES', 'created_at' => now_str(), 'updated_at' => now_str()]);
    }

    public function test_s1_01_version_is_in_the_footer(): void
    {
        $v = config('park.version');
        $this->assertSame('v0.10.1', $v);
        $this->get('/login')->assertOk()->assertSee($v);
        $this->as('admin@park.example')->get('/admin')->assertOk()->assertSee($v);
    }

    public function test_s1_02_columns_exist(): void
    {
        foreach (['registered_at', 'registered_by', 'token_kind', 'reg_declined_at', 'secrecy_agreed_at'] as $c) $this->assertTrue(Schema::hasColumn('users', $c), "users.$c");
        foreach (['rep_user_id', 'next_rep_user_id'] as $c) $this->assertTrue(Schema::hasColumn('ses_companies', $c), "ses_companies.$c");
        $this->assertTrue(Schema::hasColumn('engineers', 'registered_at'));
    }

    // ---------- S1-03・S1-04：今いる行の移し替え ----------
    public function test_s1_03_existing_people_are_migrated_without_side_effects(): void
    {
        DB::table('users')->update(['registered_at' => null, 'token_kind' => null]);
        $a = $this->oldUser('a@example.com', ['password' => 'x', 'two_factor_confirmed_at' => '2026-02-01 00:00:00', 'profile_completed_at' => '2026-02-02 00:00:00']);
        $b = $this->oldUser('b@example.com', ['password' => 'x', 'profile_completed_at' => '2026-03-01 00:00:00', 'last_login' => '2026-03-02 00:00:00']);
        $c = $this->oldUser('c@example.com', ['password' => 'x', 'last_login' => '2026-04-01 00:00:00']);
        $d = $this->oldUser('d@example.com', ['password' => 'x']);
        $e = $this->oldUser('e@example.com'); // パスワードなし＝仮登録
        $f = $this->oldUser('f@example.com', ['password' => 'x', 'active' => 0]); // 停止中
        $g = $this->oldUser('g@example.com', ['password' => 'x', 'active' => 0, 'left_at' => '2026-05-01 00:00:00']); // 退会済
        $h = $this->oldUser('h@example.com', ['password' => 'x', 'token' => 'tok-h', 'token_expires' => '2030-01-01 00:00:00']); // 再設定のリンク
        $i = $this->oldUser('i@example.com', ['token' => 'tok-i', 'token_expires' => '2030-01-01 00:00:00']); // 本登録のリンク
        $mails = DB::table('mail_outbox')->count(); $notes = DB::table('app_notifications')->count(); $users = DB::table('users')->count();
        $phones = DB::table('users')->pluck('phone', 'id')->all();

        Registration::migrateExisting();

        $row = fn ($id) => DB::table('users')->find($id);
        $this->assertSame('2026-02-01 00:00:00', $row($a)->registered_at);
        $this->assertSame('2026-03-01 00:00:00', $row($b)->registered_at);
        $this->assertSame('2026-04-01 00:00:00', $row($c)->registered_at);
        $this->assertSame('2026-01-01 00:00:00', $row($d)->registered_at);
        $this->assertNull($row($e)->registered_at);
        $this->assertNotNull($row($f)->registered_at);
        $this->assertNotNull($row($g)->registered_at);
        $this->assertSame('reset', $row($h)->token_kind);
        $this->assertSame('register', $row($i)->token_kind);
        // 管理者 TEZZO のような今の人も本登録済みになり、既にある列は変わらない
        $this->assertSame(0, DB::table('users')->whereNotNull('password')->whereNull('registered_at')->count());
        $this->assertSame($phones, DB::table('users')->whereIn('id', array_keys($phones))->pluck('phone', 'id')->all());
        $this->assertSame($mails, DB::table('mail_outbox')->count());
        $this->assertSame($notes, DB::table('app_notifications')->count());
        $this->assertSame($users, DB::table('users')->count());

        // 2回流しても同じ
        $before = DB::table('users')->orderBy('id')->get()->toJson();
        Registration::migrateExisting();
        $this->assertSame($before, DB::table('users')->orderBy('id')->get()->toJson());
    }

    public function test_s1_04_representatives_and_engineers_are_migrated(): void
    {
        DB::table('ses_companies')->update(['rep_user_id' => null]);
        DB::table('engineers')->update(['registered_at' => null]);
        DB::table('users')->update(['registered_at' => null]);

        $c1 = $this->company('移行1'); $s1a = $this->oldUser('s1a@example.com', ['password' => 'x', 'ses_id' => $c1]); $this->oldUser('s1b@example.com', ['password' => 'x', 'ses_id' => $c1]);
        $c2 = $this->company('移行2'); $this->oldUser('s2a@example.com', ['password' => 'x', 'ses_id' => $c2, 'active' => 0]); $s2b = $this->oldUser('s2b@example.com', ['password' => 'x', 'ses_id' => $c2]);
        $c3 = $this->company('移行3'); $this->oldUser('s3a@example.com', ['ses_id' => $c3]); $s3b = $this->oldUser('s3b@example.com', ['password' => 'x', 'ses_id' => $c3]); // 仮登録の人が先にいても、本登録済みの人
        $c4 = $this->company('移行4'); $s4a = $this->oldUser('s4a@example.com', ['ses_id' => $c4]); // 仮登録の人だけ
        $c5 = $this->company('移行5'); // 担当者がいない

        Registration::migrateExisting();

        $rep = fn ($id) => DB::table('ses_companies')->where('id', $id)->value('rep_user_id');
        $this->assertSame($s1a, $rep($c1));
        $this->assertSame($s2b, $rep($c2));
        $this->assertSame($s3b, $rep($c3));
        $this->assertSame($s4a, $rep($c4));
        $this->assertNull($rep($c5));
        $this->assertSame('手書きの SES', DB::table('ses_companies')->where('id', $c1)->value('rep_name')); // rep_* には書かない

        // エンジニア：ログインのある人は日時が入り、ログインのない人は空
        $withLogin = User::where('email', 'eg1@park.example')->value('engineer_id');
        $noLogin = DB::table('engineers')->whereNotIn('id', DB::table('users')->whereNotNull('engineer_id')->pluck('engineer_id')->all())->value('id');
        $this->assertNotNull(DB::table('engineers')->where('id', $withLogin)->value('registered_at'));
        $this->assertNotNull($noLogin);
        $this->assertNull(DB::table('engineers')->where('id', $noLogin)->value('registered_at'));
    }

    // ---------- S1-05：今いる人が締め出されない ----------
    public function test_s1_05_migrated_admin_completes_missing_items_and_enters(): void
    {
        DB::table('users')->where('email', 'admin@park.example')->update(['phone' => null, 'two_factor_confirmed_at' => now_str()]);
        $this->as('admin@park.example')->get('/admin')->assertRedirect('/register/complete');
        $this->get('/register/complete')->assertOk()->assertSee('電話番号');
        $this->post('/register/complete', ['phone' => '03-1111-2222'])->assertRedirect();
        $this->assertSame('03-1111-2222', $this->user('admin@park.example')->phone);
        $this->get('/admin')->assertOk();
    }

    public function test_s1_05_migrated_rep_and_engineer_are_asked_for_missing_items(): void
    {
        $sid = $this->user('ses1@park.example')->ses_id;
        DB::table('ses_companies')->where('id', $sid)->update(['address' => null, 'agreed_at' => null]);
        $this->as('ses1@park.example')->get('/ses')->assertRedirect('/register/complete');
        $this->get('/register/complete')->assertSee('会社の住所')->assertSee('守秘義務');
        $this->post('/register/complete', ['address' => '東京都新宿区1-1'])->assertSessionHasErrors('agree');
        $this->post('/register/complete', ['address' => '東京都新宿区1-1', 'agree' => '1'])->assertRedirect();
        $s = DB::table('ses_companies')->find($sid);
        $this->assertSame('東京都新宿区1-1', $s->address);
        $this->assertNotNull($s->agreed_at);
        $this->get('/ses')->assertOk();

        $eid = $this->user('eg1@park.example')->engineer_id;
        DB::table('engineers')->where('id', $eid)->update(['postal_code' => null, 'consent_at' => null]);
        $this->as('eg1@park.example')->get('/eg')->assertRedirect('/register/complete');
        $this->post('/register/complete', ['postal_code' => '123-4567', 'consent' => '1'])->assertRedirect();
        $e = DB::table('engineers')->find($eid);
        $this->assertSame('123-4567', $e->postal_code);
        $this->assertNotNull($e->consent_at);
        $this->get('/eg')->assertOk();
    }

    // ---------- S1-29：必須を保つ ----------
    public function test_s1_29_missing_person_cannot_open_account_and_required_fields_cannot_be_emptied(): void
    {
        DB::table('users')->where('email', 'ses1@park.example')->update(['phone' => null]);
        // SES（ses1）は会社の電話が必須で、本人の電話は必須ではない
        $this->as('ses1@park.example')->get('/account')->assertOk();
        // 担当者（会社の2人目）は電話が必須：足りないと /account も開けない
        $sid = $this->user('ses1@park.example')->ses_id;
        $uid = W::createUser('staff@example.com', '担当 花子', 'ses', 'park1234', ['ses_id' => $sid]);
        DB::table('users')->where('id', $uid)->update(['secrecy_agreed_at' => now_str()]);
        $this->as('staff@example.com')->get('/account')->assertRedirect('/register/complete');
        $this->post('/account', ['name_sei' => '変更'])->assertRedirect('/register/complete');
        $this->assertSame('担当', $this->user('staff@example.com')->name_sei);
        // 電話が入った担当者でも、姓や電話を空にする保存は断る
        DB::table('users')->where('id', $uid)->update(['phone' => '03-0000-0000']);
        $this->as('staff@example.com')->post('/account', ['name_sei' => '', 'name_mei' => '花子', 'phone' => '03-0000-0000'])->assertRedirect();
        $this->assertSame('担当', $this->user('staff@example.com')->name_sei);
        $this->post('/account', ['name_sei' => '担当', 'name_mei' => '花子', 'phone' => ''])->assertSessionHas('flash');
        $this->assertSame('03-0000-0000', $this->user('staff@example.com')->phone);
    }

    public function test_s1_29_company_and_admin_edit_refuse_empty_required_fields(): void
    {
        $sid = $this->user('ses1@park.example')->ses_id;
        $before = DB::table('ses_companies')->find($sid);
        $base = ['name' => $before->name, 'rep_sei' => '緑川', 'rep_mei' => '一郎', 'phone' => $before->phone, 'postal_code' => '100-0001', 'address' => $before->address];
        $this->as('ses1@park.example')->post('/ses/company', ['phone' => ''] + $base)->assertSessionHas('flash');
        $this->assertSame($before->phone, DB::table('ses_companies')->find($sid)->phone);
        $this->as('admin@park.example')->post("/admin/ses/$sid", ['address' => ''] + $base)->assertSessionHas('flash');
        $this->assertSame($before->address, DB::table('ses_companies')->find($sid)->address);
        $this->as('ses1@park.example')->post('/ses/company', ['phone' => '03-9999-0000'] + $base)->assertRedirect('/ses/company');
        $this->assertSame('03-9999-0000', DB::table('ses_companies')->find($sid)->phone);
    }

    // ---------- S1-30：企業名・SES の欄は SES と管理者だけ ----------
    public function test_s1_30_only_rep_and_admin_change_company(): void
    {
        $sid = $this->user('ses1@park.example')->ses_id;
        $uid = W::createUser('staff2@example.com', '担当 次郎', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        $s = DB::table('ses_companies')->find($sid);
        $this->as('staff2@example.com')->get('/ses/company')->assertOk()->assertDontSee('/ses/company/edit', false);
        $this->get('/ses/company/edit')->assertForbidden();
        $this->post('/ses/company', ['name' => '乗っ取り', 'rep_sei' => 'x', 'rep_mei' => 'y', 'phone' => '1', 'postal_code' => '100-0001', 'address' => 'x'])->assertForbidden();
        // アカウントの画面から企業名は変えられない
        $this->post('/account', ['name_sei' => '担当', 'name_mei' => '次郎', 'phone' => '03-0000-0009', 'ses_name' => '乗っ取り'])->assertRedirect();
        $this->assertSame($s->name, DB::table('ses_companies')->find($sid)->name);
        // SES は /ses/company と /account から姓を直せて、会社の SES の欄も同じになる
        $this->as('ses1@park.example')->get('/ses/company')->assertOk()->assertSee('/ses/company/edit', false);
        $this->post('/ses/company', ['name' => $s->name, 'rep_sei' => '新緑', 'rep_mei' => '一郎', 'phone' => $s->phone, 'postal_code' => '100-0001', 'address' => $s->address])->assertRedirect('/ses/company');
        $this->assertSame('新緑', DB::table('ses_companies')->find($sid)->rep_sei);
        $this->assertSame('新緑', $this->user('ses1@park.example')->name_sei);
        $this->post('/account', ['name_sei' => '若葉', 'name_mei' => '一郎'])->assertRedirect('/account');
        $this->assertSame('若葉', DB::table('ses_companies')->find($sid)->rep_sei);
        $this->as('admin@park.example')->post("/admin/ses/$sid", ['name' => $s->name, 'rep_sei' => '深緑', 'rep_mei' => '一郎', 'phone' => $s->phone, 'postal_code' => '100-0001', 'address' => $s->address])->assertRedirect();
        $this->assertSame('深緑', DB::table('ses_companies')->find($sid)->rep_sei);
        $this->assertSame('深緑', $this->user('ses1@park.example')->name_sei);
        // 一覧・詳細に contact_* は出さない
        $this->get('/admin/ses')->assertDontSee($s->contact_name);
    }

    // ---------- S1-31：判定は立場ごと ----------
    public function test_s1_31_share_targets_need_a_completed_registration(): void
    {
        $sid = $this->user('ses1@park.example')->ses_id;
        $uid = W::createUser('dual@example.com', '兼任 太郎', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        DB::table('users')->where('id', $uid)->update(['registered_at' => null]);
        $this->assertNull(W::adminShareTarget('dual@example.com'));
        $this->assertNull(W::egShareTarget('dual@example.com', (int) $sid, (object) ['role' => 'admin']));
        DB::table('users')->where('id', $uid)->update(['registered_at' => now_str()]);
        $this->assertNotNull(W::egShareTarget('dual@example.com', (int) $sid, (object) ['role' => 'admin']));
    }

    public function test_s1_31_dual_person_is_checked_per_role(): void
    {
        $sid = $this->user('ses1@park.example')->ses_id;
        $uid = W::createUser('dual2@example.com', '兼任 花子', 'ses', 'park1234', ['ses_id' => $sid]);
        $this->completeProfile($uid);
        $eid = W::createEngineer($sid, ['name' => '兼任 花子', 'email' => 'dual2@example.com'], (object) ['id' => null, 'role' => 'admin'], false);
        DB::table('users')->where('id', $uid)->update(['engineer_id' => $eid]);
        // 担当者の立場は入れる
        $this->as('dual2@example.com')->withSession(['acting_role' => 'ses', TwoFactor::SESSION_KEY => $uid])->get('/ses')->assertOk();
        // エンジニアの立場では、住所などが足りないので足りない項目の画面に回る
        $this->as('dual2@example.com')->withSession(['acting_role' => 'eg', TwoFactor::SESSION_KEY => $uid])->get('/eg')->assertRedirect('/register/complete');
        $this->post('/register/complete', ['kana_sei' => 'けんにん', 'kana_mei' => 'はなこ', 'phone' => '090-0000-0000', 'postal_code' => '100-0001', 'address' => '東京都', 'consent' => '1'])->assertRedirect();
        $this->assertNotNull(DB::table('engineers')->find($eid)->registered_at); // 完了で候補に出る
        $this->get('/eg')->assertOk();
    }

    // ---------- S1-32：必須項目の表 ----------
    public static function requirementRows(): array
    {
        // [立場, SES か, 項目, 必須か, mode, しまう場所]
        return [
            ['admin', false, 'company_name', true, 'confirm', 'users'], ['admin', false, 'name_sei', true, 'confirm', 'users'], ['admin', false, 'name_mei', true, 'confirm', 'users'],
            ['admin', false, 'email', false, 'view', 'users'], ['admin', false, 'phone', true, 'self', 'users'], ['admin', false, 'kana_sei', false, 'opt', 'users'],
            ['admin', false, 'postal_code', false, 'opt', 'users'], ['admin', false, 'address', false, 'opt', 'users'], ['admin', false, 'position', false, 'opt', 'users'],
            ['ses', true, 'ses_name', true, 'confirm', 'company'], ['ses', true, 'name_sei', true, 'confirm', 'users'], ['ses', true, 'email', false, 'view', 'users'],
            ['ses', true, 'phone', true, 'self', 'company'], ['ses', true, 'postal_code', true, 'self', 'company'], ['ses', true, 'address', true, 'self', 'company'],
            ['ses', true, 'kana_sei', false, 'opt', 'users'], ['ses', true, 'position', false, 'opt', 'users'],
            ['ses', false, 'company_name', false, 'view', 'company'], ['ses', false, 'name_sei', true, 'confirm', 'users'], ['ses', false, 'name_mei', true, 'confirm', 'users'],
            ['ses', false, 'phone', true, 'self', 'users'], ['ses', false, 'kana_sei', false, 'opt', 'users'], ['ses', false, 'position', false, 'opt', 'users'],
            ['eg', false, 'company_name', false, 'view', 'company'], ['eg', false, 'name_sei', true, 'confirm', 'users'], ['eg', false, 'kana_sei', true, 'self', 'users'],
            ['eg', false, 'kana_mei', true, 'self', 'users'], ['eg', false, 'phone', true, 'self', 'engineer'], ['eg', false, 'postal_code', true, 'self', 'engineer'],
            ['eg', false, 'address', true, 'self', 'engineer'],
        ];
    }

    #[DataProvider('requirementRowsProvider')]
    public function test_s1_32_requirements_match_the_table(string $role, bool $isRep, string $key, bool $required, string $mode, string $store): void
    {
        $f = Requirements::fields($role, $isRep);
        $this->assertArrayHasKey($key, $f);
        $this->assertSame($required, $f[$key]['required']);
        $this->assertSame($mode, $f[$key]['mode']);
        $this->assertSame($store, $f[$key]['store']);
    }

    public static function requirementRowsProvider(): array
    {
        return array_map(fn ($r) => $r, self::requirementRows());
    }

    public function test_s1_32_staff_and_admin_have_the_right_fields_and_consents(): void
    {
        // 郵便番号・住所：EG にはあり、担当者にはない。管理者は任意
        $this->assertArrayNotHasKey('address', Requirements::fields('ses', false));
        $this->assertArrayNotHasKey('postal_code', Requirements::fields('ses', false));
        $this->assertArrayHasKey('address', Requirements::fields('eg'));
        $this->assertNull(Requirements::consent('admin'));
        $this->assertSame('agree', Requirements::consent('ses', true)['key']);
        $this->assertSame('agree', Requirements::consent('ses', false)['key']);
        $this->assertSame('consent', Requirements::consent('eg')['key']);
        $this->assertSame('users', Requirements::consent('ses', false)['table']);
        $this->assertSame('ses_companies', Requirements::consent('ses', true)['table']);
    }

    public function test_s1_32_missing_is_computed_each_time(): void
    {
        $row = $this->user('admin@park.example');
        $this->assertSame([], Requirements::missing($row, 'admin'));
        DB::table('users')->where('id', $row->id)->update(['phone' => null, 'company_name' => ' ']);
        $this->assertEqualsCanonicalizing(['phone', 'company_name'], Requirements::missing($this->user('admin@park.example'), 'admin'));
        $this->assertNotNull(Requirements::checkNotEmpty('admin', false, ['phone' => '']));
        $this->assertNull(Requirements::checkNotEmpty('admin', false, ['position' => '']));
        $this->assertNull(Requirements::checkNotEmpty('admin', false, ['name_sei' => '山田']));
    }

    // ---------- S1-07：文面が1か所にある ----------
    public function test_s1_07_mail_text_comes_from_registration_text(): void
    {
        $this->as('admin@park.example')->post('/admin/ses', ['name' => '文面テスト株式会社', 'rep_sei' => '文面', 'rep_mei' => '太郎', 'email' => 'text@example.com'])->assertRedirect();
        $m = DB::table('mail_outbox')->where('to_email', 'text@example.com')->first();
        $this->assertStringContainsString(T::PROVISIONAL_SUBJECT, $m->subject);
        $this->assertStringContainsString('お心当たりがない場合は、リンク先の「心当たりがない」を押すか、このメールを破棄してください。', $m->body);
        $this->assertStringContainsString(T::requiredLines('ses', true), $m->body);
        $this->assertSame('会社を代表して、PARK で知り得た情報の守秘義務に同意します。', T::consent('ses', true)['text']);
        $this->assertSame('PARK で知り得た情報の守秘義務に同意します。', T::consent('ses', false)['text']);
        $this->assertSame('個人情報の取り扱いに同意し、登録の内容が事実であることを確認しました。', T::consent('eg')['text']);
    }

    // ---------- S1-09・S1-10：仮登録は候補・配信・請求から外れる ----------
    public function test_s1_09_provisional_engineer_is_not_a_candidate(): void
    {
        $sid = $this->user('ses1@park.example')->ses_id;
        $base = ['name' => 'テスト 候補', 'kana' => 'てすと こうほ', 'years_exp' => 5, 'status' => 'available', 'email' => null];
        $in = W::createEngineer($sid, $base + ['name' => 'テスト 在籍'], (object) ['id' => null, 'role' => 'admin'], false);
        $out = W::createEngineer($sid, $base + ['name' => 'テスト 仮登録'], (object) ['id' => null, 'role' => 'admin'], false);
        DB::table('engineers')->where('id', $in)->update(['registered_at' => now_str()]);
        $caseId = DB::table('cases')->value('id');
        $ids = array_map(fn ($r) => $r['engineer']->id, Matcher::forCase($caseId, true));
        $this->assertContains($in, $ids);
        $this->assertNotContains($out, $ids);
        // 配信
        $this->assertSame(1, W::distribute($caseId, [$in, $out], User::where('email', 'admin@park.example')->first()));
        $this->assertFalse(DB::table('case_offers')->where('engineer_id', $out)->exists());
        // 一斉メール
        N::queueMail('x@example.com', 'x', 's', 'b'); // 区切り
        $this->as('admin@park.example');
        DB::table('users')->insert(['email' => 'prov-eg@example.com', 'name' => '仮 登録', 'role' => 'eg', 'ses_id' => $sid, 'engineer_id' => $out, 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->post('/admin/mail/broadcast', ['target' => 'eg', 'subject' => '一斉', 'body' => '本文'])->assertRedirect();
        $this->assertFalse(DB::table('mail_outbox')->where('to_email', 'prov-eg@example.com')->exists());
    }

    public function test_s1_10_provisional_ses_is_out_of_selection_and_billing(): void
    {
        $id = DB::table('ses_companies')->insertGetId(['name' => '仮登録商事', 'kind' => 'ses', 'status' => 'provisional', 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->as('admin@park.example')->get('/cases/new')->assertOk()->assertDontSee('仮登録商事');
        $this->assertNull(\App\Services\Billing::build($id, this_month()));
        $this->post('/admin/invoices/build', ['period' => this_month()])->assertRedirect();
        $this->assertSame(0, DB::table('invoices')->where('ses_id', $id)->count());
        $uid = DB::table('users')->insertGetId(['email' => 'prov-ses@example.com', 'name' => '仮 代表', 'role' => 'ses', 'ses_id' => $id, 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        N::toSes($id, 'テスト', '本文', '/ses');
        $this->assertSame(0, DB::table('app_notifications')->where('user_id', $uid)->count());
        // 仮登録の会社は停止できない
        $this->post("/admin/ses/$id/suspend")->assertRedirect();
        $this->assertSame('provisional', DB::table('ses_companies')->find($id)->status);
    }

    // ---------- S1-34：park:status ----------
    public function test_s1_34_status_shows_registration_counts_without_personal_data(): void
    {
        DB::table('users')->insert(['email' => 'status-prov@example.com', 'name' => '仮 人', 'role' => 'ses', 'active' => 1, 'created_at' => now_str(), 'updated_at' => now_str()]);
        $this->artisan('park:status')->expectsOutputToContain('アカウント：参加中')->expectsOutputToContain('アカウント：仮登録')->expectsOutputToContain('SES のいない会社')
            ->expectsOutputToContain('本登録の日時が無い')->doesntExpectOutputToContain('status-prov@example.com')->assertExitCode(0);
        $json = json_decode($this->runStatusJson(), true);
        $this->assertSame(0, $json['アカウント：パスワードがあるのに本登録の日時が無い（0 のはず）']);
        $this->assertGreaterThanOrEqual(1, $json['アカウント：仮登録（期限切れ）']);
    }

    private function runStatusJson(): string
    {
        \Illuminate\Support\Facades\Artisan::call('park:status', ['--json' => true]);
        return \Illuminate\Support\Facades\Artisan::output();
    }
}
