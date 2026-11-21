<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 案件に、必須・尚可の「元の行（文）」と「EGに確認」を持たせる（2026-11-21 v0.14.0）。改行でつないで入れる。
// 既存の案件は空のまま（列を足すだけ。書き換えは php artisan park:case-keywords）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cases', function (Blueprint $t) {
            $t->text('required_lines')->nullable()->after('preferred_exp');
            $t->text('preferred_lines')->nullable()->after('required_lines');
            $t->text('confirm_items')->nullable()->after('preferred_lines');
        });
    }

    public function down(): void
    {
        Schema::table('cases', fn (Blueprint $t) => $t->dropColumn(['required_lines', 'preferred_lines', 'confirm_items']));
    }
};
