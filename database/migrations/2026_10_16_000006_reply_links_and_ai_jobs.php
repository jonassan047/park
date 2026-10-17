<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 案内メールの返事のリンク、AI の仕事の箱（2026-10-16）
return new class extends Migration
{
    public function up(): void
    {
        // メールの「応募したい／質問がある／見送る」のリンクに使う合言葉
        Schema::table('case_mails', fn (Blueprint $t) => $t->string('token', 40)->nullable()->unique());
        // AI の仕事の箱：PARK が仕事を入れ、手元の PC の AI が取りに来て、結果を返す。採用するのは人
        Schema::create('ai_jobs', function (Blueprint $t) {
            $t->id();
            $t->string('kind', 30);                 // script 面談スクリプトの言い回し / resume 経歴書から経験票の下書き
            $t->string('ref_type', 20);             // interview / engineer_file
            $t->unsignedBigInteger('ref_id');
            $t->longText('input');                  // AI に渡すもの（JSON）
            $t->string('status', 10)->default('queued'); // queued 待ち / running 作業中 / done できた / failed 失敗 / applied 採用済み / dropped 捨てた
            $t->longText('output')->nullable();
            $t->text('error')->nullable();
            $t->string('engine', 60)->nullable();   // どの AI でやったか
            $t->unsignedBigInteger('created_by')->nullable();
            $t->dateTime('created_at');
            $t->dateTime('picked_at')->nullable();
            $t->dateTime('done_at')->nullable();
            $t->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_jobs');
        Schema::table('case_mails', fn (Blueprint $t) => $t->dropColumn('token'));
    }
};
