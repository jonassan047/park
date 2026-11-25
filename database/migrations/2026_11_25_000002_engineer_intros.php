<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.19.0 推薦文（SES・担当者が貼り付ける、エンジニアの紹介文）。足すだけ。
// 1人のエンジニアにつき、会社ごとに1件（今の本文だけ。前の本文は残さない）。
// picked は「最後に推薦文から入れたスキルの名前」で、推薦文の本文ではない。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engineer_intros', function (Blueprint $t) {
            $t->id();
            $t->foreignId('engineer_id')->constrained('engineers')->cascadeOnDelete();
            $t->unsignedBigInteger('ses_id')->index();          // 書いた会社
            $t->text('body');                                    // 10,000 文字まで
            $t->text('picked')->nullable();                      // 推薦文から入れたスキルの名前（JSON）
            $t->unsignedBigInteger('created_by')->nullable();
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->timestamps();
            $t->unique(['engineer_id', 'ses_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engineer_intros');
    }
};
