<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// SES が案件をエンジニアにメールで案内する流れ（2026-10-16 tezzo）
return new class extends Migration
{
    public function up(): void
    {
        // メールで呼ぶ名前（愛称など。空なら氏名）
        Schema::table('engineers', fn (Blueprint $t) => $t->string('nickname')->nullable());
        // 返信の宛先（SES の担当者）
        Schema::table('mail_outbox', fn (Blueprint $t) => $t->string('reply_to')->nullable());
        // 案件の案内メール（1人1通）と、その返事
        Schema::create('case_mails', function (Blueprint $t) {
            $t->id();
            $t->foreignId('case_id')->constrained('cases')->cascadeOnDelete();
            $t->foreignId('engineer_id')->constrained()->cascadeOnDelete();
            $t->unsignedBigInteger('ses_id')->nullable();   // 送った SES（運営なら空）
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->string('route', 10);                        // own 自社のエンジニア（SES から直接）/ group グループの人（PARKS から）
            $t->string('mode', 10);                         // same 同じ内容 / each 個別
            $t->string('subject');
            $t->text('body');
            $t->integer('score')->nullable();
            $t->text('suggest')->nullable();                // 送るときに出した提案
            $t->unsignedBigInteger('outbox_id')->nullable();
            $t->dateTime('sent_at');
            $t->string('reply', 12)->default('none');       // none 返事なし / interested 応募したい / question 質問 / declined 見送り
            $t->text('reply_note')->nullable();
            $t->string('reply_via', 10)->nullable();        // ses SES が記録 / app 本人が画面で
            $t->dateTime('replied_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('case_mails');
        Schema::table('mail_outbox', fn (Blueprint $t) => $t->dropColumn('reply_to'));
        Schema::table('engineers', fn (Blueprint $t) => $t->dropColumn('nickname'));
    }
};
