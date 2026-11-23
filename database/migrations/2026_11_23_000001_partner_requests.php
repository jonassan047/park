<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v0.16.0 エンジニア選択：プロパー（自社）／パートナー（他社）に分け、他社の担当者への「紹介の依頼」と、
// 作成画面の下書き・送った単価の文を持つ（足すだけ。外部キーは張らない）
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('case_search_staff', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('search_id')->index();
            $t->unsignedBigInteger('case_id')->index();
            $t->unsignedBigInteger('ses_id')->nullable();           // 依頼する側の会社
            $t->unsignedBigInteger('created_by');                   // 依頼を作った人（users の id）
            $t->unsignedBigInteger('partner_ses_id')->index();      // 依頼先の会社
            $t->unsignedBigInteger('staff_user_id')->index();       // 依頼先の担当者（users の id）
            $t->text('engineer_ids')->nullable();                   // 依頼するエンジニアの id の JSON 配列。手で足しただけなら空
            $t->boolean('by_hand')->default(false);                 // 「エンジニア・担当者を追加」で足した
            $t->boolean('checked')->default(true);                  // 一覧のチェック
            $t->boolean('in_batch')->default(false);                // 作成画面の送る順番に入っている
            $t->text('subject')->nullable();
            $t->text('body')->nullable();
            $t->string('price_text', 100)->nullable();              // 送った単価の文
            $t->dateTime('sent_at')->nullable();
            $t->unsignedBigInteger('sent_by')->nullable();
            $t->unsignedBigInteger('outbox_id')->nullable();
            $t->dateTime('declined_at')->nullable();
            $t->text('declined_note')->nullable();
            $t->unsignedBigInteger('declined_by')->nullable();
            $t->timestamps();
            $t->unique(['search_id', 'staff_user_id'], 'css_unique');
        });
        Schema::table('case_searches', function (Blueprint $t) {
            $t->text('proper_subject')->nullable();                 // プロパーへのメールの下書き
            $t->text('proper_body')->nullable();
            $t->unsignedBigInteger('request_id')->nullable();       // パートナーが紹介の依頼から始めた検索なら、その case_search_staff.id
        });
        Schema::table('case_mails', function (Blueprint $t) {
            $t->string('price_text', 100)->nullable();              // 送った単価の文（本文の「■単価：」の行）
        });
        // 今ある案内メールの本文から、「■単価：」の行を読んで埋める（見つからなければ空のまま）
        // 件数が多くても重くならないよう、500件ずつ読む
        DB::table('case_mails')->select('id', 'body')->chunkById(500, function ($rows) {
            foreach ($rows as $m) {
                if (preg_match('/^■単価：(.*)$/mu', (string) $m->body, $x)) {
                    $v = trim(mb_substr(trim($x[1]), 0, 100));
                    if ($v !== '') DB::table('case_mails')->where('id', $m->id)->update(['price_text' => $v]);
                }
            }
        });
    }

    public function down(): void
    {
        Schema::table('case_mails', fn (Blueprint $t) => $t->dropColumn('price_text'));
        Schema::table('case_searches', fn (Blueprint $t) => $t->dropColumn(['proper_subject', 'proper_body', 'request_id']));
        Schema::dropIfExists('case_search_staff');
    }
};
