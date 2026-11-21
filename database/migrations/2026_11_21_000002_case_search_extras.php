<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.15.0 エンジニア選択：PARK に登録していない人への「追加の宛先」と、メールに入れる「EGに確認」の記録（足すだけ）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_search_extras', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('search_id')->index();
            $t->unsignedBigInteger('case_id')->index();
            $t->unsignedBigInteger('ses_id')->nullable();    // 作った会社
            $t->unsignedBigInteger('created_by');            // 作った人（users の id）
            $t->string('email', 254);                        // 小文字にそろえて入れる
            $t->string('name', 100)->nullable();
            $t->boolean('checked')->default(true);
            $t->dateTime('sent_at')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->unsignedBigInteger('reply_token_id')->nullable();
            $t->unsignedBigInteger('outbox_id')->nullable();
            $t->text('subject')->nullable();
            $t->text('body')->nullable();
            $t->text('confirm_items')->nullable();           // 送ったときの「EGに確認」（改行つなぎ）
            $t->timestamps();
            $t->unique(['search_id', 'email'], 'cse_unique');
        });
        Schema::table('reply_tokens', function (Blueprint $t) {
            $t->unsignedBigInteger('search_extra_id')->nullable()->index();
        });
        Schema::table('inbound_mails', function (Blueprint $t) {
            $t->unsignedBigInteger('search_extra_id')->nullable()->index();
        });
        Schema::table('case_mails', function (Blueprint $t) {
            $t->text('confirm_items')->nullable();           // 検索から送ったときの「EGに確認」（改行つなぎ）
        });
    }

    public function down(): void
    {
        Schema::table('case_mails', fn (Blueprint $t) => $t->dropColumn('confirm_items'));
        Schema::table('inbound_mails', function (Blueprint $t) {
            $t->dropIndex(['search_extra_id']);
            $t->dropColumn('search_extra_id');
        });
        Schema::table('reply_tokens', function (Blueprint $t) {
            $t->dropIndex(['search_extra_id']);
            $t->dropColumn('search_extra_id');
        });
        Schema::dropIfExists('case_search_extras');
    }
};
