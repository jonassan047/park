<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v0.10.1 案件のやり取りの相手：1本ごとに「書ける会社（相手）」を残す列を足す。
// 今ある1本は、今までの相手（所属の会社）のまま。行は消さない・ses_id は変えない。
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('case_threads', 'partner_ses_id')) {
            Schema::table('case_threads', function (Blueprint $t) {
                $t->foreignId('partner_ses_id')->nullable()->constrained('ses_companies'); // 外部キーが索引を兼ねる
            });
        }
        DB::table('case_threads')->whereNull('partner_ses_id')->update(['partner_ses_id' => DB::raw('ses_id')]);
    }

    public function down(): void
    {
        if (Schema::hasColumn('case_threads', 'partner_ses_id')) {
            Schema::table('case_threads', function (Blueprint $t) {
                $t->dropForeign(['partner_ses_id']);
                $t->dropColumn('partner_ses_id');
            });
        }
    }
};
