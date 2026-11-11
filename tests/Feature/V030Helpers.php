<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ProjectSkills;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
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

    // スキル（古い表と新しいチェックの表）と経歴を空にして、きれいな EG にする
    protected function cleanEg(int $eid): void
    {
        DB::table('engineer_skills')->where('engineer_id', $eid)->delete();
        DB::table('engineer_skill_checks')->where('engineer_id', $eid)->delete();
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

    // 道筋（'lang/PHP'＝節/名前、'lang/PHP/Laravel'＝節/親/名前、'roles/その他'）から、項目を引く。無ければテストを失敗にする
    protected function skillNode(string $path): array
    {
        $p = explode('/', $path, 3);
        $n = count($p) === 2 ? SkillForm::byPath($p[0], '', $p[1]) : (SkillForm::byPath($p[0], $p[1], $p[2]) ?? SkillForm::byPath($p[0], '', $p[1].'/'.$p[2]));
        $this->assertNotNull($n, "skill_form.json に道筋が無い：$path");
        return $n;
    }

    // スキル画面の送信の形にする。
    // $checks＝道筋 => 年数の文（'lang/PHP' => '5年以上'、'lang/PHP/Laravel' => ''＝年数なし）
    // $others＝「その他」の道筋 => [[名前, 年数の文]…]（'lang/PHP/その他' => [['Haxe', '3年以上']]。チェックも付く）
    protected function checkForm(array $checks, array $others = []): array
    {
        $f = ['_sf' => '1', 'chk' => [], 'yr' => [], 'oth' => [], 'othyr' => [], '_sf_end' => '1'];
        foreach ($checks as $path => $years) {
            $n = $this->skillNode($path);
            $f['chk'][$n['id']] = '1';
            if ($years !== '' && $years !== null) $f['yr'][$n['id']] = $years;
        }
        foreach ($others as $path => $list) {
            $n = $this->skillNode($path);
            $f['chk'][$n['id']] = '1';
            foreach ($list as [$name, $years]) {
                $f['oth'][$n['id']][] = $name;
                $f['othyr'][$n['id']][] = $years;
            }
        }
        return $f;
    }

    // 新しいスキルの表の、そのエンジニアの行（id 順）
    protected function checkRows(int $eid)
    {
        return DB::table('engineer_skill_checks')->where('engineer_id', $eid)->orderBy('id')->get();
    }

    // 経歴の索引にある技術の名前（並べ替え済み）
    protected function projectTermNames(int $pid): array
    {
        $names = DB::table('engineer_project_skills as ps')->join('skill_terms as t', 't.id', '=', 'ps.term_id')->where('ps.project_id', $pid)->pluck('t.name')->all();
        sort($names);
        return $names;
    }
}
