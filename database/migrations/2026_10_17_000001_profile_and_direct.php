<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 最初の情報登録（ふりがな・電話・所属・役職・登録を済ませた日時）と、管理者の直属のエンジニア（ses_companies.kind）（2026-10-17）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('kana')->nullable();
            $t->string('phone', 30)->nullable();
            $t->string('position')->nullable();
            $t->dateTime('profile_completed_at')->nullable();
        });
        // kind：'ses'（ふつうの SES）／'direct'（管理者の直属。SES としては扱わない）
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->string('kind', 10)->default('ses')->index();
        });
    }

    public function down(): void
    {
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->dropIndex(['kind']);
            $t->dropColumn('kind');
        });
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['kana', 'phone', 'position', 'profile_completed_at']));
    }
};
