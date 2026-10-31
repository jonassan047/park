<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v0.2.0 仮登録 → 本登録：状態と、登録した人・代表者を持つ列を足す
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dateTime('registered_at')->nullable();          // 本登録の完了の日時。null＝仮登録
            $t->unsignedBigInteger('registered_by')->nullable(); // 登録した人（外部キーは張らない）
            $t->string('token_kind', 10)->nullable();            // リンクの種類 register／reset
            $t->dateTime('reg_declined_at')->nullable();         // 「心当たりがない」を押した日時
            $t->dateTime('secrecy_agreed_at')->nullable();       // 担当者本人の守秘義務の同意
        });
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->unsignedBigInteger('rep_user_id')->nullable()->index();   // 代表者
            $t->unsignedBigInteger('next_rep_user_id')->nullable();       // 交代で、本登録を待っている次の代表者
        });
        Schema::table('engineers', function (Blueprint $t) {
            $t->dateTime('registered_at')->nullable()->index();  // 候補などから外す条件
        });
    }

    public function down(): void
    {
        // 仮登録の会社は、前の「申込中」に戻す
        DB::table('ses_companies')->where('status', 'provisional')->update(['status' => 'pending']);
        Schema::table('engineers', function (Blueprint $t) {
            $t->dropIndex(['registered_at']);
            $t->dropColumn('registered_at');
        });
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->dropIndex(['rep_user_id']);
            $t->dropColumn(['rep_user_id', 'next_rep_user_id']);
        });
        Schema::table('users', function (Blueprint $t) {
            $t->dropColumn(['registered_at', 'registered_by', 'token_kind', 'reg_declined_at', 'secrecy_agreed_at']);
        });
    }
};
