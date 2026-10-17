<?php

namespace App\Services;

// 案件メールの本文から、案件票の項目を抜き出す（このPCの中だけで処理する。外部サービスには送らない）
class CaseParser
{
    const LABELS = [
        'title' => ['案件名', '件名', '案件', 'タイトル', 'PJ名', 'プロジェクト'],
        'price' => ['単価', '金額', '報酬', '予算', '月額'],
        'location' => ['場所', '勤務地', '最寄', '作業場所', '就業場所'],
        'start_date' => ['開始', '開始時期', '期間開始', '参画時期', '入場'],
        'period' => ['期間', '契約期間'],
        'required_skills' => ['必須', '必須スキル', '必須要件', 'MUST'],
        'preferred_skills' => ['尚可', '歓迎', '尚可スキル', '歓迎スキル', 'WANT', 'BETTER'],
        'interviews_count' => ['面談', '面談回数'],
        'headcount' => ['人数', '募集人数', '募集'],
        'description' => ['概要', '内容', '業務内容', '作業内容', '詳細'],
        'conditions' => ['備考', 'その他', '条件', '精算'],
    ];

    private static function clean(string $l): string
    {
        return trim(preg_replace('/^[\s・●■◆◇□○\-\*＊【\[]+/u', '', $l));
    }

    public static function parse(?string $text): array
    {
        $out = [];
        $lines = explode("\n", str_replace("\r", '', (string) $text));
        $current = null; $buckets = [];
        foreach ($lines as $line) {
            $l = trim($line);
            if ($l === '') { $current = null; continue; }
            $matched = false;
            foreach (self::LABELS as $field => $labels) {
                foreach ($labels as $lb) {
                    $re = '/^[\s・●■◆◇□○\-\*＊【\[]*'.preg_quote($lb, '/').'[^】\]：:]{0,6}[】\]]?\s*[：:】\]]?\s*(.*)$/iu';
                    if (mb_strlen($l) < 120 && preg_match($re, $l, $m)) {
                        if ($field === 'period' && str_contains(mb_substr($l, 0, 6), '開始')) continue;
                        $current = $field; $buckets[$field] ??= [];
                        if (trim($m[1]) !== '') $buckets[$field][] = trim($m[1]);
                        $matched = true; break;
                    }
                }
                if ($matched) break;
            }
            if (!$matched && $current) $buckets[$current][] = self::clean($l);
        }
        foreach ($buckets as $k => $v) $out[$k] = implode(str_ends_with($k, 'skills') ? '、' : "\n", array_filter($v));

        if (!empty($out['title'])) $out['title'] = mb_substr(str_replace(['【', '】', '[', ']'], '', explode("\n", $out['title'])[0]), 0, 80);
        else foreach ($lines as $l) if (trim($l) !== '') { $out['title'] = mb_substr(self::clean($l), 0, 80); break; }

        if (!empty($out['price'])) {
            $p = mb_convert_kana($out['price'], 'as');
            $out['price_note'] = $out['price'];
            preg_match_all('/(\d+(?:\.\d+)?)\s*万/u', $p, $m);
            $out['price'] = $m[1] ? max(array_map('floatval', $m[1])) : (preg_match('/\d{2,3}/', $p, $m2) ? (float) $m2[0] : null);
        }
        $all = mb_convert_kana((string) $text, 'as');
        if (preg_match('/フルリモート|完全リモート|リモート\s*可?\s*[（(]?\s*100/u', $all)) $out['work_style'] = 'remote';
        elseif (preg_match('/リモート|在宅|テレワーク/u', $all)) $out['work_style'] = preg_match('/常駐|出社/u', $all) ? 'hybrid' : 'remote';
        elseif (preg_match('/常駐|出社/u', $all)) $out['work_style'] = 'onsite';
        if (!empty($out['interviews_count'])) $out['interviews_count'] = preg_match('/(\d)/', mb_convert_kana($out['interviews_count'], 'n'), $m) ? (int) $m[1] : 1;
        if (!empty($out['headcount'])) $out['headcount'] = preg_match('/(\d+)/', mb_convert_kana($out['headcount'], 'n'), $m) ? (int) $m[1] : 1;
        if (!empty($out['start_date'])) {
            $s = mb_convert_kana($out['start_date'], 'as');
            if (preg_match('/(20\d{2})[\/\-年](\d{1,2})/u', $s, $m)) $out['start_date'] = sprintf('%s-%02d-01', $m[1], $m[2]);
            elseif (preg_match('/(\d{1,2})\s*月/u', $s, $m)) {
                $mm = (int) $m[1]; $y = (int) date('Y');
                $out['start_date'] = sprintf('%d-%02d-01', $mm < (int) date('n') ? $y + 1 : $y, $mm);
            } elseif (preg_match('/即日|ASAP/iu', $s)) $out['start_date'] = today_str();
            else { $out['conditions'] = trim(($out['conditions'] ?? '')."\n開始：".$out['start_date']); unset($out['start_date']); }
        }
        if (preg_match('/(\d+)\s*年以上/u', $all, $m)) $out['min_years'] = (int) $m[1];
        // 本文全体から、技術マスタにある技術を探す。必須に無いものは「尚可」の候補にする（管理者が登録の前に確かめる）
        $out['detected'] = \App\Support\SkillCatalog::detect($text);
        $req = array_map('norm_skill', parse_list($out['required_skills'] ?? ''));
        $extra = array_values(array_filter($out['detected'], fn ($t) => !in_array(norm_skill($t), $req, true)));
        if (empty($out['required_skills']) && $out['detected']) $out['required_skills'] = implode('、', $out['detected']);
        elseif ($extra) {
            $pref = parse_list($out['preferred_skills'] ?? '');
            $prefN = array_map('norm_skill', $pref);
            foreach ($extra as $t) if (!in_array(norm_skill($t), $prefN, true)) $pref[] = $t;
            $out['preferred_skills'] = implode('、', $pref);
        }
        return $out;
    }
}
