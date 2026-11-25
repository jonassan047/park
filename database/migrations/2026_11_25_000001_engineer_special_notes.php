<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// エンジニアに「特記事項」（ご家庭の事情など、案件を選ぶときに気をつけること）を持たせる（2026-11-25 v0.18.0）。
// 列を足すだけ。ほかの会社・案件のメール・スキルシートには出さない
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineers', function (Blueprint $t) {
            $t->text('special_notes')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('engineers', fn (Blueprint $t) => $t->dropColumn('special_notes'));
    }
};
