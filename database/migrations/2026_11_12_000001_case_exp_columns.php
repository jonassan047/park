<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 案件に「必須の業務」「尚可の業務」を持たせる（2026-11-12 v0.5.0）。業務経験・業務領域の項目の名前を、改行でつないで入れる。
// 既存の案件は空のまま（後から埋めない）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $t) {
            $t->text('required_exp')->nullable()->after('preferred_skills');
            $t->text('preferred_exp')->nullable()->after('required_exp');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $t) => $t->dropColumn(['required_exp', 'preferred_exp']));
    }
};
