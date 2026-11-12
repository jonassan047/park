<?php

namespace App\Support;

// 案件の「必須の業務」「尚可の業務」（業務経験・業務領域の項目の名前）。
// 項目の名前と、案件での言い方（caseWords）は skill_form.json が正本。ここは、拾い出しと、文字列（改行つなぎ）との行き来だけ。
class CaseExp
{
    // 1行の文から、業務の項目の名前を拾う（JSON の並び）。消す言い方（SkillCatalog::STRIP）を先に消す。
    // 英数字だけの言い方は、前後が英数字でないときだけ当てる（大文字小文字は区別しない）
    public static function detectLine(string $line): array
    {
        $text = SkillCatalog::strip($line);
        $found = [];
        foreach (SkillForm::caseItems() as $name => $it) {
            foreach ($it['words'] as $w) {
                $w = mb_convert_kana($w, 'as');
                if (preg_match('/^[\x20-\x7e]+$/', $w)) {
                    if (preg_match('/(?<![A-Za-z0-9])'.preg_quote($w, '/').'(?![A-Za-z0-9])/i', $text)) { $found[] = $name; break; }
                } elseif (mb_strpos($text, $w) !== false) { $found[] = $name; break; }
            }
        }
        return $found;
    }

    // 必須・尚可の行（文の配列）から拾う。['required' => [名前…], 'preferred' => [名前…]]（JSON の並び。両方にあれば必須だけ）
    public static function fromLines(array $req, array $pref): array
    {
        $r = []; $p = [];
        foreach ($req as $l) foreach (self::detectLine((string) $l) as $n) $r[$n] = true;
        foreach ($pref as $l) foreach (self::detectLine((string) $l) as $n) if (!isset($r[$n])) $p[$n] = true;
        return ['required' => self::order(array_keys($r)), 'preferred' => self::order(array_keys($p))];
    }

    // 改行でつないだ文を、名前の配列にする。今の JSON にある名前だけ（消えた項目は黙って外す）
    public static function split(?string $v): array
    {
        if ($v === null || trim($v) === '') return [];
        $names = array_map('trim', preg_split('/\R/u', $v));
        return self::order($names);
    }

    // 名前の配列を、JSON の並びにして、改行でつなぐ。空なら null
    public static function join(array $names): ?string
    {
        $o = self::order($names);
        return $o ? implode("\n", $o) : null;
    }

    // 項目の id => 名前（案件の画面の入力 exp[id] を名前にするため）
    public static function itemsById(): array
    {
        $out = [];
        foreach (SkillForm::caseItems() as $name => $it) $out[$it['id']] = $name;
        return $out;
    }

    // 今の JSON にある名前だけを、JSON の並びで返す（重なりは1つに）
    private static function order(array $names): array
    {
        $out = [];
        foreach (array_keys(SkillForm::caseItems()) as $n) if (in_array($n, $names, true)) $out[] = $n;
        return $out;
    }
}
