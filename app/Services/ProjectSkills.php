<?php

namespace App\Services;

use App\Support\SkillCatalog;
use Illuminate\Support\Facades\DB;

// 経歴ごとの技術の索引（engineer_project_skills）。経歴に書かれた技術の文字列から、技術マスタの言葉だけを拾って持つ。
// 索引を作るのは rebuild() の1つだけ（経歴の保存・AI の下書きの取り込み・データの移し替えが、ここを通る）。
// 月数は保存せず、見るたびに経歴の期間から数える（重なった月は1回）。
class ProjectSkills
{
    // 索引に入れる分類（技術の分類だけ。工程・業種・役割は入れない）
    const TECH_CATS = ['lang', 'fw', 'db', 'os', 'cloud', 'infra', 'tool', 'other'];

    // 経歴1件の索引を作り直す。経歴が無ければ、その経歴の行を消すだけ
    public static function rebuild(int $projectId): void
    {
        self::rebuildRow(DB::table('engineer_projects')->find($projectId), $projectId);
    }

    // 全部の経歴の索引を作り直す
    public static function rebuildAll(): void
    {
        DB::table('engineer_projects')->chunkById(200, function ($rows) {
            foreach ($rows as $p) self::rebuildRow($p, (int) $p->id);
        });
    }

    private static function rebuildRow(?object $p, int $projectId): void
    {
        DB::transaction(function () use ($p, $projectId) {
            DB::table('engineer_project_skills')->where('project_id', $projectId)->delete();
            if (!$p) return;
            // マスタに無い言葉は入れない（経歴の文字列にだけ残る）。重複は1つにする
            $termIds = [];
            foreach (project_techs($p) as $word) {
                $t = SkillCatalog::find($word);
                if ($t && in_array($t[2], self::TECH_CATS, true)) $termIds[(int) $t[0]] = true;
            }
            foreach (array_keys($termIds) as $termId) {
                DB::table('engineer_project_skills')->insertOrIgnore(['project_id' => $projectId, 'term_id' => $termId]);
            }
        });
    }

    // エンジニアごと・技術ごとの経歴の月数。[engineer_id][term_id] => ['months' => 月数, 'projects' => 件数, 'last' => 'YYYY-MM' か null]
    public static function stats(array $engineerIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $engineerIds)) as $id) $out[$id] = [];
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            $rows = DB::table('engineer_project_skills as ps')->join('engineer_projects as p', 'p.id', '=', 'ps.project_id')
                ->whereIn('p.engineer_id', $chunk)->get(['p.engineer_id', 'ps.term_id', 'p.period_from', 'p.period_to']);
            foreach (self::statsFromRows($rows) as $eid => $terms) $out[$eid] = $terms;
        }
        return $out;
    }

    // 読んだ行（engineer_id・term_id・period_from・period_to）から同じ計算をする。問い合わせを足さずに使える
    public static function statsFromRows(iterable $rows): array
    {
        $months = []; $projects = [];
        foreach ($rows as $r) {
            $e = (int) $r->engineer_id; $t = (int) $r->term_id;
            $projects[$e][$t] = ($projects[$e][$t] ?? 0) + 1; // 期間が数えられなくても件数には入れる
            foreach (ym_months($r->period_from, $r->period_to) as $m) $months[$e][$t][$m] = true;
        }
        $out = [];
        foreach ($projects as $e => $terms) {
            foreach ($terms as $t => $count) {
                $set = array_keys($months[$e][$t] ?? []);
                $last = $set ? max($set) : null;
                $out[$e][$t] = ['months' => count($set), 'projects' => $count,
                    'last' => $last === null ? null : sprintf('%04d-%02d', intdiv($last, 12), $last % 12 + 1)];
            }
        }
        return $out;
    }
}
