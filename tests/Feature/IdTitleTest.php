<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

// 詳細画面のタイトルは、必ず「ID　名前」（2026-11-25 TEZZO「SE-000011　AAA」）
class IdTitleTest extends TestCase
{
    use RefreshDatabase;

    protected $seed = true;

    private function admin(): static
    {
        $u = User::where('email', 'admin@park.example')->firstOrFail();
        return $this->actingAs($u->fresh())->withSession([TwoFactor::SESSION_KEY => $u->id]);
    }

    private function h1(string $path, string $code, string $name): void
    {
        $this->assertNotEmpty($code, "$path の ID が空");
        $this->admin()->get($path)->assertOk()->assertSee('<h1>', false)->assertSeeText($code.'　'.$name);
    }

    public function test_detail_titles_have_id(): void
    {
        $s = DB::table('ses_companies')->whereNotNull('code')->where('status', 'active')->first();
        $this->h1('/admin/ses/'.$s->id, $s->code, $s->name);
        $this->h1('/admin/ses/'.$s->id.'/edit', $s->code, $s->name);

        $e = DB::table('engineers')->whereNotNull('code')->first();
        $this->h1('/engineers/'.$e->id, $e->code, $e->name);
        $this->h1('/engineers/'.$e->id.'/edit', $e->code, $e->name);

        $c = DB::table('cases')->whereNotNull('code')->first();
        $this->h1('/cases/'.$c->id, $c->code, $c->title);
        $this->h1('/cases/'.$c->id.'/edit', $c->code, $c->title);

        $a = DB::table('users')->where('email', 'admin@park.example')->first();
        $this->h1('/admin/admins/'.$a->id, $a->admin_code, $a->name);

        $t = DB::table('users')->where('ses_id', $s->id)->whereNotNull('staff_code')->first();
        if ($t) $this->h1('/admin/ses/'.$s->id.'/users/'.$t->id, $t->staff_code, $t->name);

        $ap = DB::table('applications')->whereNotNull('code')->first();
        if ($ap) {
            $cc = DB::table('cases')->where('id', $ap->case_id)->value('code');
            $this->admin()->get('/applications/'.$ap->id)->assertOk()->assertSeeText('選考：'.$ap->code.'　'.$cc);
        }
    }

    public function test_id_title_helper(): void
    {
        $this->assertSame('SE-000011　AAA', id_title('SE-000011', 'AAA'));
        $this->assertSame('AAA', id_title(null, 'AAA'));
        $this->assertSame('AAA', id_title('', 'AAA'));
    }
}
