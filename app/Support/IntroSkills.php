<?php

namespace App\Support;

use App\Services\CaseParser;

// 推薦文（書式がばらばらな箇条書きの紹介文）から、エンジニアのスキルを抜き出す（v0.19.0）。
// 案件メールと同じ辞書（技術マスタの確認済みの語＋業務の項目）を使う。外部のサービス・AI には送らない（このPCの中で読むだけ）。
// 新しい語はマスタに足さない。氏名・年齢・希望月額などの行は読まない。
class IntroSkills
{
    // この見出しの行（とその下の行）は読まない（個人の情報・条件・人物の文）
    const SKIP_LABELS = ['氏名', '名前', 'イニシャル', '年齢', '生年月日', '性別', '最寄駅', '最寄り駅', '住所', '所属', '国籍', '希望月額', '希望単価', '単価', '金額',
        '就業時期', '稼働', '稼働開始', '開始時期', '人物', '人柄', '人物像', '希望条件', '希望', '備考', 'その他', '面談'];

    // スキル表に置ける技術の分類（これ以外の分類は「置き場所が無い言葉」にする）
    const PLACEABLE = ['lang', 'fw', 'db', 'os', 'cloud', 'infra', 'tool', 'role', 'method', 'langs'];

    // 本文から ['rows' => [['section', 'parent', 'item', 'other_text', 'years_min']…], 'picked' => [名前…], 'unplaced' => [言葉…], 'skipped' => 読まなかった行数]
    public static function extract(string $body): array
    {
        $body = str_replace(["\r\n", "\r"], "\n", $body);
        $index = SkillForm::normIndex();
        $rows = []; $unplaced = []; $skipped = 0;
        $skipping = false; $skipBy = '';   // skipBy：読まない範囲を始めた見出しの種類（'mark'：【】や■の見出し、'colon'：「X：…」の行）
        foreach (explode("\n", $body) as $raw) {
            $line = trim($raw);
            if ($line === '') continue;
            $h = self::head($line);
            if ($h !== null) {
                if (in_array($h['label'], self::SKIP_LABELS, true)) { $skipping = true; $skipBy = $h['kind']; $skipped++; continue; }
                // 【】・■の見出しで始まった読まない範囲は、次の【】・■の見出しまで。「X：…」の行で始まった範囲は、次の見出しの行まで
                if ($skipping && ($skipBy === 'colon' || $h['kind'] === 'mark')) $skipping = false;
            } elseif ($skipping && $skipBy === 'colon' && !preg_match('/^[\s　（(※]/u', $raw)) {
                // 「X：…」の行の続きは、行頭が空白・括弧・「※」の行だけ
                $skipping = false;
            }
            if ($skipping) { $skipped++; continue; }
            self::readLine($line, $index, $rows, $unplaced);
        }
        $picked = [];
        foreach ($rows as $r) $picked[] = $r['other_text'] !== '' ? $r['other_text'] : $r['item'];
        return ['rows' => array_values($rows), 'picked' => array_values(array_unique($picked)), 'unplaced' => array_values(array_unique($unplaced)), 'skipped' => $skipped];
    }

    // 見出しの行か。['label' => 見出しの言葉, 'kind' => 'mark' か 'colon'] か null。
    // 【X】・■X・●X・◆X は mark、「X：…」「X:…」（Xは短い言葉）は colon
    private static function head(string $line): ?array
    {
        if (preg_match('/^【([^】]*)】/u', $line, $m)) return ['label' => self::label($m[1]), 'kind' => 'mark'];
        if (preg_match('/^[■●◆□▼◇○◎▲]\s*(.*)$/u', $line, $m)) {
            $s = preg_split('/[：:]/u', $m[1], 2);
            return ['label' => self::label($s[0]), 'kind' => 'mark'];
        }
        $line = (string) preg_replace(CaseParser::BULLET, '', $line, 1); // 「・年齢：30歳」のような箇条書きの印を外してから見る
        if (preg_match('/^([^：:]{1,15})[：:]/u', $line, $m)) return ['label' => self::label($m[1]), 'kind' => 'colon'];
        return null;
    }

    // 見出しの言葉をそろえる（空白・※・括弧の中を外す）
    private static function label(string $s): string
    {
        $s = (string) preg_replace('/[（(].*?[)）]/u', '', $s);
        return (string) preg_replace('/[\s　※]+/u', '', $s);
    }

    // 1行を読んで、$rows・$unplaced に足す
    private static function readLine(string $line, array $index, array &$rows, array &$unplaced): void
    {
        $line = CaseKeywords::cleanAll([$line])[0] ?? '';
        if ($line === '') return;
        $text = mb_strtolower(mb_convert_kana($line, 'as'));
        $hits = CaseKeywords::pickWithPos($line);
        $lineUnplaced = [];
        foreach ($hits as $i => [$name, , $end]) {
            $t = SkillCatalog::find($name);
            $cat = $t ? (string) $t[2] : 'other';
            if (!in_array($cat, self::PLACEABLE, true) && !isset($index[norm_skill($name)])) { $lineUnplaced[] = $name; continue; }
            // 年数：この語のあと、次の語の始まりまでの文字から
            $next = $hits[$i + 1][1] ?? mb_strlen($text);
            $years = self::yearsIn(mb_substr($text, $end, max(0, $next - $end)));
            self::add($rows, SkillForm::place($name, $cat, $years, $index));
        }
        // 業務の項目（業務経験・業務領域。年数なし）
        $items = SkillForm::caseItems();
        foreach (CaseExp::detectLine($line) as $name) {
            if (!isset($items[$name])) continue;
            self::add($rows, ['section' => $items[$name]['section'], 'parent' => '', 'item' => $name, 'other_text' => '', 'years_min' => null]);
            // 業務の項目として入った言葉は、「入れなかった言葉」に出さない
            foreach ($items[$name]['words'] as $w) {
                $lineUnplaced = array_values(array_filter($lineUnplaced, fn ($c) => mb_stripos($c, $w) === false && mb_stripos($w, $c) === false));
            }
        }
        foreach ($lineUnplaced as $c) $unplaced[] = $c;
    }

    // 「n年」「nヶ月」を年数（小数）にする。無ければ null。
    // 西暦（2019年）・「2020年4月」・「年度」・「年目」は年数と読まない（年は1〜2桁だけ）。月は月数÷12
    private static function yearsIn(string $seg): ?float
    {
        $y = null; $m = null;
        if (preg_match('/(?<!\d)(\d{1,2}(?:\.\d+)?)\s*年(?!度|目|\s*\d{1,2}\s*月)/u', $seg, $a)) $y = (float) $a[1];
        if (preg_match('/(?<!\d)(\d{1,3})\s*(?:ヶ月|か月|カ月|ヵ月|ケ月)/u', $seg, $b)) $m = (int) $b[1] / 12;
        if ($y === null && $m === null) return null;
        return ($y ?? 0.0) + ($m ?? 0.0);
    }

    // 同じ項目は1行（年数は大きいほう）
    private static function add(array &$rows, array $r): void
    {
        $k = $r['section']."\0".$r['parent']."\0".$r['item']."\0".($r['other_text'] === '' ? '' : mb_strtolower((string) norm_skill($r['other_text'])));
        if (!isset($rows[$k])) { $rows[$k] = $r; return; }
        if (SkillForm::rank($r['years_min']) > SkillForm::rank($rows[$k]['years_min'])) $rows[$k]['years_min'] = $r['years_min'];
    }
}
