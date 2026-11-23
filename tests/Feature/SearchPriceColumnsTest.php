<?php

namespace Tests\Feature;

use App\Services\CaseSearch as CS;
use App\Services\SkillChecks;
use App\Services\Workflow as W;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// EG検索（2026-11-23 TEZZO）：希望単価が案件＋10万円以上は外す。一覧に案件の情報と年齢・価格・最寄り駅を出す
class SearchPriceColumnsTest extends TestCase
{
    use RefreshDatabase;
    use V030Helpers;

    protected $seed = true;

    protected function setUp(): void
    {
        parent::setUp();
        DB::table('engineers')->update(['status' => 'unavailable']);
    }

    private function user(string $email): object
    {
        return DB::table('users')->where('email', $email)->first();
    }

    private function mkEng(string $sesEmail, string $name, array $over): int
    {
        $id = W::createEngineer((int) $this->user($sesEmail)->ses_id, ['name' => $name, 'kana' => 'てすと たろう', 'phone' => '090-0000-0001', 'postal_code' => '100-0001', 'address' => '東京都（テスト）'], $this->user('admin@park.example'), false);
        DB::table('engineers')->where('id', $id)->update($over + ['consent_at' => now_str(), 'self_confirmed_at' => now_str(), 'registered_at' => now_str(), 'initials' => 'T.T',
            'status' => 'available', 'email' => "eg{$id}@example.test"]);
        SkillChecks::saveFromNames($id, [['Java', 'lang', 12], ['SQL', 'db', 12]], 'seed');
        return $id;
    }

    public function test_price_over_is_excluded_and_columns_shown(): void
    {
        $cid = W::saveCase(['title' => '単価テストの案件', 'price' => 60, 'required_skills' => 'Java、SQL', 'location' => '品川駅', 'work_style' => 'onsite',
            'start_date' => '2027-01-01', 'status' => 'open', 'raw_text' => "架空\n■必須\n・Java\n・SQL"], $this->user('ses1@park.example'));
        $ok = $this->mkEng('ses1@park.example', '単価内 太郎', ['desired_price' => 69.5, 'birth_year' => 1990, 'nearest_station' => '大崎駅']);
        $ng = $this->mkEng('ses1@park.example', '単価超 次郎', ['desired_price' => 70]);
        $other = $this->mkEng('ses2@park.example', '他社 三郎', ['desired_price' => 50, 'birth_year' => 1985, 'nearest_station' => '秘密駅']);

        $this->as('ses1@park.example')->post("/cases/$cid/search", [])->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $cid)->value('id');
        $ids = DB::table('case_search_members')->where('search_id', $sid)->pluck('engineer_id')->all();
        $this->assertContains($ok, $ids);
        $this->assertNotContains($ng, $ids);
        $this->assertContains($other, $ids);

        $c = DB::table('cases')->find($cid);
        $res = $this->get("/searches/$sid")->assertOk()
            ->assertSee($c->code)->assertSee('60万円')->assertSee('2027年1月')->assertSee('品川駅')
            ->assertSee('年齢')->assertSee((date('Y') - 1990).'歳')->assertSee('69.5万円')->assertSee('大崎駅')
            ->assertDontSee('単価超 次郎')->assertDontSee('秘密駅'); // 他社の人の年齢・価格・最寄り駅は出さない
    }

    public function test_full_remote_hides_station_and_no_price_keeps(): void
    {
        $c = (object) ['price' => null];
        $this->assertFalse(CS::priceOver($c, (object) ['desired_price' => 100]));
        $this->assertFalse(CS::priceOver((object) ['price' => 60], (object) ['desired_price' => null]));
        $this->assertTrue(CS::priceOver((object) ['price' => 60], (object) ['desired_price' => 70]));
        $this->assertFalse(CS::priceOver((object) ['price' => 60], (object) ['desired_price' => 69.5]));

        $cid = W::saveCase(['title' => 'リモートの案件', 'price' => 60, 'required_skills' => 'Java', 'location' => '品川駅', 'work_style' => 'remote',
            'status' => 'open', 'raw_text' => "架空\n■必須\n・Java"], $this->user('ses1@park.example'));
        $this->mkEng('ses1@park.example', '在宅 四郎', ['desired_price' => 55]);
        $this->as('ses1@park.example')->post("/cases/$cid/search", [])->assertRedirect();
        $sid = (int) DB::table('case_searches')->where('case_id', $cid)->value('id');
        $this->get("/searches/$sid")->assertOk()->assertSee('フルリモート')->assertDontSee('最寄り駅：品川駅');
    }
}
