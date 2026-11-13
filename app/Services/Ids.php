<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

// 表示 ID（CA-000012 のような、人が口で言える通し番号）。v0.9.0（接頭辞は v0.9.1 から2文字）
// 番号は専用の数え表 id_counters で数える。一度使った番号は、行を消しても二度と使わない。
// 付けた ID は変えない（空でない列は上書きしない）。
class Ids
{
    // 種類 => [表, 列]
    const KINDS = [
        'A' => ['users', 'admin_code'],          // 管理者
        'T' => ['users', 'staff_code'],          // 担当者（SES の人）
        'S' => ['ses_companies', 'code'],        // 会社（SES も直属も）
        'E' => ['engineers', 'code'],            // エンジニア
        'C' => ['cases', 'code'],                // 案件
        'AP' => ['applications', 'code'],        // 応募
        'CT' => ['contracts', 'code'],           // 契約
        'IV' => ['invoices', 'code'],            // 請求書（発行のときに付ける）
    ];

    // 種類 => 画面に出す接頭辞（アルファベット2文字。v0.9.1）。数え表 id_counters の kind は、種類の文字のまま
    const PREFIX = ['A' => 'AD', 'T' => 'ST', 'S' => 'SE', 'E' => 'EG', 'C' => 'CA', 'AP' => 'AP', 'CT' => 'CT', 'IV' => 'IV'];

    const DIGITS = 6;

    public static function format(string $kind, int $n): string
    {
        return self::PREFIX[$kind].'-'.str_pad((string) $n, self::DIGITS, '0', STR_PAD_LEFT);
    }

    // ID の接頭辞（新も、v0.9.0 の旧形式の1文字も）から、種類を引く。分からなければ null
    public static function kindOfPrefix(string $prefix): ?string
    {
        $kind = array_search($prefix, self::PREFIX, true);
        if ($kind !== false) return $kind;
        return isset(self::PREFIX[$prefix]) ? $prefix : null; // 旧形式（種類の文字そのまま）
    }

    // 表示 ID（新形式）から、種類を引く
    public static function kindOf(string $code): ?string
    {
        return self::kindOfPrefix(explode('-', $code)[0]);
    }

    // 次の番号を取る。作る前に取って、insert の配列に入れる
    public static function next(string $kind): string
    {
        return DB::transaction(function () use ($kind) {
            $row = DB::table('id_counters')->where('kind', $kind)->lockForUpdate()->first();
            if (!$row) {
                DB::table('id_counters')->insertOrIgnore(['kind' => $kind, 'last_no' => 0]);
                $row = DB::table('id_counters')->where('kind', $kind)->lockForUpdate()->first();
            }
            $n = (int) $row->last_no + 1;
            DB::table('id_counters')->where('kind', $kind)->update(['last_no' => $n]);
            return self::format($kind, $n);
        });
    }

    // その行の列が空のときだけ ID を付けて返す。空でなければ今の値を返す（役割の付与・発行で使う）
    public static function assign(string $kind, int $rowId): ?string
    {
        [$table, $col] = self::KINDS[$kind];
        return DB::transaction(function () use ($kind, $table, $col, $rowId) {
            $row = DB::table($table)->where('id', $rowId)->lockForUpdate()->first();
            if (!$row) return null;
            if (!empty($row->$col)) return $row->$col;
            $code = self::next($kind);
            DB::table($table)->where('id', $rowId)->update([$col => $code]);
            return $code;
        });
    }

    // 入力のゆれ（全角・小文字・前後の空白・ゼロ埋めやハイフンの省略）を吸収して、正しい形にする。形が合わなければ null
    public static function normalize(?string $input): ?string
    {
        $s = mb_convert_kana((string) $input, 'as');
        $s = str_replace(['－', '−', '‐', '―', 'ー', '–', '—'], '-', $s);
        $s = strtoupper(preg_replace('/\s+/u', '', $s));
        // 新しい接頭辞（AD・ST・SE・EG・CA・AP・CT・IV）と、v0.9.0 の旧い1文字（A・T・S・E・C）の両方を受ける（送信済みメールに旧形式が残るため）
        if (!preg_match('/^(AP|AD|CA|CT|IV|SE|ST|EG|A|C|E|S|T)-?(\d{1,12})$/', $s, $m)) return null;
        $n = (int) ltrim($m[2], '0');
        if ($n < 1) return null;
        return self::format(self::kindOfPrefix($m[1]), $n);
    }

    // お知らせの題・メールの件名の末尾に付ける文字。ID が空なら何も付けない
    public static function tag(?string $code): string
    {
        return $code ? '［'.$code.'］' : '';
    }

    // 種類ごとの、ID が空の行の問い合わせ（付ける順に並べる）
    private static function missingQuery(string $kind)
    {
        [$table, $col] = self::KINDS[$kind];
        $q = DB::table($table)->whereNull($col);
        if ($kind === 'A') $q->where('role', 'admin');
        if ($kind === 'T') $q->where('role', 'ses');
        if ($kind === 'IV') return $q->whereIn('status', ['issued', 'paid'])->orderBy('issued_at')->orderBy('id');
        return $q->orderBy('id');
    }

    // ID の無い行の数（種類ごと）。park:status と park:fill-ids の「見るだけ」で使う
    public static function missingCounts(): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) $out[$kind] = self::missingQuery($kind)->count();
        return $out;
    }

    // 数え表を「今の値と、付いている最大の番号の大きいほう」にそろえる
    private static function syncCounter(string $kind): void
    {
        [$table, $col] = self::KINDS[$kind];
        $max = 0;
        foreach (DB::table($table)->whereNotNull($col)->pluck($col) as $code) {
            $max = max($max, (int) substr((string) $code, strpos((string) $code, '-') + 1)); // 旧形式（C-…）も新形式（CA-…）も、「-」のあとが番号
        }
        DB::table('id_counters')->insertOrIgnore(['kind' => $kind, 'last_no' => 0]);
        DB::table('id_counters')->where('kind', $kind)->where('last_no', '<', $max)->update(['last_no' => $max]);
    }

    // ID の空の行を、作った順に埋める。$apply が false なら数えるだけ。種類ごとの（埋めた／埋める）件数を返す
    public static function fillMissing(bool $apply = true): array
    {
        $out = [];
        foreach (array_keys(self::KINDS) as $kind) {
            [$table, $col] = self::KINDS[$kind];
            $ids = self::missingQuery($kind)->pluck('id');
            $out[$kind] = $ids->count();
            if (!$apply) continue;
            self::syncCounter($kind);
            foreach ($ids as $id) self::assign($kind, (int) $id);
        }
        return $out;
    }
}
