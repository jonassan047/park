<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// マッチング（案件票 × 経験票）。点数と「合う点」「足りない点」を出す。最終判断は人がする。
class Matcher
{
    const WEIGHTS = ['required' => 55, 'preferred' => 15, 'years' => 8, 'price' => 10, 'start' => 6, 'style' => 6];

    // エンジニアのスキル（スキル表＋経験票で使った技術）
    public static function skillIndex(int $engineerId): array
    {
        $idx = [];
        foreach (DB::table('engineer_skills')->where('engineer_id', $engineerId)->get() as $s) {
            $idx[norm_skill($s->name)] = ['name' => $s->name, 'years' => $s->years !== null ? (float) $s->years : null, 'fromProject' => false];
        }
        foreach (DB::table('engineer_projects')->where('engineer_id', $engineerId)->get(['tech', 'os_tools', 'langs', 'dbs']) as $p) {
            foreach (project_techs($p) as $t) {
                $k = norm_skill($t);
                if (!isset($idx[$k])) $idx[$k] = ['name' => $t, 'years' => null, 'fromProject' => true];
            }
        }
        return $idx;
    }

    public static function hasSkill(array $idx, string $name): ?array
    {
        $k = norm_skill($name);
        if (isset($idx[$k])) return $idx[$k];
        if (mb_strlen($k) >= 3) {
            foreach ($idx as $key => $s) if (mb_strlen($key) >= 3 && loose_match($key, $k)) return $s;
        }
        return null;
    }

    public static function score(object $c, object $e): array
    {
        $W = self::WEIGHTS;
        $idx = self::skillIndex($e->id);
        $reasons = []; $missing = []; $score = 0.0;

        $req = parse_list($c->required_skills);
        if ($req) {
            $hit = 0;
            foreach ($req as $r) {
                if ($s = self::hasSkill($idx, $r)) { $hit += $s['fromProject'] ? 0.8 : 1; $reasons[] = "必須 $r".($s['years'] ? '（'.num_disp($s['years']).'年）' : ''); }
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
        $q = DB::table('engineers as e')->join('ses_companies as s', 's.id', '=', 'e.ses_id')->where('s.status', 'active')->select('e.*', 's.name as ses_name');
        if (!$includeBusy) $q->whereIn('e.status', ['available', 'proposing']);
        $applied = DB::table('applications')->where('case_id', $caseId)->pluck('engineer_id')->all();
        $offered = DB::table('case_offers')->where('case_id', $caseId)->pluck('status', 'engineer_id')->all();
        $out = [];
        foreach ($q->get() as $e) {
            if (in_array($e->id, $applied)) continue;
            $out[] = ['engineer' => $e, 'offered' => $offered[$e->id] ?? null] + self::score($c, $e);
        }
        usort($out, fn ($a, $b) => $b['score'] <=> $a['score']);
        return $out;
    }

    public static function threshold(): int { return (int) setting('match_threshold') ?: 40; }
}
