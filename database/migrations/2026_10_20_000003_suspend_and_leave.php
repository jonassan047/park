<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// 停止と退会を分ける（2026-10-20）。users.left_at＝退会した日時、engineers.suspended_at＝エンジニアを停止した日時
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->dateTime('left_at')->nullable();
        });
        Schema::table('engineers', function (Blueprint $t) {
            $t->dateTime('suspended_at')->nullable();
        });

        // 今のデータを直す：退会済のエンジニア・会社の人に left_at を入れる（それ以外の active = 0 は「停止」のまま）
        $now = date('Y-m-d H:i:s');
        $egIds = DB::table('engineers')->where('status', 'left')->pluck('id');
        DB::table('users')->where('active', 0)->where('role', 'eg')->whereIn('engineer_id', $egIds)->update(['left_at' => $now]);
        $sesIds = DB::table('ses_companies')->where('status', 'left')->pluck('id');
        DB::table('users')->where('active', 0)->where('role', 'ses')->whereIn('ses_id', $sesIds)->update(['left_at' => $now]);
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('left_at'));
        Schema::table('engineers', fn (Blueprint $t) => $t->dropColumn('suspended_at'));
    }
};
