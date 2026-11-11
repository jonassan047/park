<?php

namespace App\Services;

use App\Support\SkillCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// 技術マスタの統合と、古いスキル表（engineer_skills）の整合。
// v0.4.0 から、スキルの保存・表示は SkillChecks（engineer_skill_checks）に移った。古い表は次のバージョンで消す。
class SkillTable
{
    // 同じ技術の行を1つにする。years・level・last_used_ym は大きいほう、is_primary はどれかが付いていれば付ける、note は先の行の空でないもの
    public static function mergeRows(iterable $rows): object
    {
        $out = null;
        foreach ($rows as $r) {
            $r = (object) (array) $r;
            if ($out === null) {
                $out = clone $r;
                $out->is_primary = (bool) ($r->is_primary ?? false);
                continue;
            }
            $ya = $out->years ?? null; $yb = $r->years ?? null;
            if ($yb !== null && ($ya === null || (float) $yb > (float) $ya)) $out->years = $yb;
            $out->level = max((int) ($out->level ?? 3), (int) ($r->level ?? 3));
            $la = $out->last_used_ym ?? null; $lb = $r->last_used_ym ?? null;
            if ($lb !== null && $lb !== '' && ($la === null || $la === '' || strcmp($lb, $la) > 0)) $out->last_used_ym = $lb;
            if (!empty($r->is_primary)) $out->is_primary = true;
            if (($out->note ?? '') === '' && ($r->note ?? '') !== '') $out->note = $r->note;
        }
        return $out;
    }

    // 古い表の行を、同じ技術ごとに1つにする（term_id があればそれで、無ければ名前で。DB は触らない）
    public static function dedupe($rows): Collection
    {
        $groups = [];
        foreach ($rows as $r) {
            $key = !empty($r->term_id) ? 't'.$r->term_id : 'n:'.norm_skill($r->name);
            $groups[$key][] = $r;
        }
        return collect(array_map(fn ($g) => count($g) > 1 ? self::mergeRows($g) : $g[0], array_values($groups)));
    }

    // 技術マスタの言葉 B（fromId）を A（toId）にまとめる。B は A の別名になり、B を使っていた経歴の索引とスキル表の行は A になる。
    // 全部で1つの transaction。途中で失敗したら全部元に戻る
    public static function mergeTerms(int $fromId, int $toId): void
    {
        try {
            DB::transaction(function () use ($fromId, $toId) {
                $from = DB::table('skill_terms')->find($fromId);
                $to = DB::table('skill_terms')->find($toId);
                if (!$from || !$to || $fromId === $toId) throw new \DomainException('まとめる言葉が見つかりません。');

                // 1. A の別名に B の名前と別名を足す
                $aliases = array_unique(array_merge(parse_list($to->aliases), [$from->name], parse_list($from->aliases)));
                DB::table('skill_terms')->where('id', $toId)->update(['aliases' => implode('、', $aliases), 'updated_at' => now()]);

                // 2. 経歴の索引：B の行を A に付け替える（同じ経歴にすでに A があれば、そのまま1つ）。元の行は消す
                foreach (DB::table('engineer_project_skills')->where('term_id', $fromId)->pluck('project_id') as $pid) {
                    DB::table('engineer_project_skills')->insertOrIgnore(['project_id' => $pid, 'term_id' => $toId]);
                }
                DB::table('engineer_project_skills')->where('term_id', $fromId)->delete();

                // 3. 古いスキル表：B の行を A にし、同じ人に A が2つ以上できたら1つにまとめる（id の小さい行を残す）。
                //    新しいスキルの表（engineer_skill_checks）は項目の名前で持つので、ここでは変えない
                $owners = DB::table('engineer_skills')->where('term_id', $fromId)->distinct()->pluck('engineer_id');
                DB::table('engineer_skills')->where('term_id', $fromId)->update(['term_id' => $toId, 'name' => $to->name, 'category' => $to->category]);
                foreach ($owners as $eid) {
                    $rows = DB::table('engineer_skills')->where('engineer_id', $eid)->where('term_id', $toId)->orderBy('id')->get();
                    if ($rows->count() < 2) continue;
                    $m = self::mergeRows($rows);
                    DB::table('engineer_skills')->where('id', $rows[0]->id)->update(['years' => $m->years, 'level' => $m->level,
                        'last_used_ym' => $m->last_used_ym, 'is_primary' => $m->is_primary, 'note' => $m->note, 'updated_at' => now_str()]);
                    DB::table('engineer_skills')->whereIn('id', $rows->skip(1)->pluck('id')->all())->delete();
                }

                // 4. マスタの B を消す
                DB::table('skill_terms')->where('id', $fromId)->delete();
            });
        } finally {
            SkillCatalog::forget(); // 成功しても失敗しても、覚えていた辞書は捨てる
        }
    }

    // 技術マスタの言葉ごとの、使っている人数（スキルのチェックと経歴の両方。同じ人は1回）。[term_id => 人数]
    public static function usedCounts(): array
    {
        $people = SkillChecks::usedPeople();
        $rows = DB::table('engineer_project_skills as ps')->join('engineer_projects as p', 'p.id', '=', 'ps.project_id')->distinct()->get(['ps.term_id', 'p.engineer_id']);
        foreach ($rows as $r) $people[(int) $r->term_id][(int) $r->engineer_id] = true;
        return array_map('count', $people);
    }
}
