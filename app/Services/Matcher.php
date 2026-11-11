<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// マッチング（案件票 × 経験票）。点数と「合う点」「足りない点」を出す。最終判断は人がする。
class Matcher
{
    const WEIGHTS = ['required' => 55, 'preferred' => 15, 'years' => 8, 'price' => 10, 'start' => 6, 'style' => 6];

    // エンジニアのスキル（スキルのチェック＋経験票で使った技術）
    public static function skillIndex(int $engineerId): array
    {
        return self::skillIndexMany([$engineerId])[$engineerId] ?? [];
    }

    // 何人分でも、問い合わせ3回（スキルのチェック・経歴・経歴の索引）で作る。[engineer_id => スキルの索引]
    // 索引の1つは ['name' => 表示名, 'years' => 年数の文か null, 'rank' => 年数の大きさ, 'fromProject' => 経歴だけにあるか]
    // 経歴の月数（months）は次のバージョンの準備で、点数の計算には使わない
    public static function skillIndexMany(array $engineerIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $engineerIds)) as $id) $out[$id] = [];
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            foreach (SkillChecks::indexMany($chunk) as $eid => $list) $out[$eid] = $list;
            foreach (DB::table('engineer_projects')->whereIn('engineer_id', $chunk)->get(['engineer_id', 'tech', 'os_tools', 'langs', 'dbs']) as $p) {
                foreach (project_techs($p) as $t) {
                    $k = norm_skill($t);
                    if (!isset($out[$p->engineer_id][$k])) $out[$p->engineer_id][$k] = ['name' => $t, 'years' => null, 'fromProject' => true];
                }
            }
            $rows = DB::table('engineer_project_skills as ps')->join('engineer_projects as p', 'p.id', '=', 'ps.project_id')->join('skill_terms as t', 't.id', '=', 'ps.term_id')
                ->whereIn('p.engineer_id', $chunk)->get(['p.engineer_id', 'ps.term_id', 'p.period_from', 'p.period_to', 't.name']);
            $names = [];
            foreach ($rows as $r) $names[$r->engineer_id][(int) $r->term_id] = $r->name;
            foreach (ProjectSkills::statsFromRows($rows) as $eid => $terms) {
                foreach ($terms as $termId => $st) {
                    $k = norm_skill($names[$eid][$termId]);
                    if (isset($out[$eid][$k])) $out[$eid][$k]['months'] = $st['months'];
                }
            }
        }
        return $out;
    }

    public static function hasSkill(array $idx, string $name): ?array
    {
        $k = norm_skill($name);
        if (isset($idx[$k])) return $idx[$k];
        foreach ($idx as $key => $s) if (self::sameSkill((string) $key, $k)) return $s;
        return null;
    }

    // 2つのスキル名（norm_skill 済み）が同じ技術か。同じ、または両方3文字以上でゆるく合うとき
    public static function sameSkill(string $a, string $b): bool
    {
        return $a === $b || (mb_strlen($a) >= 3 && mb_strlen($b) >= 3 && loose_match($a, $b));
    }

    // $idx は、まとめて読んだスキルの索引（skillIndexMany）。渡さなければここで読む
    public static function score(object $c, object $e, ?array $idx = null): array
    {
        $W = self::WEIGHTS;
        $idx ??= self::skillIndex($e->id);
        $reasons = []; $missing = []; $score = 0.0;

        $req = parse_list($c->required_skills);
        if ($req) {
            $hit = 0;
            foreach ($req as $r) {
                if ($s = self::hasSkill($idx, $r)) { $hit += $s['fromProject'] ? 0.8 : 1; $reasons[] = "必須 $r".($s['years'] ? '（'.$s['years'].'）' : ''); }
                else $missing[] = "必須 $r";
            }
            $score += $W['required'] * $hit / count($req);
        } else $score += $W['required'] * 0.5;

        $pref = parse_list($c->preferred_skills);
        if ($pref) {
            $hit = 0;
            foreach ($pref as $r) if (self::hasSkill($idx, $r)) { $hit++; $reasons[] = "尚可 $r"; }
            $score += $W['preferred'] * $hit / count($pref);
        } else $score += $W['preferred'] * 0.5;

        if ($c->min_years) {
            if ((float) $e->years_exp >= (float) $c->min_years) { $score += $W['years']; $reasons[] = '経験'.num_disp($e->years_exp).'年'; }
            else $missing[] = '経験年数（'.num_disp($c->min_years).'年以上）';
        } else $score += $W['years'];

        if ($c->price && $e->desired_price) {
            if ((float) $e->desired_price <= (float) $c->price) { $score += $W['price']; $reasons[] = '単価が合う'; }
            elseif ($e->min_price && (float) $e->min_price <= (float) $c->price) { $score += $W['price'] * 0.6; $reasons[] = '単価は下限なら合う'; }
            else $missing[] = '単価（希望 '.man($e->desired_price).' ＞ '.man($c->price).'）';
        } else $score += $W['price'] * 0.5;

        $start = $c->start_date ?: today_str();
        if (!$e->available_from || $e->available_from <= $start) $score += $W['start'];
        else $missing[] = '開始日（'.fmt_date($e->available_from).' から可）';

        $cs = $c->work_style ?: 'any'; $es = $e->work_style ?: 'any';
        if ($cs === 'any' || $es === 'any' || $cs === $es || ($cs === 'hybrid' && $es !== 'remote') || ($es === 'hybrid' && $cs !== 'onsite')) $score += $W['style'];
        else $missing[] = '働き方（リモート／出社）';

        return ['score' => (int) round($score), 'reasons' => $reasons, 'missing' => $missing];
    }

    // 案件に合うエンジニアを点数順に（稼働中・休止・応募済みは除く）
    public static function forCase(int $caseId, bool $includeBusy = false): array
    {
        $c = DB::table('cases')->find($caseId);
        if (!$c) return [];
        // 仮登録・停止中・退会済は常に除く（条件は Registration::usableEngineers に1か所）
        $q = Registration::usableEngineers(DB::table('engineers as e')->join('ses_companies as s', 's.id', '=', 'e.ses_id')->where('s.status', 'active'), 'e')->select('e.*', 's.name as ses_name');
        if (!$includeBusy) $q->whereIn('e.status', ['available', 'proposing']);
        $applied = DB::table('applications')->where('case_id', $caseId)->pluck('engineer_id')->all();
        $offered = DB::table('case_offers')->where('case_id', $caseId)->pluck('status', 'engineer_id')->all();
        $out = [];
        $engs = $q->get()->filter(fn ($e) => !in_array($e->id, $applied))->values();
        $idxs = self::skillIndexMany($engs->pluck('id')->all()); // 人数によらず問い合わせは決まった回数
        foreach ($engs as $e) {
            $out[] = ['engineer' => $e, 'offered' => $offered[$e->id] ?? null] + self::score($c, $e, $idxs[$e->id] ?? []);
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public static function threshold(): int { return (int) setting('match_threshold') ?: 40; }
}
