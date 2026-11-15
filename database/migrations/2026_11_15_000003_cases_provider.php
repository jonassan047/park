<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 案件にプロバイダー（案件の提供元）を結びつける。今ある案件は空のまま（編集のときに選ぶ）
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('cases', 'provider_id')) {
            Schema::table('cases', fn (Blueprint $t) => $t->unsignedBigInteger('provider_id')->nullable()->index());
        }
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $t) => $t->dropColumn('provider_id'));
    }
};
