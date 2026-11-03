<?php

namespace App\Services;

use App\Support\SkillCatalog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

// スキル表（engineer_skills）。保存・同じ技術のまとめ・表示用の月数と注意をここに集める。
class SkillTable
{
    // レベルの文（画面とマニュアルの文の正本）
    const LEVELS = [
        1 => '学んだが業務では未経験',
        2 => '指示を受けて作れる',
        3 => '一人で作れる',
        4 => '設計・レビューができる',
        5 => '人に教え、技術を選べる',
    ];
    const MAX_PRIMARY = 5;     // 得意の印は5つまで
    const NAME_MAX = 40;       // 技術の名前の長さ
    const NOTE_MAX = 200;      // ひとことの長さ
    const WARN_GAP_MONTHS = 12; // 申告の年数が経歴の月数より、これ以上多ければ注意を出す

    // 同じ技術の行を1つにする。years・level・last_used_ym は大きいほう、is_primary はどれかが付いていれば付ける、note は先の行の空でないもの
    public static function mergeRows(iterable $rows): object
    {
        $out = null;
        foreach ($rows as $r) {
            $r = (object) (array) $r;
            if ($out === null) {
                $out = clone $r;
                $out->is_primary = (bool) ($r->is_primary ?? false);
                continue;
            }
            $ya = $out->years ?? null; $yb = $r->years ?? null;
            if ($yb !== null && ($ya === null || (float) $yb > (float) $ya)) $out->years = $yb;
            $out->level = max((int) ($out->level ?? 3), (int) ($r->level ?? 3));
            $la = $out->last_used_ym ?? null; $lb = $r->last_used_ym ?? null;
            if ($lb !== null && $lb !== '' && ($la === null || $la === '' || strcmp($lb, $la) > 0)) $out->last_used_ym = $lb;
            if (!empty($r->is_primary)) $out->is_primary = true;
            if (($out->note ?? '') === '' && ($r->note ?? '') !== '') $out->note = $r->note;
        }
        return $out;
    }

    // 表示用に同じ技術を1つにする（term_id があればそれで、無ければ名前で。DB は触らない）
    public static function dedupe($rows): Collection
    {
        $groups = [];
        foreach ($rows as $r) {
            $key = !empty($r->term_id) ? 't'.$r->term_id : 'n:'.norm_skill($r->name);
            $groups[$key][] = $r;
        }
        return collect(array_map(fn ($g) => count($g) > 1 ? self::mergeRows($g) : $g[0], array_values($groups)));
    }

    // 画面に出すスキルの行：同じ技術を1つにし、経歴から数えた月数・件数・最後の年月と、注意（warn）を付ける
    public static function forDisplay(int $engineerId): Collection
    {
        $rows = DB::table('engineer_skills')->where('engineer_id', $engineerId)->orderBy('category')->orderByDesc('years')->orderBy('id')->get();
        // 技術マスタの紐づけが空の行は、名前で引いて付ける（DB は書き換えない）
        foreach ($rows as $r) {
            if (empty($r->term_id) && ($t = SkillCatalog::find($r->name))) $r->term_id = $t[0];
        }
        $stats = ProjectSkills::stats([$engineerId])[$engineerId] ?? [];
        return self::dedupe($rows)->map(function ($r) use ($stats) {
            $s = $stats[(int) ($r->term_id ?? 0)] ?? null;
            $r->months = $s['months'] ?? 0;
            $r->projects = $s['projects'] ?? 0;
            $r->last_ym = $s['last'] ?? null;
            $r->warn = $r->projects >= 1 && $r->years !== null && (float) $r->years * 12 - $r->months >= self::WARN_GAP_MONTHS;
            return $r;
        })->values();
    }

    // 保存する。戻り値は ['ok' => true, 'names' => [正式な名前…]] か ['bad' => '理由']。
    // 検査は DB に書く前に全部行い、1つでも当たれば何も保存しない
    public static function save(int $engineerId, array $d): array
    {
        $names = (array) ($d['skill_name'] ?? []); $cats = (array) ($d['skill_cat'] ?? []);
        $years = (array) ($d['skill_years'] ?? []); $levels = (array) ($d['skill_level'] ?? []);
        $lasts = (array) ($d['skill_last'] ?? []); $primaries = (array) ($d['skill_primary'] ?? []); $notes = (array) ($d['skill_note'] ?? []);
        $thisMonth = now()->format('Y-m');
        $validCats = array_keys(PARK_LABELS['skillCat']);

        // 1. 検査
        $rows = [];
        foreach ($names as $i => $n) {
            $n = trim((string) $n);
            if ($n === '') continue;
            if (mb_strlen($n) > self::NAME_MAX) return ['bad' => '技術の名前は'.self::NAME_MAX.'文字までにしてください（'.mb_substr($n, 0, 10).'…）。'];
            $lv = $levels[$i] ?? '';
            if ($lv === '' || $lv === null) $lv = 3;
            elseif (!is_numeric($lv) || (int) $lv != $lv || !isset(self::LEVELS[(int) $lv])) return ['bad' => "「{$n}」のレベルは 1〜5 で選んでください。"];
            $y = $years[$i] ?? '';
            if ($y === '' || $y === null) $y = null;
            elseif (!is_numeric($y) || (float) $y < 0 || (float) $y >= 100) return ['bad' => "「{$n}」の年数は 0 以上 100 未満の数で入れてください。"];
            $last = trim((string) ($lasts[$i] ?? ''));
            if ($last === '') $last = null;
            elseif (!ym_valid($last)) return ['bad' => "「{$n}」の最後に使った年月は 2024-04 の形で入れてください。"];
            elseif ($last > $thisMonth) return ['bad' => "「{$n}」の最後に使った年月は、今月より先にはできません。"];
            $note = trim((string) ($notes[$i] ?? ''));
            if ($note === '') $note = null;
            elseif (mb_strlen($note) > self::NOTE_MAX) return ['bad' => "「{$n}」のひとことは".self::NOTE_MAX.'文字までにしてください。'];
            $cat = (string) ($cats[$i] ?? 'other');
            $rows[] = (object) ['name' => $n, 'category' => in_array($cat, $validCats, true) ? $cat : 'other', 'years' => $y === null ? null : (float) $y, 'level' => (int) $lv,
                'last_used_ym' => $last, 'is_primary' => (string) ($primaries[$i] ?? '0') === '1', 'note' => $note];
        }

        // 2〜6. transaction の中で保存する（途中で断ったら、足したマスタの言葉も含めて全部戻す）
        try {
            $saved = DB::transaction(fn () => self::write($engineerId, $rows));
        } catch (\DomainException $e) { // write() が、断る理由の文を持って投げる
            SkillCatalog::forget();
            return ['bad' => $e->getMessage()];
        }
        return ['ok' => true, 'names' => $saved];
    }

    private static function write(int $engineerId, array $rows): array
    {
        // 技術マスタの言葉にそろえる（無ければ「確認待ち」で足す）
        foreach ($rows as $r) {
            $t = SkillCatalog::find($r->name);
            if (!$t) {
                SkillCatalog::ensure($r->name, $r->category);
                $t = SkillCatalog::find($r->name);
            }
            if (!$t) throw new \DomainException("「{$r->name}」を技術マスタに足せませんでした。");
            $r->term_id = (int) $t[0]; $r->name = $t[1]; $r->category = $t[2];
        }

        // 同じ技術の行を1つにする
        $groups = [];
        foreach ($rows as $r) $groups[$r->term_id][] = $r;
        $merged = array_map(fn ($g) => count($g) > 1 ? self::mergeRows($g) : $g[0], $groups);

        if (count(array_filter($merged, fn ($r) => $r->is_primary)) > self::MAX_PRIMARY) {
            throw new \DomainException('得意の印は'.self::MAX_PRIMARY.'つまでです。');
        }

        // 今ある行と突き合わせる（全部消して入れ直さない。残した行の id・登録日は変わらない）
        $existing = DB::table('engineer_skills')->where('engineer_id', $engineerId)->orderBy('id')->get();
        $now = now_str();
        $keepIds = [];
        foreach ($merged as $termId => $r) {
            $same = $existing->filter(fn ($x) => (int) $x->term_id === $termId)->values();
            if ($same->isEmpty()) {
                $same = $existing->filter(fn ($x) => empty($x->term_id) && ($f = SkillCatalog::find($x->name)) && (int) $f[0] === $termId)->values();
            }
            $values = ['term_id' => $termId, 'name' => $r->name, 'category' => $r->category, 'years' => $r->years, 'level' => $r->level,
                'last_used_ym' => $r->last_used_ym, 'is_primary' => $r->is_primary, 'note' => $r->note];
            if ($same->isEmpty()) {
                DB::table('engineer_skills')->insert($values + ['engineer_id' => $engineerId, 'created_at' => $now, 'updated_at' => $now]);
                continue;
            }
            $keep = $same->first();
            $keepIds[] = $keep->id;
            if (self::changed($keep, $values)) DB::table('engineer_skills')->where('id', $keep->id)->update($values + ['updated_at' => $now]);
            // 同じ技術の残りの行（重複）は、保存のときに消して1つにする
        }
        // 残した行以外（重複と、画面で消した行）を消す。入れ直した行は新しい id なので、元からあった行だけが対象
        $drop = $existing->pluck('id')->diff($keepIds)->values()->all();
        if ($drop) DB::table('engineer_skills')->whereIn('id', $drop)->delete();

        DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => $now]);
        return array_values(array_map(fn ($r) => $r->name, $merged));
    }

    // 今の行と値が違うか（年数は数で比べる）
    private static function changed(object $row, array $values): bool
    {
        foreach ($values as $k => $v) {
            $cur = $row->$k ?? null;
            if ($k === 'years') { if (($cur === null) !== ($v === null) || ($v !== null && (float) $cur !== (float) $v)) return true; continue; }
            if ($k === 'is_primary') { if ((bool) $cur !== (bool) $v) return true; continue; }
            if ($k === 'term_id' || $k === 'level') { if ((int) $cur !== (int) $v) return true; continue; }
            if (($cur ?? null) !== $v) return true;
        }
        return false;
    }
}
