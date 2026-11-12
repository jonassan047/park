<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.7.0 エンジニア検索：案件ごと・作った人ごとの検索（条件）と、一覧に出したエンジニア（チェック・メッセージ）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_searches', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $t->unsignedBigInteger('created_by');            // 作った人（users の id）
            $t->unsignedBigInteger('ses_id')->nullable();    // 作った人の会社（管理者は空）
            $t->text('conditions');                          // JSON：{"req": [...], "pref": [...]}
            $t->text('message_all')->nullable();             // 全員へのメッセージ
            $t->dateTime('last_sent_at')->nullable();
            $t->timestamps();
            $t->unique(['case_id', 'created_by'], 'cs_unique');
        });
        Schema::create('case_search_members', function (Blueprint $t) {
            $t->id();
            $t->foreignId('search_id')->constrained('case_searches')->cascadeOnDelete();
            $t->foreignId('engineer_id')->constrained('engineers')->cascadeOnDelete();
            $t->boolean('checked')->default(false);
            $t->boolean('by_hand')->default(false);          // 手で足した人
            $t->text('message')->nullable();                 // 個別メッセージ
            $t->boolean('omit_note')->default(false);        // 「ご検討ください」の一文を入れない
            $t->timestamps();
            $t->unique(['search_id', 'engineer_id'], 'csm_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_search_members');
        Schema::dropIfExists('case_searches');
    }
};
