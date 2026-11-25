<?php

namespace App\Support;

use App\Services\CaseParser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 案件の必須・尚可の文から、技術マスタの言葉（キーワード）を拾う（v0.14.0。拾い出しの正本）。
// 取り込み・保存・AI の結果の差し替え・既存案件の読み直しは、どれもここを通る。
// マスタの言葉にも業務の項目にも当たらない文は、「エンジニアに確認」にする。
class CaseKeywords
{
    // 文章の中からは拾わない言葉（普通の言葉と区別がつかない短い別名）。行そのものがこの言葉なら、拾う
    const NO_PICK = ['IT', 'ST', 'EM', 'PO', 'カード', 'キャリア', '株式', 'ガス', '電力', '予約', '製造', '運用', '保守', '監視', 'レビュー', 'リーダー', 'リーダ',
        'パッケージ', '公共', '行政', '交通', '広告', '宿泊', '観光', '賃貸', '受託', '基幹', '流通', '折衝', '配送', '倉庫',
        '勤怠', 'セキュリティ', '通信', '教育', '組込', '組込み', '組み込み', '保険', '移行', '人事'];

    // 先に消す言い方（別の言葉に当たってしまうもの）。製造業・プロジェクトマネージャ試験はマスタの語なので、ここには入れない
    const SKIP = ['電気小売', 'PMワークベンチ', '可視化', '営業日'];

    // マスタの言葉の一覧（最初の文字ごと、長い順）。[最初の文字 => [[小文字にした言葉, 正式な名前, 英数字だけか]…]]
    // （[最初の文字 => [[小文字にした言葉, 正式な名前, 英数字だけか, 長さ, 最初の2文字]…]]）
    private static ?array $words = null;
    // 自動で足されて、まだ確認していない語の名前（これは案件の拾い出しに使わない）
    private static array $unreviewed = [];

    public static function forget(): void { self::$words = null; self::$unreviewed = []; }

    private static function words(): array
    {
        if (self::$words !== null) return self::$words;
        self::$words = [];
        self::$unreviewed = [];
        try {
            if (!Schema::hasTable('skill_terms')) return self::$words;
            $all = [];
            foreach (DB::table('skill_terms')->get(['name', 'aliases', 'source', 'reviewed']) as $t) {
                if ($t->source === 'auto' && !$t->reviewed) { self::$unreviewed[$t->name] = true; continue; }
                foreach (array_merge([$t->name], parse_list($t->aliases)) as $w0) {
                    if (in_array($w0, SkillCatalog::NO_DETECT, true) || in_array($w0, SkillCatalogData::NO_DETECT, true) || in_array($w0, self::NO_PICK, true)) continue;
                    $w = mb_strtolower(mb_convert_kana($w0, 'as'));
                    $len = mb_strlen($w);
                    if ($len < 2) continue; // 1字の語（C・R・「版」など）は、文章からは拾わない
                    $all[] = [$w, $t->name, (bool) preg_match('/^[\x20-\x7e]+$/', $w), $len, mb_substr($w, 0, 2)];
                }
            }
            usort($all, fn ($a, $b) => $b[3] <=> $a[3]);
            foreach ($all as $x) self::$words[mb_substr($x[0], 0, 1)][] = $x;
        } catch (\Throwable $e) {
            self::$words = [];
        }
        return self::$words;
    }

    // 確認済みのマスタの語か（自動で足されて未確認の語は、文ごと名前に当たってしまうので、使わない）
    private static function master(string $w): ?array
    {
        self::words();
        $t = SkillCatalog::find($w);
        return $t && !isset(self::$unreviewed[$t[1]]) ? $t : null;
    }

    // 1行（文）から拾う。['skills' => [正式な名前…], 'exp' => [業務の項目名…], 'new' => ?string（足してよい英数字1語）]
    public static function line(string $line): array
    {
        $line = self::clean($line);
        $out = ['skills' => [], 'exp' => [], 'new' => null];
        if ($line === '') return $out;
        // 行そのものがマスタの語なら、その名前だけ（「C」「R」のような1字の語もここで当たる）
        if ($t = self::master($line)) {
            $out['skills'] = [$t[1]];
            return $out;
        }
        $out['skills'] = self::pick($line);
        $out['exp'] = CaseExp::detectLine($line);
        // 何も拾えず、空白の無い英数字1語なら、新しい技術名として足してよい（保存のときに足す）
        if (!$out['skills'] && !$out['exp'] && self::isNewWord($line)) {
            $out['skills'] = [$line];
            $out['new'] = $line;
        }
        return $out;
    }

    // 空白の無い英数字1語（2〜30字。英字を含む）か
    private static function isNewWord(string $s): bool
    {
        return preg_match('/^[A-Za-z0-9.+#_\-]{2,30}$/', $s) === 1 && preg_match('/[A-Za-z]/', $s) === 1;
    }

    // 文章の中から、マスタの言葉を左から最長一致で拾う（見つけた順の正式な名前）
    private static function pick(string $line): array
    {
        return array_values(array_unique(array_column(self::scan($line), 0)));
    }

    // 推薦文の抜き出し用（v0.19.0）：1行から、マスタの言葉を位置つきで拾う。[[正式な名前, 始まり, 終わり]…]（見つけた順）。
    // 渡す行は cleanAll で整えたもの。位置は、それを mb_strtolower(mb_convert_kana(…, 'as')) した文での文字の位置。
    // 行そのものがマスタの語なら、その名前だけ。新しい語は作らない
    public static function pickWithPos(string $line): array
    {
        // 呼ぶ側が cleanAll で整えた行を渡す（ここでは整え直さない。位置がずれないように）
        if ($line === '') return [];
        if (preg_match('/^[^\d()（）年]+$/u', $line) && ($t = self::master($line))) return [[$t[1], 0, mb_strlen(mb_convert_kana($line, 'as'))]];
        return self::scan($line);
    }

    // 文章の中から、マスタの言葉を左から最長一致で拾う。[[正式な名前, 始まり, 終わり]…]
    private static function scan(string $line): array
    {
        $text = mb_strtolower(mb_convert_kana($line, 'as'));
        // 消す言い方は同じ長さの空白にする（位置がずれないように）
        foreach (self::SKIP as $w) {
            $w = mb_strtolower(mb_convert_kana($w, 'as'));
            $text = str_replace($w, str_repeat(' ', mb_strlen($w)), $text);
        }
        $words = self::words();
        $chars = mb_str_split($text);
        $n = count($chars);
        $found = [];
        for ($i = 0; $i < $n; ) {
            $hit = null;
            $two = $chars[$i].($chars[$i + 1] ?? '');
            foreach ($words[$chars[$i]] ?? [] as [$w, $name, $ascii, $len, $w2]) {
                if ($w2 !== $two || $i + $len > $n || implode('', array_slice($chars, $i, $len)) !== $w) continue;
                // 英数字の語は、前後が英数字でないときだけ当てる（detect と同じ条件）
                if ($ascii) {
                    $before = $i > 0 ? $chars[$i - 1] : '';
                    $after = $i + $len < $n ? $chars[$i + $len] : '';
                    if (preg_match('/[A-Za-z0-9#+.]/', $before) || preg_match('/[A-Za-z0-9#+]/', $after)) continue;
                }
                $hit = [$len, $name];
                break; // 長い順に並んでいるので、最初に当たったものが一番長い
            }
            if ($hit) { $found[] = [$hit[1], $i, $i + $hit[0]]; $i += $hit[0]; } else $i++;
        }
        return $found;
    }

    // 必須・尚可の行から、案件の列の形にする
    // $expToConfirm：欄に書かれた項目から作るとき（保存・再抽出）は、業務にだけ当たった項目も「エンジニアに確認」へ回す（業務の欄は画面で選ぶので、捨てると条件が消える）
    public static function split(array $reqLines, array $prefLines, bool $expToConfirm = false): array
    {
        $reqLines = self::cleanAll($reqLines);
        $prefLines = self::cleanAll($prefLines);
        $req = []; $pref = []; $confirm = []; $new = [];
        foreach ([[$reqLines, 'req'], [$prefLines, 'pref']] as [$lines, $kind]) {
            foreach ($lines as $l) {
                $r = self::line($l);
                if (!$r['skills'] && (!$r['exp'] || $expToConfirm)) { $confirm[$l] = true; continue; }
                foreach ($r['skills'] as $s) {
                    if ($kind === 'req') $req[$s] = true; else $pref[$s] = true;
                }
                if ($r['new']) $new[$r['new']] = true;
            }
        }
        foreach (array_keys($req) as $s) unset($pref[$s]); // 必須にある名前は、尚可に重ねない
        $exp = CaseExp::fromLines($reqLines, $prefLines);
        return [
            'required_skills' => array_keys($req), 'preferred_skills' => array_keys($pref),
            'required_exp' => $exp['required'], 'preferred_exp' => $exp['preferred'],
            'confirm' => array_keys($confirm), 'new' => array_keys($new),
            'required_lines' => $reqLines, 'preferred_lines' => $prefLines,
        ];
    }

    // 「、」区切りなどの欄の文字列を項目に分ける（「・」「/」はマスタの名前なら割らない）
    public static function items(?string $s): array
    {
        $out = [];
        foreach (preg_split('/[,、，;；|\n]+/u', (string) $s) as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (SkillCatalog::find($p) || !preg_match('/[・\/／]/u', $p)) { $out[] = $p; continue; }
            // 「・」「/」で割った断片が全部、確認済みのマスタの語のときだけ割る（それ以外は文の一部なので、1項目のまま）
            $parts = array_values(array_filter(array_map('trim', preg_split('/[\/／・]+/u', $p)), fn ($q) => $q !== ''));
            $all = $parts !== [];
            foreach ($parts as $q) if (!self::master($q)) { $all = false; break; }
            if ($all) array_push($out, ...$parts); else $out[] = $p;
        }
        return $out;
    }

    // 前後の空白と、箇条書きの印を外す
    private static function clean(string $l): string
    {
        $l = preg_replace('/^[\s　]+|[\s　]+$/u', '', $l) ?? $l;
        $l = preg_replace(CaseParser::BULLET, '', $l, 1) ?? $l;
        return preg_replace('/^[\s　]+|[\s　]+$/u', '', $l) ?? $l;
    }

    // 空の行を除き、同じ行は1つにする（印も外す）
    public static function cleanAll(array $lines): array
    {
        $out = [];
        foreach ($lines as $l) {
            $l = self::clean((string) $l);
            if ($l !== '' && !in_array($l, $out, true)) $out[] = $l;
        }
        return $out;
    }
}
