<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// エンジニアの細かい登録（履歴書・業務経歴書の項目）、技術マスタ、エンジニアごとのデータ領域（書類）
return new class extends Migration
{
    public function up(): void
    {
        // 技術マスタ：案件メールとエンジニアのスキルを、同じ言葉で突き合わせるための辞書
        Schema::create('skill_terms', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();             // 正式な名前（例：PostgreSQL）
            $t->string('category', 20)->default('other');
            $t->text('aliases')->nullable();          // 別名（「、」区切り。例：Postgres、psql）
            $t->string('source', 10)->default('master'); // master 最初から / auto 案件から自動で追加 / user 手で追加
            $t->boolean('reviewed')->default(true);   // 自動で追加したものは、運営が分類を確かめるまで false
            $t->timestamps();
        });

        Schema::table('engineers', function (Blueprint $t) {
            $t->string('phone')->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address')->nullable();
            $t->string('address_kana')->nullable();
            $t->string('nearest_line')->nullable();
            $t->integer('walk_minutes')->nullable();
            $t->string('education')->nullable();        // 最終学歴
            $t->string('graduated_ym', 7)->nullable();  // 卒業年月 YYYY-MM
            $t->text('career_summary')->nullable();     // 職務概要
            $t->string('desired_roles')->nullable();    // 希望業務（PM・PL・SE など）
            $t->string('english_level')->nullable();    // 語学
            $t->text('appeal')->nullable();             // 志望の動機・特技・アピールポイント
            $t->text('wishes')->nullable();             // 本人希望
            $t->string('gender', 10)->nullable();
            $t->date('birth_date')->nullable();         // 生年月日（履歴書用。年だけなら birth_year）
        });

        // 学歴・職歴（履歴書の表）
        Schema::create('engineer_histories', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 10);    // edu 学歴 / work 職歴
            $t->string('ym', 7)->nullable();
            $t->string('text', 500);
            $t->integer('sort')->default(0);
        });

        // 資格・免許
        Schema::create('engineer_certs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->string('name');
            $t->string('ym', 7)->nullable();
            $t->integer('sort')->default(0);
        });

        // 業務経歴の案件を、業務経歴書の列に合わせて細かく持つ
        Schema::table('engineer_projects', function (Blueprint $t) {
            $t->string('os_tools', 500)->nullable(); // 機種・OS・ツール
            $t->string('langs', 500)->nullable();    // 言語
            $t->string('dbs', 500)->nullable();      // DB
            $t->integer('sort')->nullable();
        });

        Schema::table('engineer_skills', function (Blueprint $t) {
            $t->unsignedBigInteger('term_id')->nullable();
        });

        // エンジニアごとのデータ領域（書き出した履歴書・業務経歴書、アップロードした書類）
        Schema::create('engineer_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->string('kind', 20);           // resume 履歴書 / careersheet 業務経歴書 / other その他
            $t->string('title');
            $t->string('filename');           // ダウンロードのときの名前
            $t->string('path');               // storage の中の場所（公開されない）
            $t->integer('size')->default(0);
            $t->boolean('generated')->default(false); // PARKS で書き出したもの
            $t->unsignedBigInteger('uploaded_by')->nullable();
            $t->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_files');
        Schema::dropIfExists('engineer_certs');
        Schema::dropIfExists('engineer_histories');
        Schema::dropIfExists('skill_terms');
        Schema::table('engineer_skills', fn (Blueprint $t) => $t->dropColumn('term_id'));
        Schema::table('engineer_projects', fn (Blueprint $t) => $t->dropColumn(['os_tools', 'langs', 'dbs', 'sort']));
        Schema::table('engineers', fn (Blueprint $t) => $t->dropColumn(['phone', 'postal_code', 'address', 'address_kana', 'nearest_line', 'walk_minutes', 'education',
            'graduated_ym', 'career_summary', 'desired_roles', 'english_level', 'appeal', 'wishes', 'gender', 'birth_date']));
    }
};
