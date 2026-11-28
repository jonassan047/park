<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 企業に「企業代表者」を持たせる（2026-11-28 v0.23.0）。ceo_same＝1 は「PARK窓口担当者に同じ」、0 は「記入」（氏名は ceo_name）。
// 今ある企業は、すべて「窓口に同じ」になる（列を足すだけ。データは消さない）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->boolean('ceo_same')->default(true)->after('rep_mei');
            $t->string('ceo_name', 50)->nullable()->after('ceo_same');
        });
    }

    public function down(): void
    {
        Schema::table('ses_companies', fn (Blueprint $t) => $t->dropColumn(['ceo_same', 'ceo_name']));
    }
};
