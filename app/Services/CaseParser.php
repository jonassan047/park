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

    // 箇条書きの印（・ - * ● ○ ①〜⑳ と、(1)・（1）・1) の番号）。「４．単価」のような数字＋「．」は印にしない
    const BULLET = '/^(?:[・\-\*●○◯〇－]|[①-⑳]|[\(（]?\d+[\)）])\s*/u';
    // 必須・尚可を表す言葉（「必　須」のように間に空白があってもよい）
    const KIND = '必\s*須|尚\s*可|歓\s*迎|推\s*奨|MUST|WANT|BETTER';
    // 見出しの前の飾り
    const DECOR = '(?:<<|<|■|□|【|▼|\[|⇒|◆|◇|\()';

    private static function clean(string $l): string
    {
        return trim(preg_replace('/^[\s・●■◆◇□○\-\*＊【\[]+/u', '', $l));
    }

    // 前後の空白を消す（全角空白でも文字が壊れないよう trim() は使わない）
    private static function t(string $s): string
    {
        return preg_replace('/^[\s　]+|[\s　]+$/u', '', $s) ?? $s;
    }

    // 行頭の空白の数（子項目の深さを見る）
    private static function indentOf(string $raw): int
    {
        preg_match('/^[ \t　]*/u', $raw, $m);
        return mb_strlen($m[0]);
    }

    // 必須・尚可の見出しか調べる。見出しなら ['kind', 'body'（同じ行の本文）, 'bulleted']、違えば null
    private static function headingKind(string $l): ?array
    {
        if (preg_match(self::BULLET, $l)) return null; // 印で始まる行は見出しにしない
        $h = mb_convert_kana($l, 'as'); // 見出しの判定だけ半角にそろえる（1文字ずつの置き換えなので、位置は元の文と同じ）
        // 本体の無い見出し（◆スキル：・■スキル■・【スキル】・スキル要件：）は必須
        if (preg_match('/^(?<pre>'.self::DECOR.'*)\s*(?:スキル要件|スキル)\s*[\)\]】>■◆]*\s*(?<colon>[:：]?)\s*$/u', $h, $g) && ($g['pre'] !== '' || $g['colon'] !== '')) {
            return ['kind' => 'required', 'body' => '', 'bulleted' => false];
        }
        $re = '/^(?<pre>'.self::DECOR.'*)\s*(?:スキル要件|スキル)?\s*[\(\[]?\s*(?<kind>'.self::KIND.')\s*(?:スキル|要件|条件)?\s*(?:[・\/／]\s*経験)?(?<post>.*)$/iu';
        if (!preg_match($re, $h, $m, PREG_OFFSET_CAPTURE)) return null;
        $post = $m['post'][0];
        preg_match('/^(?<decor>[\)\]】>〕■◆□◇▼\s]*)(?<colon>[:：]?)\s*(?<body>.*)$/us', $post, $d, PREG_OFFSET_CAPTURE);
        $hasDecor = preg_replace('/\s+/u', '', $d['decor'][0]) !== '';
        $off = mb_strlen(substr($h, 0, $m['post'][1])) + mb_strlen(substr($post, 0, $d['body'][1]));
        $body = self::t(mb_substr($l, $off));
        // 「必須経験のある方は…」のような、ふつうの文の書き出しは見出しにしない
        if ($m['pre'][0] === '' && !$hasDecor && $d['colon'][0] === '' && $body !== '') return null;
        $bulleted = false;
        if (preg_match(self::BULLET, $body)) {
            $bulleted = true;
            $body = self::t(preg_replace(self::BULLET, '', $body, 1));
        } elseif (preg_match('/^[\(（※]/u', $body)) {
            $body = ''; // 「（いずれか）」「※…」のような注意書きは項目にしない
        }
        $k = strtoupper(preg_replace('/\s+/u', '', $m['kind'][0]));
        return ['kind' => in_array($k, ['必須', 'MUST'], true) ? 'required' : 'preferred', 'body' => $body, 'bulleted' => $bulleted];
    }

    // 「【概要】」のように、必須・尚可以外の見出しか（見出しのすぐ下の小見出しと見分ける）
    private static function isOtherLabel(string $inner): bool
    {
        $s = mb_convert_kana($inner, 'as');
        foreach (self::LABELS as $field => $labels) {
            if ($field === 'required_skills' || $field === 'preferred_skills') continue;
            foreach ($labels as $lb) if (str_starts_with($s, $lb)) return true;
        }
        return false;
    }

    // 次の見出しや項目名（単価： など）が来て、節が終わるか
    private static function endsSection(string $l, string $h, bool $marked): bool
    {
        if (preg_match('/^(?:■|【|◆|<<|▼)/u', $h)) return true;
        if (preg_match('/^\d+\.(?!\d)/', $h)) return true; // 「4．」のような番号の見出し
        if (!$marked && preg_match('/^[^\s:]{1,8}\s*:/u', $h)) return true; // 8字以内の項目名＋コロン
        return false;
    }

    // 本文から、必須・尚可の項目を読む（規則。手元の AI も使わず、すぐ返す）
    public static function sections(string $text): array
    {
        $out = ['required' => [], 'preferred' => []];
        $cur = null;       // いま読んでいる節（required／preferred）
        $n = 0;            // その節で読んだ項目の数
        $bulleted = false; // 箇条書きの節か
        $base = null;      // 最初の箇条書きの深さ
        $gap = false;      // 直前に空行があったか
        foreach (explode("\n", str_replace("\r", '', $text)) as $raw) {
            $l = self::t($raw);
            if ($l === '') { $gap = true; continue; }
            if (preg_match('/^[\*＊=＝━─\-－_＿]{3,}/u', $l)) { $cur = null; continue; } // 区切り線
            $hd = self::headingKind($l);
            if ($hd) {
                $cur = $hd['kind']; $n = 0; $gap = false; $bulleted = $hd['bulleted']; $base = null;
                if ($hd['body'] !== '') { $out[$cur][] = $hd['body']; $n = 1; if ($bulleted) $base = self::indentOf($raw); }
                continue;
            }
            if ($cur === null) continue;
            $h = mb_convert_kana($l, 'as');
            $indent = self::indentOf($raw);
            $marked = preg_match(self::BULLET, $l) === 1;
            // 見出しのすぐ下の小見出し（【テクニカルスキル】）は読み飛ばす
            if ($n === 0 && preg_match('/^【([^】]{1,15})】$/u', $l, $s)) {
                if (self::isOtherLabel($s[1])) $cur = null;
                continue;
            }
            // 深く下げた「-」の子項目と、「※」の補足は、前の項目に付ける
            if ($n > 0 && $base !== null && $indent > $base && preg_match('/^-\s*(.*)$/u', $l, $c)) {
                $out[$cur][array_key_last($out[$cur])] .= ' '.self::t($c[1]); $gap = false; continue;
            }
            if (str_starts_with($l, '※')) {
                if ($n > 0) $out[$cur][array_key_last($out[$cur])] .= ' '.$l;
                $gap = false; continue;
            }
            if ($gap && $n > 0 && !$marked) { $cur = null; $gap = false; continue; } // 空行のあとの印の無い行は、節の外
            if (self::endsSection($l, $h, $marked)) { $cur = null; continue; }
            $gap = false;
            $txt = self::t(preg_replace(self::BULLET, '', $l, 1));
            if ($txt === '') continue;
            if ($marked) { $bulleted = true; $base ??= $indent; $out[$cur][] = $txt; $n++; }
            elseif ($bulleted && $n > 0) $out[$cur][array_key_last($out[$cur])] .= ' '.$txt; // 前の項目の続き
            else { $out[$cur][] = $txt; $n++; }
        }
        // 必須の節の中で「（尚可）」「（歓迎）」と書いてある行は、尚可へ移す
        $req = [];
        foreach ($out['required'] as $it) {
            if (preg_match('/[\(（]\s*(?:尚可|歓迎)\s*[\)）]/u', $it)) {
                $it = self::t(preg_replace('/[\(（]\s*(?:尚可|歓迎)\s*[\)）]/u', '', $it));
                if ($it !== '') $out['preferred'][] = $it;
            } else $req[] = $it;
        }
        $out['required'] = $req;
        return $out;
    }

    // 必須・尚可の行から、業務の拾い出しと本文の技術の追加をして、案件票の項目にそろえる（規則も AI の結果も同じ手順）
    public static function finishSkills(array $req, array $pref, string $text): array
    {
        // 必須・尚可の各行から、業務経験・業務領域も拾う（技術の欄は変えない）
        $exp = \App\Support\CaseExp::fromLines($req, $pref);
        $reqS = implode('、', array_filter($req));
        $prefS = implode('、', array_filter($pref));
        // 本文全体から、技術マスタにある技術を探す。必須に無いものは「尚可」の候補にする（管理者が登録の前に確かめる）
        $detected = \App\Support\SkillCatalog::detect($text);
        $reqN = array_map('norm_skill', parse_list($reqS));
        $extra = array_values(array_filter($detected, fn ($t) => !in_array(norm_skill($t), $reqN, true)));
        if (empty($reqS) && $detected) $reqS = implode('、', $detected);
        elseif ($extra) {
            $list = parse_list($prefS);
            $listN = array_map('norm_skill', $list);
            foreach ($extra as $t) if (!in_array(norm_skill($t), $listN, true)) $list[] = $t;
            $prefS = implode('、', $list);
        }
        return [
            'required_skills' => $reqS,
            'preferred_skills' => $prefS,
            'required_exp' => \App\Support\CaseExp::join($exp['required']),
            'preferred_exp' => \App\Support\CaseExp::join($exp['preferred']),
            'detected' => $detected,
        ];
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
        // 必須・尚可は新しい読み方（sections）で読む。どちらも空なら、今までの読み方の結果を使う（読めていたメールを落とさない）
        $sec = self::sections((string) $text);
        if (!$sec['required'] && !$sec['preferred']) {
            $sec = ['required' => $buckets['required_skills'] ?? [], 'preferred' => $buckets['preferred_skills'] ?? []];
        }
        $fin = self::finishSkills($sec['required'], $sec['preferred'], (string) $text);
        $out = $fin + $out; // 必須・尚可・業務・本文の技術は finishSkills の結果を使う

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
        return $out;
    }
}
