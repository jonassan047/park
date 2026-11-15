<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// プロバイダー（案件を SES に提供してくれる上位者）の登録。管理者だけが見る。今ある表は触らない
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('providers', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);                 // 企業名（必須）
            $t->string('sei', 50);                   // 氏名（姓）（必須）
            $t->string('mei', 50)->nullable();       // 氏名（名）
            $t->string('email', 190);                // メール（必須）
            $t->string('phone', 50)->nullable();
            $t->string('postal_code', 10)->nullable();
            $t->string('address', 200)->nullable();
            $t->unsignedInteger('employees')->nullable();
            $t->string('strengths', 200)->nullable();
            $t->text('notes')->nullable();           // 管理者のメモ
            $t->timestamps();
            $t->index('name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('providers');
    }
};
