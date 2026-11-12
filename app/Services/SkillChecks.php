<?php

namespace App\Services;

use App\Support\CaseExp;
use App\Support\SkillCatalog;
use App\Support\SkillForm;
use Illuminate\Support\Facades\DB;

// スキルのチェック式の表（engineer_skill_checks）。保存・表示用の文・マッチング用の索引・案件の印をここに集める。
// 項目は skill_form.json（SkillForm）。表には「節／親／名前」で持つので、JSON の並びが変わってもデータは崩れない。
// 古い表（engineer_skills）は、移行（migrateFromOld）のほかでは読まない・書かない。
class SkillChecks
{
    const TABLE = 'engineer_skill_checks';

    // 節 → 技術マスタの分類（「その他」の名前を確認待ちで足すときに使う）。言語の節は親が lang、子とその他は fw
    const CATEGORY = ['roles' => 'role', 'phases' => 'method', 'db' => 'db', 'os' => 'os', 'pm' => 'tool', 'dev' => 'tool'];

    private static function arr($v): array { return is_array($v) ? $v : []; }

    private static function years(?object $r): ?int { return $r === null || $r->years_min === null ? null : (int) $r->years_min; }

    // 表に入れる値の鍵（同じ道筋・同じ名前）
    private static function key(string $section, string $parent, string $item, string $other): string
    {
        return $section."\0".$parent."\0".$item."\0".$other;
    }

    // 「その他」の名前を比べる鍵（大文字小文字・空白・全角半角の違いは同じとみなす）
    private static function nameKey(string $name): string
    {
        $k = norm_skill($name);
        return $k !== '' ? $k : mb_strtolower($name);
    }

    // 大きいほうの年数（null が一番小さい）
    private static function maxYears(?int $a, ?int $b): ?int
    {
        return SkillForm::rank($b) > SkillForm::rank($a) ? $b : $a;
    }

    // 行の表示名（その他は書いた名前）
    private static function rowName(object $r, ?array $n): string
    {
        $isOther = $n ? $n['other'] : ($r->other_text !== '');
        return $isOther && $r->other_text !== '' ? $r->other_text : $r->item;
    }

    // 行の照合用の言葉（決まった項目は SkillForm::words、その他は書いた名前1つ）
    private static function rowWords(object $r, ?array $n): array
    {
        return $n && !$n['other'] ? $n['words'] : [self::rowName($r, $n)];
    }

    // ---------- 保存 ----------

    // 画面からの保存。入力：chk[id]・yr[id]・oth[id][]・othyr[id][]・keep[]（今の表に無い項目を残す行の id）。
    // 戻り値は ['ok' => true, 'count' => 行数, 'names' => [名前…], 'skipped' => 名前が空で保存しなかった「その他」の数] か ['bad' => '理由']。
    // 検査は DB に書く前に全部行い、1つでも当たれば何も保存しない
    public static function save(int $engineerId, array $in): array
    {
        $chk = self::arr($in['chk'] ?? []); $yr = self::arr($in['yr'] ?? []);
        $oth = self::arr($in['oth'] ?? []); $othyr = self::arr($in['othyr'] ?? []);
        $keep = array_map('intval', array_filter(self::arr($in['keep'] ?? []), 'is_scalar'));
        $badYear = '年数は選択肢から選んでください。';
        $badOne = '選択肢から選んでください。';

        // 1. 年数の検査（知らない項目の分も見る。空は「年数なし」）
        // 空の文字は、Laravel が null にして届ける（ConvertEmptyStringsToNull）。null も「年数なし」として通す
        foreach ($yr as $v) {
            if ($v !== null && (!is_string($v) || ($v !== '' && SkillForm::value($v) === null))) return ['bad' => $badYear];
        }
        foreach ($othyr as $list) {
            foreach (self::arr($list) as $v) {
                if ($v !== null && (!is_string($v) || ($v !== '' && SkillForm::value($v) === null))) return ['bad' => $badYear];
            }
        }

        // 1b. 1つ選ぶ設問（one[グループの id]=選択肢の id）の検査。知らない設問は無視。空は「未回答」
        $oneRaw = $in['one'] ?? [];
        if ($oneRaw !== null && $oneRaw !== '' && !is_array($oneRaw)) return ['bad' => $badOne];
        $oneRaw = is_array($oneRaw) ? $oneRaw : [];
        $one = [];
        foreach (SkillForm::singleGroups() as $gid => $cids) {
            $v = $oneRaw[$gid] ?? null;
            if ($v === null || $v === '') continue;
            if (!is_string($v) || !in_array($v, $cids, true)) return ['bad' => $badOne];
            $one[$gid] = $v;
        }

        // 2. 行を作る（チェックできる項目だけ。知らない id は無視する）
        $rows = []; $skipped = 0;
        foreach (SkillForm::nodes() as $n) {
            if (!$n['checkable'] || self::isSingleSection($n['section'])) continue; // 1つ選ぶ節は、下で one から作る
            $id = $n['id'];
            if ($n['other']) {
                if (empty($chk[$id])) continue;
                $names = array_values(self::arr($oth[$id] ?? []));
                $yrs = array_values(self::arr($othyr[$id] ?? []));
                $found = 0;
                foreach ($names as $i => $raw) {
                    $name = is_string($raw) ? clean_name($raw) : null;
                    if ($name === null) continue;
                    if (mb_strlen($name) > SkillForm::OTHER_MAX) return ['bad' => 'その他の名前は'.SkillForm::OTHER_MAX.'文字までにしてください（'.mb_substr($name, 0, 10).'…）。'];
                    if (++$found > SkillForm::OTHER_COUNT_MAX) return ['bad' => '1つの「その他」に入れられるのは'.SkillForm::OTHER_COUNT_MAX.'個までです。'];
                    $y = $n['years'] && is_string($yrs[$i] ?? null) ? SkillForm::value($yrs[$i]) : null;
                    if ($n['years'] && $y === null) return ['bad' => '年数を選んでください（その他：'.$name.'）。'];
                    $k = self::key($n['section'], $n['parent'], $n['item'], self::nameKey($name));
                    if (isset($rows[$k])) { $rows[$k]['years_min'] = self::maxYears($rows[$k]['years_min'], $y); continue; }
                    $rows[$k] = ['section' => $n['section'], 'parent' => $n['parent'], 'item' => $n['item'], 'other_text' => $name, 'years_min' => $y, 'node' => $n];
                }
                if ($found === 0) $skipped++;
                continue;
            }
            $label = $n['years'] ? (string) ($yr[$id] ?? '') : '';
            // 年数だけ選んでチェックが無いときも、チェックありとして保存する（JavaScript が動かないときの守り）
            if (empty($chk[$id]) && $label === '') continue;
            if ($n['years'] && $label === '') return ['bad' => '年数を選んでください（'.($n['parent'] !== '' ? $n['parent'].'／' : '').$n['item'].'）。'];
            $rows[self::key($n['section'], $n['parent'], $n['item'], '')] = ['section' => $n['section'], 'parent' => $n['parent'], 'item' => $n['item'], 'other_text' => '',
                'years_min' => $label === '' ? null : SkillForm::value($label), 'node' => $n];
        }
        // 子の行があるのに親の行が無ければ、親の行（年数なし）を補う。親の行がすでにあれば、その年数のまま
        foreach ($rows as $r) {
            $p = self::parentNode($r['section'], $r['parent']);
            if ($p === null) continue;
            $pk = self::key($p['section'], '', $p['item'], '');
            if (!isset($rows[$pk])) $rows[$pk] = ['section' => $p['section'], 'parent' => '', 'item' => $p['item'], 'other_text' => '', 'years_min' => null, 'node' => $p];
        }
        // 1つ選ぶ設問の行。出す条件（PM・PL にチェック）を満たさないときは、黙って捨てる（前の回答の行も、入れ替えで消える）
        foreach ($one as $cid) {
            $n = SkillForm::node($cid);
            if ($n['none']) continue; // 「選択しない」は未回答と同じ
            $cond = SkillForm::showIfIds($n['section']);
            if ($cond && !array_filter($cond, fn ($c) => !empty($chk[$c]) || (is_string($yr[$c] ?? null) && $yr[$c] !== ''))) continue;
            $rows[self::key($n['section'], $n['parent'], $n['item'], '')] = ['section' => $n['section'], 'parent' => $n['parent'], 'item' => $n['item'], 'other_text' => '',
                'years_min' => null, 'node' => $n];
        }
        $rows = array_values($rows);

        // 3. transaction の中で、今の JSON にある項目の行を入れ替える。JSON に無い項目の行は、keep にあれば残す
        try {
            DB::transaction(function () use ($engineerId, $rows, $keep) {
                // 同じ人の保存が同時に来ても、順番に処理する（行の入れ替えが重ならないように。sqlite では何も起きない）
                DB::table('engineers')->where('id', $engineerId)->lockForUpdate()->first();
                $now = now_str();
                $drop = [];
                foreach (DB::table(self::TABLE)->where('engineer_id', $engineerId)->get(['id', 'section', 'parent', 'item']) as $x) {
                    // 今の表でチェックできる項目の行は入れ替える。表に無い・見出しだけに変わった項目の行は、keep にあれば残す
                    if (self::isLive($x) || !in_array((int) $x->id, $keep, true)) $drop[] = $x->id;
                }
                foreach (array_chunk($drop, 500) as $c) DB::table(self::TABLE)->whereIn('id', $c)->delete();
                $ins = array_map(fn ($r) => ['engineer_id' => $engineerId, 'section' => $r['section'], 'parent' => $r['parent'], 'item' => $r['item'],
                    'other_text' => $r['other_text'], 'years_min' => $r['years_min'], 'source' => 'form', 'created_at' => $now, 'updated_at' => $now], $rows);
                foreach (array_chunk($ins, 50) as $c) DB::table(self::TABLE)->insert($c);
                // 「その他」の名前は、技術マスタに「確認待ち」で足す（決まった項目の名前は足さない）
                foreach ($rows as $r) {
                    if ($r['other_text'] === '') continue;
                    $cat = $r['section'] === 'lang' ? ($r['node']['parent'] === '' ? 'lang' : 'fw') : (self::CATEGORY[$r['section']] ?? 'other');
                    SkillCatalog::ensure($r['other_text'], $cat);
                }
                DB::table('engineers')->where('id', $engineerId)->update(['updated_at' => $now]);
            });
        } catch (\Throwable $e) {
            SkillCatalog::forget(); // 足したマスタの言葉も戻ったので、覚えていた辞書は捨てる
            throw $e;
        }
        return ['ok' => true, 'count' => count($rows), 'skipped' => $skipped,
            'names' => array_map(fn ($r) => $r['other_text'] !== '' ? $r['other_text'] : $r['item'], $rows)];
    }

    // 1つ選ぶ節（PM/PL の設問など）か
    private static function isSingleSection(string $section): bool
    {
        foreach (SkillForm::tree() as $sec) if ($sec['key'] === $section) return $sec['single'];
        return false;
    }

    // 子の行の親（言語・データベース・OS のグループの親。チェックできるもの）。親の無い行・見出しだけのグループの下の行は null
    private static function parentNode(string $section, string $parent): ?array
    {
        if ($parent === '') return null;
        $p = SkillForm::byPath($section, '', $parent);
        return $p !== null && $p['checkable'] && $p['group'] ? $p : null;
    }

    // 子にチェックがあるのに親にチェックが無い状態を直す（子を選んだら、親にも自動でチェックが入る）。入力と同じ形の状態を受けて返す
    private static function withParents(array $s): array
    {
        foreach (array_merge(array_keys(self::arr($s['chk'] ?? [])), array_keys(self::arr($s['yr'] ?? []))) as $id) {
            $n = SkillForm::node((string) $id);
            if (!$n || !self::isOn($s, $n['id'])) continue;
            $p = self::parentNode($n['section'], $n['parent']);
            if ($p !== null && !self::isOn($s, $p['id'])) $s['chk'][$p['id']] = '1';
        }
        return $s;
    }

    // 子の行だけあって親の行が無い人に、親の行（年数なし）を補う。冪等で、消さない（マイグレーション 000003 が使う）。
    // 補った行は source='autoparent'。戻り値は補った行数
    public static function fillParents(): int
    {
        $have = [];
        foreach (DB::table(self::TABLE)->where('parent', '')->get(['engineer_id', 'section', 'item']) as $x) $have[$x->engineer_id."\0".$x->section."\0".$x->item] = true;
        $add = [];
        foreach (DB::table(self::TABLE)->where('parent', '<>', '')->distinct()->get(['engineer_id', 'section', 'parent']) as $x) {
            $p = self::parentNode($x->section, $x->parent);
            $k = $x->engineer_id."\0".$x->section."\0".$x->parent;
            if ($p === null || isset($have[$k])) continue;
            $have[$k] = true;
            $add[] = ['engineer_id' => $x->engineer_id, 'section' => $x->section, 'parent' => '', 'item' => $x->parent, 'other_text' => '', 'years_min' => null,
                'source' => 'autoparent', 'created_at' => now_str(), 'updated_at' => now_str()];
        }
        foreach (array_chunk($add, 50) as $c) DB::table(self::TABLE)->insert($c);
        return count($add);
    }

    // 行の道筋が、今の JSON でチェックできる項目か（無い・見出しだけに変わったものは false）
    private static function isLive(object $x): bool
    {
        $n = SkillForm::byPath($x->section, $x->parent, $x->item);
        return $n !== null && $n['checkable'];
    }

    // 行の配列（section・parent・item・other_text・years_min）を入れる。移行・ダミー・見本から使う。
    // 同じ鍵の行は年数の大きいほうに寄せて1つにする。すでにある同じ鍵の行は、年数が大きいときだけ年数を上げる（黙って捨てない）
    public static function saveRows(int $engineerId, array $rows, string $source): void
    {
        $merged = [];
        foreach ($rows as $r) {
            $k = self::key($r['section'], $r['parent'], $r['item'], $r['other_text'] ?? '');
            $y = isset($r['years_min']) ? (int) $r['years_min'] : null;
            if (isset($merged[$k])) { $merged[$k]['years_min'] = self::maxYears($merged[$k]['years_min'], $y); continue; }
            $merged[$k] = ['engineer_id' => $engineerId, 'section' => $r['section'], 'parent' => $r['parent'], 'item' => $r['item'],
                'other_text' => $r['other_text'] ?? '', 'years_min' => $y, 'source' => $source, 'created_at' => now_str(), 'updated_at' => now_str()];
        }
        // 子の行があるのに親の行が無ければ、親の行（年数なし）を補う（移行・ダミー・見本も通る）
        foreach ($merged as $row) {
            $p = self::parentNode($row['section'], $row['parent']);
            if ($p === null) continue;
            $pk = self::key($p['section'], '', $p['item'], '');
            if (!isset($merged[$pk])) $merged[$pk] = ['engineer_id' => $engineerId, 'section' => $p['section'], 'parent' => '', 'item' => $p['item'],
                'other_text' => '', 'years_min' => null, 'source' => $source, 'created_at' => now_str(), 'updated_at' => now_str()];
        }
        $existing = [];
        foreach (DB::table(self::TABLE)->where('engineer_id', $engineerId)->get(['id', 'section', 'parent', 'item', 'other_text', 'years_min']) as $x) {
            $existing[self::key($x->section, $x->parent, $x->item, $x->other_text)] = $x;
        }
        $new = [];
        foreach ($merged as $k => $row) {
            if (!isset($existing[$k])) { $new[] = $row; continue; }
            $cur = self::years($existing[$k]);
            if (SkillForm::rank($row['years_min']) > SkillForm::rank($cur)) {
                DB::table(self::TABLE)->where('id', $existing[$k]->id)->update(['years_min' => $row['years_min'], 'updated_at' => now_str()]);
            }
        }
        foreach (array_chunk($new, 50) as $c) DB::table(self::TABLE)->insert($c);
    }

    // 名前・分類・年数の並び（[[名前, 分類, 年数]…]）を置き場所に変えて入れる。ダミー・見本から使う
    public static function saveFromNames(int $engineerId, array $list, string $source): void
    {
        $index = SkillForm::normIndex();
        $rows = [];
        foreach ($list as [$name, $cat, $years]) $rows[] = SkillForm::place((string) $name, (string) $cat, $years === null ? null : (float) $years, $index);
        self::saveRows($engineerId, $rows, $source);
    }

    // 今の表（engineer_skills）から新しい表へ。エンジニアごとに transaction。すでに新しい表に行がある人は飛ばす。戻り値は移した人数
    public static function migrateFromOld(): int
    {
        if (!\Illuminate\Support\Facades\Schema::hasTable('engineer_skills')) return 0;
        $index = SkillForm::normIndex();
        $done = 0;
        foreach (DB::table('engineer_skills')->distinct()->orderBy('engineer_id')->pluck('engineer_id') as $eid) {
            if (DB::table(self::TABLE)->where('engineer_id', $eid)->exists()) continue;
            if (!DB::table('engineers')->where('id', $eid)->exists()) continue;
            $rows = [];
            foreach (DB::table('engineer_skills')->where('engineer_id', $eid)->orderBy('id')->get() as $o) {
                if (trim((string) $o->name) === '') continue;
                $rows[] = SkillForm::place((string) $o->name, (string) $o->category, $o->years === null ? null : (float) $o->years, $index);
            }
            DB::transaction(fn () => self::saveRows((int) $eid, $rows, 'migrated'));
            $done++;
        }
        return $done;
    }

    // ---------- 画面用の状態 ----------

    // 保存済みの状態。入力と同じ形：chk[id]=1・yr[id]=年数の文・oth[id][]=名前・othyr[id][]=年数の文
    public static function state(int $engineerId): array
    {
        $s = ['chk' => [], 'yr' => [], 'oth' => [], 'othyr' => [], 'one' => []];
        foreach (DB::table(self::TABLE)->where('engineer_id', $engineerId)->orderBy('id')->get() as $r) {
            $n = SkillForm::byPath($r->section, $r->parent, $r->item);
            if (!$n || !$n['checkable']) continue;
            if (self::isSingleSection($n['section'])) {
                // 1つ選ぶ設問：同じ設問に行が2つあれば、id の小さいほう
                $g = SkillForm::byPath($n['section'], '', $n['parent']);
                if ($g === null || isset($s['one'][$g['id']])) continue;
                $s['one'][$g['id']] = $n['id'];
                $s['chk'][$n['id']] = '1';
                continue;
            }
            $label = SkillForm::label(self::years($r)) ?? '';
            $s['chk'][$n['id']] = '1';
            if ($n['other']) { $s['oth'][$n['id']][] = $r->other_text; $s['othyr'][$n['id']][] = $label; }
            else $s['yr'][$n['id']] = $label;
        }
        return self::withParents($s); // 子だけで親が無い古いデータも、親にチェックが付いた形で出す
    }

    // 入力の誤りで戻ったときの状態（送った値のとおり）。形の違う値は捨てる
    public static function stateFromInput(array $in): array
    {
        $s = ['chk' => [], 'yr' => [], 'oth' => [], 'othyr' => [], 'one' => []];
        foreach (self::arr($in['chk'] ?? []) as $id => $v) if (!empty($v)) $s['chk'][(string) $id] = '1';
        // 1つ選ぶ設問は、その設問の選択肢のものだけ受ける
        foreach (SkillForm::singleGroups() as $gid => $cids) {
            $v = self::arr($in['one'] ?? [])[$gid] ?? null;
            if (is_string($v) && in_array($v, $cids, true)) {
                $s['one'][$gid] = $v;
                if (!SkillForm::node($v)['none']) $s['chk'][$v] = '1'; // 「選択しない」は one にだけ入れる（件数が1つ増えないように）
            }
        }
        // 年数は、4つの選択肢のものだけ受ける（誤りの値が「チェックあり」の印にならないように）
        foreach (self::arr($in['yr'] ?? []) as $id => $v) if (is_string($v) && SkillForm::value($v) !== null) $s['yr'][(string) $id] = $v;
        foreach (['oth', 'othyr'] as $f) {
            foreach (self::arr($in[$f] ?? []) as $id => $list) {
                $s[$f][(string) $id] = array_map(fn ($x) => is_string($x) ? $x : '', array_values(self::arr($list)));
            }
        }
        return self::withParents($s); // 保存と同じく、子にチェックがあれば親にもチェックを付ける
    }

    // 状態のうち、その項目にチェックがあるか（年数だけ選んでいるときも、チェックありとみなす）
    public static function isOn(array $state, string $id): bool
    {
        return !empty($state['chk'][$id]) || (($state['yr'][$id] ?? '') !== '');
    }

    // 今の JSON に無い項目の行（D10）。画面の下に出し、チェックを外して保存すると消える
    public static function gone(int $engineerId): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)->where('engineer_id', $engineerId)->orderBy('id')->get() as $r) {
            if (self::isLive($r)) continue; // 表に無い項目と、見出しだけに変わった項目の行を出す
            $out[] = (object) ['id' => (int) $r->id, 'name' => self::rowName($r, null), 'years' => SkillForm::label(self::years($r)),
                'path' => implode('／', array_filter([SkillForm::shortTitle($r->section), $r->parent]))];
        }
        return $out;
    }

    // ---------- 表示 ----------

    // 節の順に、チェックのある項目を並べる。
    // ['sections' => [['key', 'title'（短い名前）, 'entries' => […]]…]]。
    // 役割・工程の節の entry は ['name', 'years'（文か null）, 'words', 'other']。
    // 言語などグループの節の entry は ['name'（親）, 'years', 'checked'（親にチェックがあるか）, 'words', 'children' => [上と同じ形…]]
    public static function forDisplay(int $engineerId): array
    {
        $by = [];
        foreach (DB::table(self::TABLE)->where('engineer_id', $engineerId)->orderBy('id')->get() as $r) {
            $by[self::key($r->section, $r->parent, $r->item, '')][] = $r;
        }
        $entriesOf = function (array $n) use ($by) {
            $out = [];
            foreach ($by[self::key($n['section'], $n['parent'], $n['item'], '')] ?? [] as $r) {
                $out[] = ['name' => $n['other'] ? $r->other_text : $n['item'], 'years' => SkillForm::label(self::years($r)),
                    'words' => $n['other'] ? [$r->other_text] : $n['words'], 'other' => $n['other']];
            }
            return $out;
        };
        $sections = [];
        foreach (SkillForm::tree() as $sec) {
            $entries = [];
            foreach ($sec['items'] as $n) foreach ($entriesOf($n) as $e) $entries[] = $e;
            foreach ($sec['groups'] as $g) {
                $p = $g['node'];
                $pr = $by[self::key($p['section'], '', $p['item'], '')][0] ?? null;
                $children = [];
                foreach ($g['items'] as $n) foreach ($entriesOf($n) as $e) $children[] = $e;
                if ($pr === null && !$children) continue;
                $entries[] = ['name' => $p['short'] ?? $p['item'], 'years' => SkillForm::label(self::years($pr)), 'checked' => $pr !== null, 'words' => $p['words'], 'children' => $children, 'other' => false];
            }
            $sections[] = ['key' => $sec['key'], 'title' => $sec['short'], 'entries' => $entries];
        }
        return ['sections' => $sections];
    }

    private static function yearsText(array $e): string
    {
        return $e['name'].($e['years'] ? '（'.$e['years'].'）' : '');
    }

    // forDisplay の結果を、節ごとの文にする。[['title' => '役割', 'text' => 'PM（3年以上）、メンバー（5年以上）']…]（空の節は出さない）。
    // グループは「PHP（5年以上）：Laravel（3年以上）、CakePHP」で、グループ同士は「 ／ 」でつなぐ
    public static function linesOf(array $display): array
    {
        $lines = [];
        foreach ($display['sections'] as $s) {
            if (!$s['entries']) continue;
            $parts = [];
            foreach ($s['entries'] as $e) {
                if (!isset($e['children'])) { $parts[] = self::yearsText($e); continue; }
                $head = $e['checked'] ? self::yearsText($e) : $e['name'];
                $kids = array_map(fn ($c) => self::yearsText($c), $e['children']);
                $parts[] = $kids ? $head.'：'.implode('、', $kids) : $head;
            }
            $lines[] = ['title' => $s['title'], 'text' => implode(isset($s['entries'][0]['children']) ? ' ／ ' : '、', $parts)];
        }
        return $lines;
    }

    public static function lines(int $engineerId): array { return self::linesOf(self::forDisplay($engineerId)); }

    // 一覧の「スキル」列の文。[engineer_id => 'PHP、Laravel、MySQL…']（同じ技術は1回。技術の節が先で、役割・工程は後）。問い合わせは500人ごとに1回
    public static function summaryMany(array $engineerIds): array
    {
        $out = []; $list = [];
        foreach (array_unique(array_map('intval', $engineerIds)) as $id) $out[$id] = '';
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            foreach (DB::table(self::TABLE)->whereIn('engineer_id', $chunk)->get(['engineer_id', 'section', 'parent', 'item', 'other_text']) as $r) {
                if (!SkillForm::matchable($r->section)) continue; // 業界・PM/PL は一覧の列に出さない
                $n = SkillForm::byPath($r->section, $r->parent, $r->item);
                $order = ($n ? $n['pos'] : 99999) + (in_array($r->section, ['roles', 'phases'], true) ? 100000 : 0);
                $list[$r->engineer_id][] = [$order, self::rowName($r, $n)];
            }
        }
        foreach ($list as $eid => $items) {
            usort($items, fn ($a, $b) => $a[0] <=> $b[0]);
            $names = [];
            foreach ($items as [, $name]) $names[self::nameKey($name)] ??= $name;
            $out[$eid] = implode('、', $names);
        }
        return $out;
    }

    // ---------- マッチング ----------

    // マッチング用の索引。[engineer_id => [norm_skill(言葉) => ['name' => 表示名, 'years' => 文か null, 'rank' => 大きさ, 'fromProject' => false]]]。
    // 同じ言葉は年数の大きいほう（React が JavaScript と TypeScript の下にあっても、同じ技術として1つ）
    public static function indexMany(array $engineerIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $engineerIds)) as $id) $out[$id] = [];
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            foreach (DB::table(self::TABLE)->whereIn('engineer_id', $chunk)->get() as $r) {
                if (!SkillForm::matchable($r->section)) continue; // 業界・PM/PL はマッチングに使わない
                $n = SkillForm::byPath($r->section, $r->parent, $r->item);
                $y = self::years($r);
                $rank = SkillForm::rank($y);
                $name = self::rowName($r, $n);
                foreach (self::rowWords($r, $n) as $w) {
                    $k = norm_skill($w);
                    if ($k === '') continue;
                    $cur = $out[$r->engineer_id][$k] ?? null;
                    if ($cur !== null && $cur['rank'] >= $rank) continue;
                    $out[$r->engineer_id][$k] = ['name' => $name, 'years' => SkillForm::label($y), 'rank' => $rank, 'fromProject' => false];
                }
            }
        }
        return $out;
    }

    // 案件の業務と突き合わせる項目（業務経験・業務領域）に、チェックがある人。[engineer_id => [項目の名前 => true]]。問い合わせは500人ごとに1回
    public static function expMany(array $engineerIds): array
    {
        $out = [];
        foreach (array_unique(array_map('intval', $engineerIds)) as $id) $out[$id] = [];
        $items = SkillForm::caseItems();
        $sections = array_values(array_unique(array_column($items, 'section')));
        if (!$items) return $out;
        foreach (array_chunk(array_keys($out), 500) as $chunk) {
            foreach (DB::table(self::TABLE)->whereIn('engineer_id', $chunk)->whereIn('section', $sections)->where('parent', '')->get(['engineer_id', 'section', 'item']) as $r) {
                if (isset($items[$r->item]) && $items[$r->item]['section'] === $r->section) $out[$r->engineer_id][$r->item] = true;
            }
        }
        return $out;
    }

    // 技術マスタの言葉ごとの、使っている人（[term_id => [engineer_id => true]]）。行の言葉を技術マスタで引く
    public static function usedPeople(): array
    {
        $people = [];
        $termsOf = [];
        foreach (DB::table(self::TABLE)->distinct()->get(['engineer_id', 'section', 'parent', 'item', 'other_text']) as $r) {
            if (!SkillForm::matchable($r->section)) continue;
            $n = SkillForm::byPath($r->section, $r->parent, $r->item);
            $pathKey = self::key($r->section, $r->parent, $r->item, $r->other_text);
            if (!isset($termsOf[$pathKey])) {
                $ids = [];
                foreach (self::rowWords($r, $n) as $w) if ($t = SkillCatalog::find($w)) $ids[(int) $t[0]] = true;
                $termsOf[$pathKey] = array_keys($ids);
            }
            foreach ($termsOf[$pathKey] as $tid) $people[$tid][(int) $r->engineer_id] = true;
        }
        return $people;
    }

    // ---------- 案件に合わせた印（EG 本人の画面だけ） ----------

    // 案件の必須・尚可に合う項目に印を付ける（チェックは変えない。印は保存しない）。
    // $state は表示している状態（入力の誤りで戻ったときは送った値）。
    // 戻り値：marks＝[項目の id => 'required'|'preferred']、missing＝本人がチェックした項目のどれにも合わない必須（元の書き方のまま）、empty＝必須・尚可がどちらも空
    public static function caseMarks(array $state, object $c): array
    {
        $req = parse_list($c->required_skills);
        $pref = parse_list($c->preferred_skills);
        $reqKeys = array_map('norm_skill', $req);
        $prefKeys = array_map('norm_skill', $pref);
        $any = function (array $keys, array $wants) {
            foreach ($wants as $w) {
                if ($w === '') continue;
                foreach ($keys as $k) if (Matcher::sameSkill($k, $w)) return true;
            }
            return false;
        };
        $marks = []; $mine = [];
        foreach (SkillForm::nodes() as $n) {
            if (!$n['checkable']) continue;
            $id = $n['id'];
            $words = $n['other'] ? array_values(array_filter(array_map(fn ($x) => is_string($x) ? clean_name($x) : null, self::arr($state['oth'][$id] ?? [])))) : $n['words'];
            $keys = array_values(array_filter(array_map('norm_skill', $words), fn ($k) => $k !== ''));
            if (!$keys) continue;
            if ($any($keys, $reqKeys)) $marks[$id] = 'required';
            elseif ($any($keys, $prefKeys)) $marks[$id] = 'preferred';
            if (self::isOn($state, $id)) array_push($mine, ...$keys);
        }
        $missing = [];
        foreach ($req as $i => $r) if (!$any($mine, [$reqKeys[$i]])) $missing[] = $r;
        // 業務経験・業務領域：案件の必須・尚可の業務に印を付け、本人がチェックしていない必須の業務は足りないものに入れる
        $expReq = CaseExp::split($c->required_exp ?? null);
        $expPref = CaseExp::split($c->preferred_exp ?? null);
        foreach (SkillForm::caseItems() as $name => $it) {
            if (in_array($name, $expReq, true)) {
                $marks[$it['id']] = 'required';
                if (!self::isOn($state, $it['id'])) $missing[] = $name;
            } elseif (in_array($name, $expPref, true)) $marks[$it['id']] = 'preferred';
        }
        return ['marks' => $marks, 'missing' => $missing, 'empty' => !$req && !$pref && !$expReq && !$expPref];
    }
}
