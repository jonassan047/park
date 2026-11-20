<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.13.0 プロバイダーへの返信（provider_mails）と、案内メールの行に持つ先方の結果。
// 足すだけ。外部キーは付けない（案件・エンジニア・プロバイダーを消しても、送った記録を残すため）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_mails', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('case_id')->index();
            $t->unsignedBigInteger('ses_id')->index();               // 送る会社（作ったときの案件の持ち込み元）
            $t->unsignedBigInteger('provider_id')->nullable();
            $t->unsignedBigInteger('engineer_id')->nullable()->index();
            $t->unsignedBigInteger('case_mail_id')->nullable()->index();
            $t->unsignedBigInteger('inbound_mail_id')->nullable()->unique(); // 同じ受信メールから 2 つは作らない
            $t->string('reply', 12);                                 // interested／question／declined
            $t->string('to_email', 190)->nullable();                 // 送ったときの写し
            $t->string('to_name', 150)->nullable();
            $t->string('subject', 255);
            $t->text('body');
            $t->string('status', 10)->default('draft')->index();     // draft／sent／discarded
            $t->text('note')->nullable();                            // 取りやめの理由など
            $t->unsignedBigInteger('created_by')->nullable();        // 自動で作ったときは空
            $t->unsignedBigInteger('updated_by')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->dateTime('sent_at')->nullable();
            $t->unsignedBigInteger('outbox_id')->nullable();
            $t->unsignedBigInteger('reply_token_id')->nullable();
            $t->timestamps();
        });

        Schema::table('case_mails', function (Blueprint $t) {
            $t->string('result', 10)->nullable();                    // pending／passed／failed
            $t->text('result_note')->nullable();
            $t->unsignedBigInteger('result_by')->nullable();
            $t->dateTime('result_at')->nullable();
        });

        Schema::table('inbound_mails', function (Blueprint $t) {
            $t->string('sender_kind', 10)->default('eg');            // eg／provider
            $t->unsignedBigInteger('provider_mail_id')->nullable()->index();
        });

        Schema::table('reply_tokens', fn (Blueprint $t) => $t->unsignedBigInteger('provider_mail_id')->nullable()->index());
    }

    public function down(): void
    {
        Schema::table('reply_tokens', function (Blueprint $t) {
            $t->dropIndex(['provider_mail_id']);
            $t->dropColumn('provider_mail_id');
        });
        Schema::table('inbound_mails', function (Blueprint $t) {
            $t->dropIndex(['provider_mail_id']);
            $t->dropColumn(['sender_kind', 'provider_mail_id']);
        });
        Schema::table('case_mails', fn (Blueprint $t) => $t->dropColumn(['result', 'result_note', 'result_by', 'result_at']));
        Schema::dropIfExists('provider_mails');
    }
};
