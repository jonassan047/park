<?php

namespace App\Services;

use App\Support\SkillCatalog;
use Illuminate\Support\Carbon;

// 候補者の紹介メールの本文から、エンジニアのプロフィールの項目を抜き出す（v0.18.0）。
// このサーバーの中だけで読む（外部サービス・AI には送らない）。貼られた本文は、どこにもしまわない。
// 読んだ結果は ['fields' => 列 => 値, 'raw' => 列 => メールの文, 'skills' => …, 'unknown_words' => …, 'notes' => …, 'unread' => …, 'ref' => …, 'multiple' => bool]
class EngineerMailParser
{
    // 項目名の辞書（キー => 項目名）。足すときはここだけ。項目名は空白を消し、全角英数を半角にして、まるごと同じときだけ当てる
    const LABELS = [
        'name' => ['氏名', '名前', '候補者', '候補者名', '要員名', '技術者名'],
        'age' => ['年齢'],
        'gender' => ['性別'],
        'station' => ['最寄駅', '最寄り駅', '最寄', '最寄り', '最寄駅・路線'],
        'affiliation' => ['所属', '所属会社', '雇用形態'],
        'career' => ['経験', '経歴', '職務経歴', '職務概要', '概要'],
        'achievements' => ['実績', '主な実績'],
        'years' => ['経験年数', 'IT経験年数', 'IT経験'],
        'roles' => ['得意分野', '得意', '希望業務', '希望ポジション', '希望職種', 'ポジション'],
        'skills' => ['言語スキル', '言語', 'スキル', '他スキル', 'その他スキル', '開発言語', '開発環境', 'DB', 'OS', 'ツール', 'フレームワーク', 'FW'],
        'english' => ['英語', '語学'],
        'price' => ['希望月額', '希望単価', '単価', '希望金額', '月額'],
        'start' => ['就業時期', '稼働開始', '稼働開始日', '稼働可能日', '参画可能時期', '参画時期', '開始', '稼働'],
        'person' => ['人物', '人柄', '人物像', '性格', '人物評'],
        'style' => ['勤務形態', '働き方', '勤務条件', 'リモート'],
        'notes' => ['備考', '特記事項', 'その他', '補足'],
    ];

    // 「得意分野」の側の項目名（希望業務より先に並べる）
    const ROLE_STRONG = ['得意分野', '得意'];

    const MAX_ROLES = 255;
    const MAX_ENGLISH = 255;
    // 行頭の印
    const MARK = '/^[・■◆◇●○□▼\-\*＊]/u';
    // 区切り線（3つ以上）
    const RULE = '/^[\-－=＝━─\*＊_＿]{3,}/u';
    // 結びのあいさつ（このあとは署名）
    const CLOSING = '/^(?:以上|よろしく|宜しく|お願い(?:い?たし)?ます)/u';
    // 最寄りの路線・駅の長さの上限（字）
    const MAX_STATION = 255;
    // 経験年数の上限（これを超える数は読まない）
    const MAX_YEARS = 60;
    // 1人に1つの項目（2回出たら、別の人が混ざっているとみなす）
    const SINGLE = ['age', 'gender', 'station', 'years', 'price', 'start'];
    // 路線の名前らしい言葉（「線」で終わらないもの）
    const LINE_WORDS = 'JR|東急|メトロ|地下鉄|都営|京王|小田急|京急|西武|東武|京成|相鉄|私鉄';

    private static ?array $labelMap = null;

    // 前後の空白を消す（全角空白でも文字が壊れないよう trim() は使わない）
    private static function t(string $s): string
    {
        return preg_replace('/^[\s　]+|[\s　]+$/u', '', $s) ?? $s;
    }

    // 項目名をそろえる（空白を消し、全角英数を半角に、大文字に）
    private static function normLabel(string $s): string
    {
        return mb_strtoupper(preg_replace('/[\s　]+/u', '', mb_convert_kana($s, 'asKV')) ?? $s);
    }

    private static function labelKey(string $label): ?string
    {
        if (self::$labelMap === null) {
            self::$labelMap = [];
            foreach (self::LABELS as $key => $names) foreach ($names as $n) self::$labelMap[self::normLabel($n)] = $key;
        }
        return self::$labelMap[self::normLabel($label)] ?? null;
    }

    // 印を外す
    private static function stripMark(string $l): string
    {
        return self::t(preg_replace('/^[\s　・■◆◇●○□▼\-\*＊]+/u', '', $l) ?? $l);
    }

    // 結び・署名の行か（以上・よろしく・連絡先・会社名で始まる行。会社名は短い行だけ）
    private static function isSignature(string $body): bool
    {
        $h = mb_convert_kana($body, 'asKV');
        if (preg_match(self::CLOSING, $h) || preg_match('/^(?:TEL|電話|携帯|FAX|E-?MAIL|メール|URL|HP|〒|https?:)/iu', $h)) return true;
        return mb_strlen($h) <= 40 && !str_contains($h, '。') && preg_match('/^(?:株式会社|有限会社|合同会社|\(株\)|㈱)/u', $h) === 1;
    }

    // 見出しだけの行か（「（候補者）」「【候補者】」「■候補者情報」など。コロンが無く、文ではない）
    private static function isTitleLine(string $h): bool
    {
        if (preg_match('/[：:]/u', $h)) return false;
        return preg_match('/^[\(（【\[■◆◇●○□▼・<＜\s　]*[^。、，,]{0,15}(?:候補者|要員|人材|技術者)[^。、，,]{0,15}[\)）】\]>＞]?$/u', $h) === 1;
    }

    // 項目の行か。[キー（辞書に無ければ null）, 項目名, 同じ行の値] か、違えば null。「【スキル】」だけの行は値が空
    private static function itemLine(string $l): ?array
    {
        $deco = '(?:[・■◆◇●○□▼\-\*＊]\s*)*';
        if (preg_match('/^'.$deco.'[【\[]?\s*(?<label>[^：:【\[\]】。、，,]{1,20}?)\s*[】\]]?\s*[：:]\s*(?<val>.*)$/us', $l, $m)) {
            return [self::labelKey($m['label']), self::t($m['label']), self::t($m['val'])];
        }
        // 項目名だけの行（印か括弧が付いているものだけ。辞書にあるものだけ）
        if (preg_match('/^'.$deco.'[【\[]\s*(?<label>[^：:【\[\]】]{1,20}?)\s*[】\]]\s*$/u', $l, $m)
            || preg_match('/^[■◆◇●○□▼]+\s*(?<label>[^：:■◆◇●○□▼]{1,20}?)\s*[■◆◇●○□▼]*\s*$/u', $l, $m)) {
            $key = self::labelKey($m['label']);
            if ($key !== null) return [$key, self::t($m['label']), ''];
        }
        return null;
    }

    // 項目名の無い、印で始まる行：年齢・性別なら ['age' => …, 'gender' => …]、違えば null（特記事項の候補）
    private static function looseFacts(string $body): ?array
    {
        $h = mb_convert_kana($body, 'asKV');
        if (mb_strlen($h) > 30) return null;
        $out = [];
        if (preg_match('/^(\d{1,2})\s*歳/u', $h, $m)) $out['age'] = (int) $m[1];
        if (preg_match('/男性|女性/u', $h, $m)) $out['gender'] = str_starts_with($m[0], '男') ? '男' : '女';
        elseif (preg_match('/(?:^|[\s、,・\/])(男|女)(?:$|[\s、,・\/])/u', $h, $m)) $out['gender'] = $m[1];
        return $out ?: null;
    }

    // 候補者が2人以上か（氏名の項目が2回以上、または「1人目」「候補者1」「候補者2」のような見出しが2つ以上）
    private static function countNumbered(array $lines): int
    {
        $n = 0;
        foreach ($lines as $raw) {
            $h = mb_convert_kana($raw, 'asKV');
            if (preg_match('/(?:候補者|要員|技術者)\s*(?:No\.?\s*)?[1-9①-⑩](?![\d名])|[1-9①-⑩]\s*人目/u', $h)) $n++;
        }
        return $n;
    }

    private static function blank(): array
    {
        return ['fields' => [], 'raw' => [], 'skills' => [], 'unknown_words' => [], 'notes' => [], 'unread' => [], 'ref' => [], 'multiple' => false];
    }

    public static function parse(string $text): array
    {
        $out = self::blank();
        $lines = explode("\n", str_replace("\r", '', $text));

        // 1. 行ごとに、項目（ブロック）に分ける
        $blocks = [];   // ['key', 'label', 'orig', 'lines', 'head']
        $notes = [];
        $unread = [];
        $loose = [];    // 項目名の無い行から読んだ年齢・性別
        $cur = null;
        $closed = false; // 「以上」「よろしく」のあとは、署名として読まない
        foreach ($lines as $raw) {
            $l = self::t($raw);
            if ($l === '') { $cur = null; continue; }
            $h = mb_convert_kana($l, 'asKV');
            if (preg_match(self::RULE, $h)) { $cur = null; continue; }
            // 辞書に当たる項目を先に調べる（見出しとして黙って飛ばさない）。ただし「【候補者】」だけの見出しは、項目にしない
            $item = self::itemLine($l);
            if ($item && $item[0] === 'name' && $item[2] === '' && self::normLabel($item[1]) === self::normLabel('候補者') && !preg_match('/[：:]/u', $h)) { $cur = null; continue; }
            if (!$item && self::isTitleLine($h)) { $cur = null; continue; }
            // 結びのあいさつ・署名（以上・よろしく・連絡先・会社名）は、続きの行にも特記事項にも入れない
            if (!$item && ($closed || self::isSignature(self::stripMark($l)))) {
                if (preg_match(self::CLOSING, self::stripMark($l))) $closed = true;
                $cur = null;
                $unread[] = $l;
                continue;
            }
            if ($item) {
                [$key, $label, $val] = $item;
                $blocks[] = ['key' => $key, 'label' => $label, 'orig' => $l, 'lines' => $val === '' ? [] : [$val], 'head' => $val === ''];
                $cur = array_key_last($blocks);
                continue;
            }
            // 項目名の無い「山田一郎(IY)」だけの行は、氏名として読む（項目名のある氏名が無いときだけ）
            if ($cur === null && !array_filter($blocks, fn ($b) => $b['key'] === 'name') && preg_match('/^(?:[・■◆●]\s*)?[^：:\(（]{1,20}[\(（][A-Za-z]{2,3}[\)）]$/u', $h)) {
                $blocks[] = ['key' => 'name', 'label' => '氏名', 'orig' => $l, 'lines' => [self::stripMark($l)], 'head' => false];
                continue;
            }
            $marked = preg_match(self::MARK, $l) === 1;
            // 続きの行：印で始まらない行（値が次の行から始まる項目は、印のある行も）
            if ($cur !== null && (!$marked || $blocks[$cur]['head'])) {
                $blocks[$cur]['lines'][] = self::stripMark($l);
                continue;
            }
            $cur = null;
            if ($marked) {
                $body = self::stripMark($l);
                if ($body === '') continue;
                if ($f = self::looseFacts($body)) $loose = $f + $loose;
                else $notes[] = $body;
                continue;
            }
            $unread[] = $l; // あいさつ・署名など
        }

        // 2. 候補者が2人以上なら、何も読まない
        $nameBlocks = array_values(array_filter($blocks, fn ($b) => $b['key'] === 'name'));
        $dup = false; // 年齢・希望月額など、1人に1つの項目が2回出たら、別の人の経歴が混ざっているとみなす
        foreach (self::SINGLE as $k) if (count(array_filter($blocks, fn ($b) => $b['key'] === $k)) >= 2) $dup = true;
        if (count($nameBlocks) >= 2 || $dup || self::countNumbered($lines) >= 2) {
            $out['multiple'] = true;
            return $out;
        }

        // 3. 項目ごとに読む
        $by = [];
        foreach ($blocks as $b) {
            if ($b['key'] === null) { $unread[] = self::fullText($b); continue; }
            $by[$b['key']][] = $b;
        }
        $f = [];
        $raw = [];
        $text0 = fn (array $b) => self::t(implode("\n", $b['lines'])); // 値（続きの行も、改行のまま）
        $first = fn (array $b) => self::t((string) ($b['lines'][0] ?? '')); // 最初の行だけ

        // 氏名：「山田一郎(IY)」→ 氏名と、かっこの中のイニシャル。イニシャルだけの書き方もある
        if (isset($by['name'][0])) {
            $b = $by['name'][0];
            $v = mb_convert_kana($first($b), 'asKV');
            if ($v !== '') {
                if (preg_match('/^(.*?)\s*[\(（]\s*([A-Za-z]{2,3})\s*[\)）]\s*$/u', $v, $m)) {
                    $name = $m[1];
                    $f['initials'] = implode('.', str_split(strtoupper($m[2])));
                    $raw['initials'] = $m[2];
                } elseif (preg_match('/^([A-Za-z])\.?\s*([A-Za-z])\.?$/', $v, $m)) {
                    $name = '';
                    $f['initials'] = strtoupper($m[1]).'.'.strtoupper($m[2]);
                    $raw['initials'] = $v;
                } else $name = $v;
                $name = preg_replace('/[\s　]+/u', '', preg_replace('/[\(（][^\)）]*[\)）]/u', '', $name) ?? $name) ?? $name;
                $name = preg_replace('/(?:さん|氏|様|殿)$/u', '', $name) ?? $name;
                if ($name !== '') { $f['name'] = $name; $raw['name'] = $first($b); }
            }
        }

        // 年齢 → 生まれた年（今年 − 年齢）
        $age = null; $ageRaw = null;
        if (isset($by['age'][0])) {
            $b = $by['age'][0];
            // 「56歳」か「56」だけ読む（「50代」「50代前半」は読まない）
            $av = mb_convert_kana($first($b), 'asKV');
            if (preg_match('/(?<!\d)(\d{1,3})\s*歳/u', $av, $m) || preg_match('/^(\d{1,3})$/', $av, $m)) { $age = (int) $m[1]; $ageRaw = $first($b); }
            else $unread[] = self::fullText($b);
        }
        if ($age === null && isset($loose['age'])) { $age = $loose['age']; $ageRaw = $age.'歳'; }
        if ($age !== null && $age > 0 && $age < 100) {
            $f['birth_year'] = Carbon::now()->year - $age;
            $raw['birth_year'] = $ageRaw;
        }

        // 性別
        $g = null; $gRaw = null;
        if (isset($by['gender'][0])) {
            $b = $by['gender'][0];
            $v = mb_convert_kana($first($b), 'asKV');
            if (in_array($v, ['男性', '男'], true)) { $g = '男'; $gRaw = $first($b); }
            elseif (in_array($v, ['女性', '女'], true)) { $g = '女'; $gRaw = $first($b); }
            else $unread[] = self::fullText($b);
        }
        if ($g === null && isset($loose['gender'])) { $g = $loose['gender']; $gRaw = $g.'性'; }
        if ($g !== null) { $f['gender'] = $g; $raw['gender'] = $gRaw; }

        // 最寄駅
        if (isset($by['station'][0])) {
            $b = $by['station'][0];
            $st = self::station($first($b));
            if ($st) {
                foreach ($st as $col => $val) { $f[$col] = $val; $raw[$col] = $first($b); }
            } else $unread[] = self::fullText($b);
        }

        // 所属（取り込まない。参考に出すだけ）
        if (isset($by['affiliation'][0])) $out['ref']['affiliation'] = $text0($by['affiliation'][0]);

        // 職務概要：経験 ＋ 実績（「【実績】」を付けて後ろに足す）
        $career = [];
        foreach ($by['career'] ?? [] as $b) if (($v = $text0($b)) !== '') $career[] = $v;
        $ach = [];
        foreach ($by['achievements'] ?? [] as $b) if (($v = $text0($b)) !== '') $ach[] = $v;
        if ($ach) $career[] = "【実績】\n".implode("\n", $ach);
        if ($career) { $f['career_summary'] = implode("\n", $career); $raw['career_summary'] = $f['career_summary']; }

        // 経験年数（0.5 刻み）
        if (isset($by['years'][0])) {
            $b = $by['years'][0];
            // 1〜2桁の数＋「年」だけ読む（「2010年」のような4桁や、60を超える数は読まない）
            if (preg_match('/(?<![\d.])(\d{1,2}(?:\.\d+)?)\s*年/u', mb_convert_kana($first($b), 'asKV'), $m) && (float) $m[1] <= self::MAX_YEARS) {
                $f['years_exp'] = self::num(round((float) $m[1] * 2) / 2);
                $raw['years_exp'] = $first($b);
            } else $unread[] = self::fullText($b);
        }

        // 得意分野・希望業務（改行は「 」。得意分野を先に、「 ／ 」でつなぐ）
        if (!empty($by['roles'])) {
            $strong = []; $wish = [];
            foreach ($by['roles'] as $b) {
                $v = self::t(preg_replace('/\s*\n\s*/u', ' ', implode("\n", $b['lines'])) ?? '');
                if ($v === '') continue;
                if (in_array(self::normLabel($b['label']), array_map([self::class, 'normLabel'], self::ROLE_STRONG), true)) $strong[] = $v; else $wish[] = $v;
            }
            $all = implode(' ／ ', array_merge($strong, $wish));
            if ($all !== '') { $f['desired_roles'] = mb_substr($all, 0, self::MAX_ROLES); $raw['desired_roles'] = $all; }
        }

        // 英語・語学
        if (isset($by['english'][0])) {
            $b = $by['english'][0];
            $v = self::t(preg_replace('/\s*\n\s*/u', ' ', implode("\n", $b['lines'])) ?? '');
            if ($v !== '') {
                $v = self::normLabel($b['label']) === self::normLabel('英語') ? '英語：'.$v : $v;
                $f['english_level'] = mb_substr($v, 0, self::MAX_ENGLISH);
                $raw['english_level'] = $v;
            }
        }

        // 希望月額
        if (isset($by['price'][0])) {
            $b = $by['price'][0];
            $p = self::price($first($b));
            if ($p) {
                foreach ($p as $col => $val) { $f[$col] = $val; $raw[$col] = $first($b); }
            } else $unread[] = self::fullText($b);
        }

        // 就業時期
        if (isset($by['start'][0])) {
            $b = $by['start'][0];
            $d = self::startDate($first($b));
            if ($d) { $f['available_from'] = $d; $raw['available_from'] = $first($b); }
            else $unread[] = self::fullText($b);
        }

        // 人物
        $person = [];
        foreach ($by['person'] ?? [] as $b) if (($v = $text0($b)) !== '') $person[] = $v;
        if ($person) { $f['appeal'] = implode("\n", $person); $raw['appeal'] = $f['appeal']; }

        // 働き方：本文全体から（案件の取り込みと同じ言葉。どれも無ければ変えない）
        if ($ws = self::workStyle($text)) { $f['work_style'] = $ws[0]; $raw['work_style'] = $ws[1]; }

        // 備考・特記事項・その他は、行ごとに特記事項へ
        foreach ($by['notes'] ?? [] as $b) foreach ($b['lines'] as $v) if (($v = self::stripMark($v)) !== '') $notes[] = $v;
        // 勤務形態などの行（style）は、ここでは読まない（働き方は本文全体で見た）

        // スキル
        $words = [];
        foreach ($by['skills'] ?? [] as $b) foreach ($b['lines'] as $line) {
            foreach (preg_split('/[、,，\/／・\s　]+/u', $line, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                $w = self::t($w);
                if ($w !== '') $words[] = $w;
            }
        }
        $seen = []; $unk = [];
        foreach (array_slice($words, 0, 100) as $w) {
            $hits = self::resolveSkill($w);
            foreach ($hits as $hit) {
                if (isset($seen[$hit[1]])) continue;
                $seen[$hit[1]] = true;
                $out['skills'][] = ['name' => $hit[1], 'category' => $hit[2], 'word' => $w];
            }
            if (!$hits) $unk[$w] = true;
        }

        // 同じ行・同じ文は1つに
        $out['notes'] = array_values(array_unique($notes));
        $out['unknown_words'] = array_keys($unk);
        $out['unread'] = array_values(array_filter(array_unique(array_map('strval', $unread)), fn ($x) => $x !== ''));
        $out['fields'] = $f;
        $out['raw'] = $raw;
        return $out;
    }

    // 項目の行と、その続きの行（読めなかったときに、そのまま見せる）
    private static function fullText(array $b): string
    {
        $rest = array_slice($b['lines'], $b['head'] ? 0 : 1);
        return $b['orig'].($rest ? "\n".implode("\n", $rest) : '');
    }

    // 言葉 → 技術マスタの [id, 正式名, 分類] の並び（当たらなければ空）。マスタには足さない
    private static function resolveSkill(string $w): array
    {
        if (mb_strlen($w) > 40) return [];
        if ($hit = SkillCatalog::find($w)) return [$hit];
        $s = preg_replace('/(?:可能|対応可|経験あり|経験|可)$/u', '', $w) ?? $w; // 「PL可能」→「PL」
        if ($s !== '' && $s !== $w && ($hit = SkillCatalog::find($s))) return [$hit];
        $out = [];
        foreach (SkillCatalog::detect($w) as $name) if ($hit = SkillCatalog::find((string) $name)) $out[] = $hit;
        return $out;
    }

    // 最寄駅の文 → ['nearest_line' => …, 'nearest_station' => …, 'walk_minutes' => …]（読めなければ空）
    private static function station(string $s): array
    {
        $s = self::t(mb_convert_kana($s, 'asKV'));
        $out = [];
        if (preg_match('/徒歩\s*(\d+)\s*分/u', $s, $m)) { $out['walk_minutes'] = (int) $m[1]; $s = self::t(str_replace($m[0], '', $s)); }
        $s = self::t(preg_replace('/[\(（]\s*[\)）]/u', '', $s) ?? $s);
        $line = null;
        if (preg_match('/^(.*?)\s*[\(（]([^\)）]*)[\)）]\s*(.*)$/u', $s, $m)) {
            $inner = self::t($m[2]);
            if (self::isLine($inner)) { $line = $inner; $s = self::t($m[1].' '.$m[3]); }
            else $s = self::t($m[1].' '.$m[3]);
        } else {
            $parts = preg_split('/\s+/u', $s, -1, PREG_SPLIT_NO_EMPTY);
            if (count($parts) >= 2 && self::isLine($parts[0])) { $line = $parts[0]; $s = implode(' ', array_slice($parts, 1)); }
            elseif (count($parts) === 1 && preg_match('/線$/u', $s)) { $line = $s; $s = ''; } // 路線だけの行は、駅に入れない
        }
        $s = self::t(preg_replace('/駅$/u', '', $s) ?? $s);
        if ($line !== null) $out['nearest_line'] = mb_substr($line, 0, self::MAX_STATION);
        if ($s !== '') $out['nearest_station'] = mb_substr($s, 0, self::MAX_STATION);
        return isset($out['nearest_line']) || isset($out['nearest_station']) ? $out : [];
    }

    private static function isLine(string $s): bool
    {
        return preg_match('/線$|'.self::LINE_WORDS.'/u', $s) === 1;
    }

    // 希望月額の文 → ['desired_price' => …, 'min_price' => …]（数が無ければ空）。範囲は上が希望・下が下限。1つなら希望額
    private static function price(string $s): array
    {
        $s = mb_convert_kana($s, 'asKV');
        // 「70～80万」「70万～80万円」の形（前の数の「万」「円」はあってもなくてもよい）
        if (preg_match('/(\d+(?:\.\d+)?)\s*(?:万円?)?\s*[～~〜\-－ー]\s*(\d+(?:\.\d+)?)\s*万/u', $s, $m)) {
            $lo = (float) $m[1]; $hi = (float) $m[2];
            if ($lo > $hi) [$lo, $hi] = [$hi, $lo];
            return $lo < $hi ? ['desired_price' => self::num($hi), 'min_price' => self::num($lo)] : ['desired_price' => self::num($hi)];
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*万/u', $s, $m)) return ['desired_price' => self::num((float) $m[1])];
        return [];
    }

    // 整数で言い切れる数は整数に（180.0 → 180）
    private static function num(float $n): int|float
    {
        return $n == floor($n) ? (int) $n : $n;
    }

    // 就業時期の文 → Y-m-d（読めなければ null）。かっこの中は読まない
    private static function startDate(string $s): ?string
    {
        $s = mb_convert_kana($s, 'asKV');
        $s = preg_replace('/[\(（][^\)）]*[\)）]/u', '', $s) ?? $s;
        $now = Carbon::now(); // テストで travelTo できるよう、date() ではなく Carbon を使う
        if (preg_match('/即日|ASAP|すぐ|即時/iu', $s)) return $now->format('Y-m-d');
        if (preg_match('/(20\d{2})\s*[\/\-年\.]\s*(\d{1,2})/u', $s, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12) return sprintf('%s-%02d-01', $m[1], $m[2]);
        if (preg_match('/(\d{1,2})\s*月/u', $s, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12) {
            $mm = (int) $m[1]; $y = $now->year; $cur = $now->month;
            // 今月か、過ぎた2か月以内の月なら、もう働ける（今日）
            for ($k = 0; $k <= 2; $k++) if ($now->copy()->startOfMonth()->subMonthsNoOverflow($k)->month === $mm) return $now->format('Y-m-d');
            return sprintf('%d-%02d-01', $mm > $cur ? $y : $y + 1, $mm);
        }
        return null;
    }

    // 働き方：['remote'|'hybrid'|'onsite', 見つかった言葉]（無ければ null）。「不可」「NG」などの打ち消しは数えない
    private static function workStyle(string $text): ?array
    {
        $ws = mb_convert_kana($text, 'asKV');
        // 後ろに「不可」「NG」「×」「なし」（「は不可」「が不可」も）があれば、数えない
        $ng = '(?!\s*(?:は|が|も)?\s*[（(]?\s*(?:不可|NG|×|なし|無し))';
        foreach ([
            'remote' => '/(?:フルリモート|完全リモート|リモート\s*100\s*%)'.$ng.'/u',
            'hybrid' => '/(?:一部リモート|リモート併用|一部在宅)'.$ng.'/u',
            'onsite' => '/(?:常駐|出社|オンサイト)'.$ng.'/u',
        ] as $style => $re) {
            if (preg_match($re, $ws, $m)) return [$style, $m[0]];
        }
        return null;
    }
}
