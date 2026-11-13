<?php

use App\Services\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// v0.9.1 表示 ID の接頭辞を、アルファベット2文字にそろえる（A-→AD-、T-→ST-、S-→SE-、E-→EG-、C-→CA-。AP・CT・IV は同じ）。
// すでに付いている旧い1文字の ID の接頭辞だけを付け替える（番号の部分は同じ。新形式の行は触らない＝何度流しても同じ）。
// id_counters は触らない（kind は種類の文字のまま、番号も同じ）。
return new class extends Migration
{
    public function up(): void
    {
        $this->rename(fn (string $kind) => [$kind, Ids::PREFIX[$kind]]);
    }

    public function down(): void
    {
        $this->rename(fn (string $kind) => [Ids::PREFIX[$kind], $kind]);
    }

    // $map：種類 → [いまの接頭辞, 付け替えたあとの接頭辞]
    private function rename(callable $map): void
    {
        foreach (Ids::KINDS as $kind => [$table, $col]) {
            [$from, $to] = $map($kind);
            if ($from === $to) continue;
            // 「C-」は「CA-」「CT-」に当たらない（ハイフンまで含めて探す）
            $rows = DB::table($table)->where($col, 'like', $from.'-%')->get(['id', $col]);
            foreach ($rows as $row) {
                DB::table($table)->where('id', $row->id)->update([$col => $to.'-'.substr((string) $row->$col, strlen($from) + 1)]);
            }
        }
    }
};
