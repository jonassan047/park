<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.8.0 連絡メール：案件に結び付かない、人から人への自由な件名・本文のメール。
// 1回の送信＝direct_mails 1行、宛先ごと＝direct_mail_recipients 1行（50 人に送っても本文は1回だけ持つ）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('direct_mails', function (Blueprint $t) {
            $t->id();
            $t->string('send_key', 40)->unique();                // 二重送信の守り（作成画面を開くたびに作る使い捨ての鍵）
            $t->foreignId('from_user_id')->nullable()->constrained('users')->nullOnDelete(); // 送った人
            $t->string('from_role', 10);                         // 送ったときの立場（admin／ses／eg）
            $t->unsignedBigInteger('from_ses_id')->nullable();   // 送った人の会社（管理者は空）
            $t->string('from_label', 200);                       // 送ったときの表示名（あとで人が消えても読める）
            $t->string('subject', 150);
            $t->text('body');
            $t->unsignedInteger('to_count');
            $t->dateTime('created_at');
        });
        Schema::create('direct_mail_recipients', function (Blueprint $t) {
            $t->id();
            $t->foreignId('direct_mail_id')->constrained('direct_mails')->cascadeOnDelete();
            $t->foreignId('to_user_id')->nullable()->constrained('users')->nullOnDelete(); // 受け取った人
            $t->foreignId('to_engineer_id')->nullable()->constrained('engineers')->nullOnDelete(); // エンジニアあてのときだけ
            $t->string('to_role', 10);
            $t->string('to_label', 200);
            $t->unsignedBigInteger('outbox_id')->nullable();     // mail_outbox の id（送れたかの状態はここから引く）
            $t->dateTime('created_at');
            $t->unique(['direct_mail_id', 'to_user_id'], 'dmr_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('direct_mail_recipients');
        Schema::dropIfExists('direct_mails');
    }
};
