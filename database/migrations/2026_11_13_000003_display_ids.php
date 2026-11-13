<?php

use App\Services\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v0.9.0 表示 ID：人・会社・案件・応募・契約・請求書に、通し番号つきの ID を付ける。
// 列は空を許し（unique）、作る経路で必ず入れる。既存のデータには、作った順に付ける。
// 何度流しても安全（列・表は無ければ作る。埋めるのは空の行だけ）。
return new class extends Migration
{
    // 表 => 足す列
    private const COLUMNS = [
        'ses_companies' => ['code'],
        'engineers' => ['code'],
        'cases' => ['code'],
        'applications' => ['code'],
        'contracts' => ['code'],
        'invoices' => ['code'],
        'users' => ['admin_code', 'staff_code'],
    ];

    public function up(): void
    {
        if (!Schema::hasTable('id_counters')) {
            Schema::create('id_counters', function (Blueprint $t) {
                $t->string('kind', 4)->primary();
                $t->unsignedBigInteger('last_no')->default(0);
            });
        }
        foreach (array_keys(Ids::KINDS) as $kind) {
            DB::table('id_counters')->insertOrIgnore(['kind' => $kind, 'last_no' => 0]);
        }
        foreach (self::COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                if (Schema::hasColumn($table, $col)) continue;
                Schema::table($table, function (Blueprint $t) use ($col) {
                    $t->string($col, 12)->nullable()->unique();
                });
            }
        }
        Ids::fillMissing(true);
    }

    public function down(): void
    {
        foreach (self::COLUMNS as $table => $cols) {
            foreach ($cols as $col) {
                if (!Schema::hasColumn($table, $col)) continue;
                Schema::table($table, function (Blueprint $t) use ($table, $col) {
                    $t->dropUnique($table.'_'.$col.'_unique');
                    $t->dropColumn($col);
                });
            }
        }
        Schema::dropIfExists('id_counters');
    }
};
