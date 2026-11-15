<?php

use App\Services\DirectMerge;
use Illuminate\Database\Migrations\Migration;

// v0.11.0 管理者の直属をやめる：直属（kind='direct'）を指している行と、持ち込み元が空の案件を、合同会社MRSY に付け替える。
// 列は足さない（データの付け替えだけ）。中身は DirectMerge（plan・run・restore）。
// 合同会社MRSY が見つからない（または複数・参加中でない）ときは、何も変えずに止まる（会社を勝手に作らない）。
// 対象が0件なら何もしない（2回目も同じ）。直属の会社の行は消さない。元の値は storage/app/backups/merge-direct-*.json に残る。
// 番号は 000004（同じ日の 000003 provider のあと。ほかの 11_15 の移行のあとに流す）
return new class extends Migration
{
    public function up(): void
    {
        DirectMerge::run();
    }

    // 戻す：いちばん新しいバックアップに記録した行だけ、元の値に戻す（バックアップが無ければ何もしない）
    public function down(): void
    {
        DirectMerge::restore();
    }
};
