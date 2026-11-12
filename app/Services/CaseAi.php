<?php

namespace App\Services;

use App\Services\Notifier as N;
use App\Support\CaseExp;
use App\Support\SkillCatalog;
use Illuminate\Support\Facades\DB;

// 案件の必須・尚可を、手元の AI に読み直してもらう（v0.6.0）。
// 規則で読んだ結果はそのまま使える。AI の結果は本文と突き合わせ、同じ読み方なら自動で入れ、違えば人が選ぶ。
class CaseAi
{
    const KIND = 'case_skills';
    const MAX_TEXT = 6000; // これより長い本文は AI に回さない
    const FIX_LIMIT = 0.5; // 本文の行に戻すときの、近さ（Dice 係数）の下限

    // AI への指示（調査で測った文のまま。言い換えない）
    const PROMPT = <<<'TXT'
次の文章は、IT エンジニアの案件の募集メールです。応募する人に求める条件を、「必須」と「尚可」に分けて抜き出してください。

決まり：
- 「必須」は、必ず要るもの（必須・MUST・スキル要件・必要スキルなど）。見出しが無くても、「必須」「読み書き必須」のように書いてあるものは必須。
- 「尚可」は、あればよいもの（尚可・歓迎・推奨・あればなお良い・Want など）。
- 1つの条件を1つの項目にする。元の文の言葉をそのまま使い、言い換えない。箇条書きの印（・ ① (1) など）は外す。
- ※の補足は、直前の項目に含める。1行に「・」で2つ書いてあれば2つに分ける。
- 求める人物像・人柄の節、単価・場所・期間・面談・国籍・年齢などの契約の条件は入れない。
- 該当が無ければ空の配列にする。

次の JSON だけを返してください。
{"required": ["…", "…"], "preferred": ["…"]}
TXT;

    // ---------- 仕事を入れる ----------
    // 仕事を入れられない理由（入れられるなら null）
    public static function blocked(object $case): ?string
    {
        if ((string) config('services.park_ai.token') === '') return 'AI はまだつながっていません。';
        $raw = trim((string) $case->raw_text);
        if ($raw === '') return '本文が無い案件は、AI に読み直してもらえません。';
        if (mb_strlen($raw) > self::MAX_TEXT) return '本文が長いので AI に回していません。';
        return null;
    }

    // $replace：登録・保存のとき true（順番待ちの前の仕事を捨てて入れ直す）。ボタンのときは false（順番待ち・作業中があれば入れない）
    public static function queue(int $caseId, $actor, bool $replace = false): ?int
    {
        $c = DB::table('cases')->find($caseId);
        if (!$c) return null;
        $open = fn () => DB::table('ai_jobs')->where('ref_type', 'case')->where('ref_id', $caseId)->where('kind', self::KIND);
        if (!$replace && (self::blocked($c) || $open()->whereIn('status', ['queued', 'running'])->exists())) return null;
        // 前の仕事を捨てる（保存のときは、入れ直せない本文でも捨てる。作業中のものは、届いたとき本文の違いで捨てる）
        $by = $actor->id ?? null;
        foreach ($open()->whereIn('status', $replace ? ['queued', 'done'] : ['done'])->get(['id']) as $old) {
            DB::table('ai_jobs')->where('id', $old->id)->update(['status' => 'dropped']);
            N::audit($by, 'ai-case-stale', "#{$old->id} {$c->title}");
        }
        if (self::blocked($c)) return null;
        $raw = (string) $c->raw_text;
        $snap = self::snapshot($c);
        return AiJobs::queueCase($caseId, [
            'prompt' => self::PROMPT."\n\n募集メール：\n".$raw,
            'system' => self::PROMPT,
            'user' => $raw,
            'options' => ['temperature' => 0],
            'text_hash' => sha1($raw),
            'rule' => CaseParser::ruleLines($raw),
            'base' => $snap,
            'edited' => $snap !== self::ruleSnapshot($raw),
        ], $actor);
    }

    // ---------- 案件の4列を、比べやすい形にする ----------
    private static function skillList($s): array
    {
        $list = [];
        foreach (parse_list($s) as $w) {
            $t = SkillCatalog::find($w);
            $list[] = $t[1] ?? $w;
        }
        $list = array_values(array_unique($list));
        sort($list, SORT_STRING);
        return $list;
    }

    private static function fromCols($req, $pref, $reqExp, $prefExp): array
    {
        return ['req' => self::skillList($req), 'pref' => self::skillList($pref), 'req_exp' => CaseExp::split($reqExp), 'pref_exp' => CaseExp::split($prefExp)];
    }

    public static function snapshot(object $case): array
    {
        return self::fromCols($case->required_skills, $case->preferred_skills, $case->required_exp ?? null, $case->preferred_exp ?? null);
    }

    // 本文を規則で読んだ結果（マスタには足さない）
    public static function ruleSnapshot(string $raw): array
    {
        $p = CaseParser::parse($raw);
        return self::fromCols($p['required_skills'] ?? '', $p['preferred_skills'] ?? '', $p['required_exp'] ?? null, $p['preferred_exp'] ?? null);
    }

    // ---------- 本文との突き合わせ ----------
    private static function trimLine(string $s): string
    {
        return preg_replace('/^[\s　]+|[\s　]+$/u', '', $s) ?? $s;
    }

    // 比べるための形：全角半角・大文字小文字をそろえ、空白・印・句読点を消す
    private static function norm(string $s): string
    {
        $s = mb_strtolower(mb_convert_kana($s, 'asKV'));
        return preg_replace('/[\s　・●○◯〇－\-\*＊①-⑳、。,.，．：:]+/u', '', $s) ?? $s;
    }

    private static function grams(string $s): array
    {
        $n = mb_strlen($s);
        if ($n < 2) return $n ? [$s => 1] : [];
        $g = [];
        for ($i = 0; $i < $n - 1; $i++) { $k = mb_substr($s, $i, 2); $g[$k] = ($g[$k] ?? 0) + 1; }
        return $g;
    }

    private static function dice(string $a, string $b): float
    {
        $ga = self::grams($a); $gb = self::grams($b);
        $sum = array_sum($ga) + array_sum($gb);
        if (!$sum) return 0.0;
        $same = 0;
        foreach ($ga as $k => $n) $same += min($n, $gb[$k] ?? 0);
        return 2 * $same / $sum;
    }

    // AI の各行を本文に戻す。[['text', 'mark' => 'ok'|'fixed'|'missing', 'from' => AI の文 or null], …]
    public static function restore(array $lines, string $text): array
    {
        $clean = [];
        foreach (explode("\n", str_replace("\r", '', $text)) as $l) {
            $l = self::trimLine(preg_replace(CaseParser::BULLET, '', self::trimLine($l), 1));
            if ($l !== '') $clean[] = $l;
        }
        $all = self::norm(implode('', $clean));
        $cand = $clean;
        for ($i = 0; $i < count($clean) - 1; $i++) $cand[] = $clean[$i].' '.$clean[$i + 1];
        $out = [];
        foreach ($lines as $line) {
            $line = (string) $line;
            $n = self::norm($line);
            if ($n !== '' && str_contains($all, $n)) { $out[] = ['text' => $line, 'mark' => 'ok', 'from' => null]; continue; }
            $best = null; $bestScore = 0.0;
            if ($n !== '') foreach ($cand as $c) {
                $s = self::dice($n, self::norm($c));
                if ($s > $bestScore) { $bestScore = $s; $best = $c; }
            }
            $out[] = $best !== null && $bestScore >= self::FIX_LIMIT
                ? ['text' => $best, 'mark' => 'fixed', 'from' => $line]
                : ['text' => $line, 'mark' => 'missing', 'from' => null];
        }
        return $out;
    }

    // 並びを問わず、同じ行の集まりか
    private static function sameSet(array $a, array $b): bool
    {
        $f = function (array $l) { $l = array_values(array_unique(array_map(fn ($x) => self::norm((string) $x), $l))); sort($l, SORT_STRING); return $l; };
        return $f($a) === $f($b);
    }

    // 違いの表の印：今の行・AI の行のうち、相手に無いもの（true）。並びは問わない
    public static function diff(array $now, array $ai): array
    {
        $n = fn ($x) => self::norm((string) $x);
        $nowN = array_map($n, $now);
        $aiN = array_map($n, $ai);
        return ['now' => array_map(fn ($x) => !in_array($n($x), $aiN, true), array_values($now)), 'ai' => array_map(fn ($x) => !in_array($n($x), $nowN, true), array_values($ai))];
    }

    // AI の結果と今の案件を比べる
    public static function compare(object $job, object $case): array
    {
        $in = json_decode($job->input, true) ?: [];
        $ai = AiJobs::items($job) ?? ['required' => [], 'preferred' => []];
        $raw = (string) $case->raw_text;
        $req = self::restore($ai['required'], $raw);
        $pref = self::restore($ai['preferred'], $raw);
        $rule = $in['rule'] ?? ['required' => [], 'preferred' => []];
        $sameCount = count($req) === count($rule['required'] ?? []) && count($pref) === count($rule['preferred'] ?? []);
        $missing = count(array_filter([...$req, ...$pref], fn ($x) => $x['mark'] === 'missing'));
        $sameAsRule = self::sameSet(array_column($req, 'text'), $rule['required'] ?? []) && self::sameSet(array_column($pref, 'text'), $rule['preferred'] ?? []);
        $stale = sha1($raw) !== ($in['text_hash'] ?? '');
        $reasons = [];
        if (!$sameCount) $reasons[] = 'count';
        if ($missing > 0) $reasons[] = 'missing';
        if (!$req && !$pref) $reasons[] = 'empty'; // AI が何も読めなかったときは、自動で消さない
        if (!empty($in['edited']) || self::snapshot($case) !== ($in['base'] ?? null)) $reasons[] = 'edited';
        $id = (int) $case->id;
        if (DB::table('case_offers')->where('case_id', $id)->exists() || DB::table('case_mails')->where('case_id', $id)->exists() || DB::table('applications')->where('case_id', $id)->exists()) $reasons[] = 'offered';
        if (!in_array($case->status, ['open', 'draft'], true)) $reasons[] = 'closed';
        return ['req' => $req, 'pref' => $pref, 'sameCount' => $sameCount, 'missing' => $missing, 'sameAsRule' => $sameAsRule,
            'reasons' => $reasons, 'auto' => !$stale && !$reasons, 'stale' => $stale];
    }

    // ---------- 届いたあと・差し替え ----------
    // AI の結果で、必須・尚可・業務の4列だけを差し替える（ほかの列は変えない）
    public static function apply(object $case, array $cmp): void
    {
        $fin = CaseParser::finishSkills(array_column($cmp['req'], 'text'), array_column($cmp['pref'], 'text'), (string) $case->raw_text);
        $v = Workflow::normalizeCaseSkills(['required_skills' => $fin['required_skills'], 'preferred_skills' => $fin['preferred_skills']], (string) $case->title);
        DB::table('cases')->where('id', $case->id)->update([
            'required_skills' => $v['required_skills'], 'preferred_skills' => $v['preferred_skills'],
            'required_exp' => $fin['required_exp'], 'preferred_exp' => $fin['preferred_exp'], 'updated_at' => now_str(),
        ]);
    }

    // 結果が届いたとき（AiJobs::finish が done にしたあとに呼ぶ）
    public static function settle(int $jobId): void
    {
        $j = DB::table('ai_jobs')->find($jobId);
        if (!$j || $j->status !== 'done') return;
        $c = DB::table('cases')->find($j->ref_id);
        $by = $j->created_by ? (int) $j->created_by : null;
        $set = fn (string $status) => DB::table('ai_jobs')->where('id', $jobId)->update(['status' => $status]);
        if (!$c) { $set('dropped'); N::audit($by, 'ai-case-stale', "#$jobId"); return; }
        $cmp = self::compare($j, $c);
        if ($cmp['stale']) { $set('dropped'); N::audit($by, 'ai-case-stale', "#$jobId {$c->title}"); return; }
        if ($cmp['auto']) { self::apply($c, $cmp); $set('applied'); N::audit($by, 'ai-case-auto', "#$jobId {$c->title}"); return; }
        if ($cmp['sameAsRule']) { $set('dropped'); N::audit($by, 'ai-case-same', "#$jobId {$c->title}"); return; }
        if ($by) N::notify($by, 'AI の読み直しの結果を確かめてください：'.$c->title, '規則で読んだ結果と違うところがあるか、自動では入れられない案件です。案件の画面の「AI の読み直し」で、AI の結果を使うか選んでください。', "/cases/{$c->id}#ai", false, 'ai', json_decode($j->input, true)['actor_role'] ?? null);
    }
}
