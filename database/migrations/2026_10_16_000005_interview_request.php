<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 面談依頼の内容（案件をくれた先が確かめたいことなど）。面談スクリプトに入れる（2026-10-16）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('interviews', fn (Blueprint $t) => $t->text('request_note')->nullable());
    }

    public function down(): void
    {
        Schema::table('interviews', fn (Blueprint $t) => $t->dropColumn('request_note'));
    }
};
