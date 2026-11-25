<?php

namespace App\Services;

// 紹介メールから読んだ値（EngineerMailParser の結果）と、エンジニアの今の値を比べて、編集画面に入れる値を決める（v0.18.0）。
// ここでは何も保存しない。保存は、画面の［保存する］（EngineerController::update）だけ
class EngineerMailImport
{
    // 結果の表に出す順（列 => 項目名）
    const LABELS = [
        'birth_year' => '生まれた年',
        'gender' => '性別',
        'nearest_line' => '最寄りの路線',
        'nearest_station' => '最寄り駅',
        'walk_minutes' => '徒歩（分）',
        'career_summary' => '職務概要',
        'years_exp' => 'IT の経験年数',
        'desired_roles' => '希望業務',
        'english_level' => '語学',
        'desired_price' => '希望単価（万円／月）',
        'min_price' => '下限の単価（万円／月）',
        'available_from' => '稼働できる日',
        'appeal' => 'アピール',
        'work_style' => '希望の働き方',
    ];

    // どんなときも、編集画面に入れない列（氏名・メール・所属・状態・イニシャル）
    const NEVER = ['name', 'name_sei', 'name_mei', 'kana', 'kana_sei', 'kana_mei', 'email', 'ses_id', 'status', 'initials'];

    // 詳しいプロフィールの列（編集画面にふだんは無い。取り込みのあとだけ入力欄を出す）
    const PROFILE_COLS = ['gender', 'nearest_line', 'walk_minutes', 'career_summary', 'desired_roles', 'english_level', 'appeal'];

    const MAX_NOTES = 2000;

    private static function isEmpty(string $col, $v): bool
    {
        if ($v === null || $v === '') return true;
        return $col === 'work_style' && $v === 'any';
    }

    private static function same(string $col, $a, $b): bool
    {
        if (in_array($col, ['years_exp', 'desired_price', 'min_price', 'birth_year', 'walk_minutes'], true)) return (float) $a === (float) $b;
        return trim((string) $a) === trim((string) $b);
    }

    // イニシャルが同じか（大文字小文字は問わない。「T.N」と「N.T」の逆順も同じとみなす）
    private static function sameInitials(string $a, string $b): bool
    {
        $a = strtoupper($a); $b = strtoupper($b);
        if ($a === $b) return true;
        return explode('.', $a) === array_reverse(explode('.', $b));
    }

    private static function short(string $s): string
    {
        $s = preg_replace('/\s+/u', ' ', $s) ?? $s;
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 60).'…' : $s;
    }

    public static function plan(object $e, array $parsed, bool $overwrite): array
    {
        $plan = ['fill' => [], 'rows' => [], 'skills' => [], 'warn' => [], 'notes_added' => [],
            'unread' => $parsed['unread'] ?? [], 'unknown_words' => $parsed['unknown_words'] ?? [], 'ref' => $parsed['ref'] ?? [], 'read' => []];
        if (!empty($parsed['multiple'])) {
            $plan['warn'][] = '1通に複数の候補者が入っています。1人分だけを貼ってください。';
            return $plan;
        }
        $f = $parsed['fields'] ?? [];
        $raw = $parsed['raw'] ?? [];

        // 氏名：書き換えない。登録と違うときだけ注意（イニシャルだけのメールは、イニシャルで比べる）
        $squash = fn (string $s) => preg_replace('/[\s　]+/u', '', $s) ?? $s;
        if (isset($f['name'])) {
            if ($squash((string) ($e->name ?? '')) !== $squash((string) $f['name'])) $plan['warn'][] = 'メールの氏名が登録の氏名と違います。別の方のメールではないか確かめてください。';
        } elseif (isset($f['initials']) && ($e->initials ?? '') !== '' && !self::sameInitials((string) $e->initials, $f['initials'])) {
            $plan['warn'][] = 'メールのイニシャルが登録のイニシャルと違います。別の方のメールではないか確かめてください。';
        }
        if (isset($f['name'])) $plan['read'][] = 'name';
        if (isset($f['initials'])) {
            $plan['read'][] = 'initials';
            $reg = (string) ($e->initials ?? '');
            if (isset($f['name']) && $reg !== '' && !self::sameInitials($reg, $f['initials'])) {
                $plan['rows'][] = ['label' => 'イニシャル', 'mail' => (string) ($raw['initials'] ?? $f['initials']), 'status' => "登録は {$reg}、メールは {$f['initials']}（変えません）", 'note' => null];
            }
        }

        foreach (self::LABELS as $col => $label) {
            if (!array_key_exists($col, $f)) continue;
            $plan['read'][] = $col;
            $val = $f[$col];
            $mail = self::short((string) ($raw[$col] ?? $val));
            $note = null;
            if ($col === 'birth_year') {
                if (!empty($e->birth_date)) {
                    $plan['rows'][] = ['label' => $label, 'mail' => $mail, 'status' => '生年月日が登録済みのため使いません', 'note' => null];
                    continue;
                }
                $note = '年齢から計算（誕生日により1年ずれます）';
            }
            if ($col === 'work_style') $mail = (string) (PARK_LABELS['workStyle'][$val] ?? $val).'（メールの言葉：'.$mail.'）';
            if (in_array($col, ['desired_roles', 'english_level'], true) && mb_strlen((string) ($raw[$col] ?? '')) > mb_strlen((string) $val)) $note = '長いので切りました';
            $cur = $e->$col ?? null;
            if (!self::isEmpty($col, $cur) && self::same($col, $cur, $val)) {
                $status = '同じ値です';
            } elseif (self::isEmpty($col, $cur)) {
                $plan['fill'][$col] = $val;
                $status = '入れました';
            } elseif ($overwrite) {
                $plan['fill'][$col] = $val;
                $status = '上書きします（保存するまで変わりません）';
            } else {
                $status = '今の値のまま（メールでは '.$mail.'）';
            }
            $plan['rows'][] = ['label' => $label, 'mail' => $mail, 'status' => $status, 'note' => $note];
        }

        // 特記事項：今の特記事項の後ろに足すだけ（上書きのチェックでも消さない。同じ行は足さない）
        $notes = $parsed['notes'] ?? [];
        if ($notes) {
            $plan['read'][] = 'special_notes';
            $curNotes = (string) ($e->special_notes ?? '');
            $have = array_map($squash, preg_split('/\R/u', $curNotes) ?: []);
            $text = $curNotes;
            $skipped = false;
            foreach ($notes as $line) {
                if (in_array($squash($line), $have, true)) continue;
                $next = $text === '' ? $line : $text."\n".$line;
                if (mb_strlen($next) > self::MAX_NOTES) { $skipped = true; continue; }
                $text = $next;
                $have[] = $squash($line);
                $plan['notes_added'][] = $line;
            }
            if ($plan['notes_added']) $plan['fill']['special_notes'] = $text;
            if ($skipped) $plan['warn'][] = '特記事項が '.self::MAX_NOTES.' 字を超えるため、一部の行は足していません。';
            $plan['rows'][] = ['label' => '特記事項', 'mail' => self::short(implode(' ／ ', $notes)),
                'status' => $plan['notes_added'] ? '足しました（今の特記事項の後ろ）' : '同じ行がすでにあるため、足しません', 'note' => null];
        }

        // スキルの候補：登録済みのものは、チェック欄を出さない
        if (!empty($parsed['skills'])) {
            $plan['read'][] = 'skills';
            $have = SkillChecks::indexMany([(int) $e->id])[(int) $e->id] ?? [];
            foreach ($parsed['skills'] as $s) {
                $plan['skills'][] = ['name' => $s['name'], 'category' => $s['category'], 'registered' => isset($have[norm_skill($s['name'])])];
            }
        }

        // 決して入れない列は、overwrite でも入れない（念のため）
        foreach (self::NEVER as $c) unset($plan['fill'][$c]);
        return $plan;
    }
}
