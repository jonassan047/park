<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 本人の確認（上の立場の人が入れた情報も、本人が確かめて責任を持つ）と、案件ごとの経歴の更新（2026-10-16）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineers', fn (Blueprint $t) => $t->dateTime('self_confirmed_at')->nullable());
        Schema::table('ses_companies', fn (Blueprint $t) => $t->dateTime('self_confirmed_at')->nullable());
        // 成約した案件を、エンジニアが経験票に書いた日時（終わったあとにもう一度書いてもらう）
        Schema::table('contracts', fn (Blueprint $t) => $t->dateTime('history_updated_at')->nullable());
    }

    public function down(): void
    {
        Schema::table('engineers', fn (Blueprint $t) => $t->dropColumn('self_confirmed_at'));
        Schema::table('ses_companies', fn (Blueprint $t) => $t->dropColumn('self_confirmed_at'));
        Schema::table('contracts', fn (Blueprint $t) => $t->dropColumn('history_updated_at'));
    }
};
