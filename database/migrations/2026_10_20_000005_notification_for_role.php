<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 兼任ログイン（2026-10-20）：お知らせが、どの立場あてかを残す（admin／ses／eg。空ならどの立場でも出す）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_notifications', function (Blueprint $t) {
            $t->string('for_role', 10)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('app_notifications', fn (Blueprint $t) => $t->dropColumn('for_role'));
    }
};
