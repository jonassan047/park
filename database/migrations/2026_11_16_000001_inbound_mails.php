<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.12.0 案内メールの返信先トークン（reply_tokens）と、受け取ったメールの記録（inbound_mails）。
// 今ある表は mail_outbox に message_id を足すだけ。表を2つ足す。外部キーは付けない（案件・エンジニアを消しても、受信の記録を残すため）。
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mail_outbox', fn (Blueprint $t) => $t->string('message_id', 255)->nullable()->index());

        Schema::create('reply_tokens', function (Blueprint $t) {
            $t->id();
            $t->string('token', 40)->unique();
            $t->string('kind', 20);                              // case-mail（案内メール）。次の版でプロバイダーへの送信にも使う
            $t->unsignedBigInteger('case_mail_id')->nullable()->index();
            $t->unsignedBigInteger('case_id');
            $t->unsignedBigInteger('ses_id')->nullable();        // 送った会社
            $t->unsignedBigInteger('engineer_id')->nullable();
            $t->unsignedBigInteger('outbox_id')->nullable();
            $t->string('message_id', 255)->nullable()->index();
            $t->boolean('via_park')->default(false);             // 送ったとき、受信が使えて、返信先を PARK に向けたか
            $t->dateTime('sent_at');
            $t->dateTime('created_at');
            $t->index('case_id');
        });

        Schema::create('inbound_mails', function (Blueprint $t) {
            $t->id();
            // 取り込み
            $t->string('source', 10);                            // imap／file
            $t->unsignedBigInteger('imap_uidvalidity')->nullable();
            $t->unsignedBigInteger('imap_uid')->nullable();
            $t->string('message_id', 255)->nullable()->unique();
            $t->string('raw_hash', 64)->unique();
            $t->string('raw_path', 255)->nullable();
            $t->unsignedBigInteger('raw_size')->default(0);
            $t->dateTime('fetched_at');
            $t->dateTime('received_at');
            // 中身
            $t->string('from_email', 255)->default('');
            $t->string('from_name', 255)->default('');
            $t->text('to_addrs')->nullable();
            $t->text('subject')->nullable();
            $t->text('in_reply_to')->nullable();
            $t->text('references')->nullable();
            $t->mediumText('body_text')->nullable();
            $t->text('attachments')->nullable();                 // JSON：名前・種類・大きさだけ
            $t->string('auto', 10)->nullable();                  // bounce／auto
            // 紐付け
            $t->string('status', 10)->default('unlinked');       // linked／unlinked／ignored
            $t->string('match_by', 15)->nullable();              // token／in_reply_to／subject／body／manual
            $t->unsignedBigInteger('reply_token_id')->nullable()->index();
            $t->unsignedBigInteger('case_mail_id')->nullable()->index();
            $t->unsignedBigInteger('case_id')->nullable()->index();
            $t->unsignedBigInteger('ses_id')->nullable()->index();
            $t->unsignedBigInteger('engineer_id')->nullable()->index();
            $t->boolean('from_mismatch')->default(false);       // 差出人が EG の登録アドレスと違う
            $t->unsignedBigInteger('linked_by')->nullable();
            $t->dateTime('linked_at')->nullable();
            $t->text('note')->nullable();
            // 知らせ
            $t->dateTime('notified_at')->nullable();
            $t->dateTime('ses_read_at')->nullable();
            $t->unique(['imap_uidvalidity', 'imap_uid'], 'inbound_imap_unique');
            $t->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inbound_mails');
        Schema::dropIfExists('reply_tokens');
        Schema::table('mail_outbox', function (Blueprint $t) {
            $t->dropIndex(['message_id']);
            $t->dropColumn('message_id');
        });
    }
};
