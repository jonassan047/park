<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SES の担当者のふりがな（姓・名）の列（2026-10-20）。承認のとき、招待する担当者のふりがなになる
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->string('contact_kana_sei', 50)->nullable()->after('contact_mei');
            $t->string('contact_kana_mei', 50)->nullable()->after('contact_kana_sei');
        });
    }

    public function down(): void
    {
        Schema::table('ses_companies', fn (Blueprint $t) => $t->dropColumn(['contact_kana_sei', 'contact_kana_mei']));
    }
};
