<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProjectSkills;
use App\Support\SkillCatalog;
use App\Support\TwoFactor;
use Illuminate\Support\Facades\DB;

// v0.3.0 のテストで共通の道具（ログイン・EG の片付け・経歴やスキルの作り方）。テストのクラスは RefreshDatabase と $seed = true を自分で使う
trait V030Helpers
{
    // 見本のユーザーとしてログインした状態にする。前の人のセッションは捨てる
    protected function as(string $email, ?string $acting = null): static
    {
        $this->flushSession();
        $u = User::where('email', $email)->firstOrFail();
        $s = [TwoFactor::SESSION_KEY => $u->id];
        if ($acting) $s['acting_role'] = $acting;
        return $this->actingAs($u->fresh())->withSession($s);
    }

    // 見本の EG のログインメールから、エンジニアの id を返す
    protected function engId(string $email): int
    {
        return (int) DB::table('users')->where('email', $email)->value('engineer_id');
    }

    // スキル表と経歴を空にして、きれいな EG にする
    protected function cleanEg(int $eid): void
    {
        DB::table('engineer_skills')->where('engineer_id', $eid)->delete();
        DB::table('engineer_projects')->where('engineer_id', $eid)->delete();
        SkillCatalog::forget();
    }

    // 技術マスタの言葉の term_id
    protected function termId(string $name): int
    {
        $t = SkillCatalog::find($name);
        $this->assertNotNull($t, "技術マスタに {$name} が無い");
        return (int) $t[0];
    }

    // 経歴を直接入れて、索引を作る。経歴の id を返す
    protected function makeProject(int $eid, array $v = []): int
    {
        $pid = (int) DB::table('engineer_projects')->insertGetId($v + ['engineer_id' => $eid, 'title' => '見本の案件']);
        ProjectSkills::rebuild($pid);
        return $pid;
    }

    // スキル表の送信の形にする。行は ['name' => …, 'cat' => …, 'years' => …, 'level' => …, 'last' => …, 'primary' => 0/1, 'note' => …]
    protected function skillForm(array $rows): array
    {
        $f = ['skill_name' => [], 'skill_cat' => [], 'skill_years' => [], 'skill_level' => [], 'skill_last' => [], 'skill_primary' => [], 'skill_note' => []];
        foreach ($rows as $r) {
            $f['skill_name'][] = $r['name'];
            $f['skill_cat'][] = $r['cat'] ?? 'other';
            $f['skill_years'][] = $r['years'] ?? '';
            $f['skill_level'][] = $r['level'] ?? '3';
            $f['skill_last'][] = $r['last'] ?? '';
            $f['skill_primary'][] = (string) ($r['primary'] ?? '0');
            $f['skill_note'][] = $r['note'] ?? '';
        }
        return $f;
    }

    // 経歴の索引にある技術の名前（並べ替え済み）
    protected function projectTermNames(int $pid): array
    {
        $names = DB::table('engineer_project_skills as ps')->join('skill_terms as t', 't.id', '=', 'ps.term_id')->where('ps.project_id', $pid)->pluck('t.name')->all();
        sort($names);
        return $names;
    }
}
