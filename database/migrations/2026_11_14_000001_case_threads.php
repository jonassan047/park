<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.10.0 案件のやり取り：案件×エンジニア×会社ごとの1本（case_threads）と、その中の発言（case_thread_posts）。
// 今ある表は触らない。表を2つ足すだけ。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_threads', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases');
            $t->foreignId('engineer_id')->constrained('engineers');
            $t->foreignId('ses_id')->constrained('ses_companies'); // 作ったときの所属の会社（直属の入れ物も可）
            $t->dateTime('last_post_at')->nullable();
            $t->dateTime('created_at');
            $t->unique(['case_id', 'engineer_id', 'ses_id'], 'ct_unique');
            $t->index('ses_id');
        });
        Schema::create('case_thread_posts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('thread_id')->constrained('case_threads')->cascadeOnDelete();
            $t->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('from_side', 5);                  // eg／ses／admin
            $t->string('from_label', 200);               // 書いたときの表示名（あとで人が消えても読める）
            $t->text('body');
            $t->boolean('read_by_eg')->default(false);
            $t->boolean('read_by_side')->default(false); // side＝所属の会社の担当者（直属なら管理者）
            $t->dateTime('created_at');
            $t->index(['thread_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_thread_posts');
        Schema::dropIfExists('case_threads');
    }
};
