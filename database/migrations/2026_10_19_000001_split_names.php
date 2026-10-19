<?php

use App\Services\Workflow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 氏名・ふりがなを姓と名に分ける（2026-10-19）。name・kana は、姓＋名をつないだものとして残す
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('name_sei', 50)->nullable()->after('name');
            $t->string('name_mei', 50)->nullable()->after('name_sei');
            $t->string('kana_sei', 50)->nullable()->after('kana');
            $t->string('kana_mei', 50)->nullable()->after('kana_sei');
        });
        Schema::table('engineers', function (Blueprint $t) {
            $t->string('name_sei', 50)->nullable()->after('name');
            $t->string('name_mei', 50)->nullable()->after('name_sei');
            $t->string('kana_sei', 50)->nullable()->after('kana');
            $t->string('kana_mei', 50)->nullable()->after('kana_sei');
        });
        Workflow::splitExistingNames(); // 登録済みの人を、空白で姓・名に分けて入れる
    }

    public function down(): void
    {
        foreach (['engineers', 'users'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn(['name_sei', 'name_mei', 'kana_sei', 'kana_mei']));
        }
    }
};
