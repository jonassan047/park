<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.3.0 EG のスキル登録：経歴ごとの技術の索引の表と、スキル表に足す列
return new class extends Migration
{
    public function up(): void
    {
        // 経歴（engineer_projects）の1件が使った技術。経歴を保存するたびに作り直す（元の文字列は残る）
        Schema::create('engineer_project_skills', function (Blueprint $t) {
            $t->id();
            $t->foreignId('project_id')->constrained('engineer_projects')->cascadeOnDelete();
            $t->foreignId('term_id')->constrained('skill_terms'); // 消すときは止める（まとめる処理で先に付け替える）
            $t->unique(['project_id', 'term_id']);
        });

        Schema::table('engineer_skills', function (Blueprint $t) {
            $t->char('last_used_ym', 7)->nullable();          // 最後に使った年月 YYYY-MM
            $t->boolean('is_primary')->default(false);        // 得意の印（5つまで）
            $t->string('note', 200)->nullable();              // ひとこと
            $t->timestamps();                                 // 今ある行は空のまま
            $t->index(['engineer_id', 'term_id']);            // 一意にはしない
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_project_skills');
        Schema::table('engineer_skills', fn (Blueprint $t) => $t->dropIndex(['engineer_id', 'term_id']));
        Schema::table('engineer_skills', fn (Blueprint $t) => $t->dropColumn(['last_used_ym', 'is_primary', 'note', 'created_at', 'updated_at']));
    }
};
