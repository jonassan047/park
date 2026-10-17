<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 直属の所属の名前を「管理者の直属」にする（立場の呼び名を「運営」から「管理者」に変えたため。2026-10-17）
return new class extends Migration
{
    public function up(): void
    {
        DB::table('ses_companies')->where('kind', 'direct')->update(['name' => '管理者の直属']);
    }

    public function down(): void
    {
        DB::table('ses_companies')->where('kind', 'direct')->update(['name' => 'PARK 運営（直属）']);
    }
};
