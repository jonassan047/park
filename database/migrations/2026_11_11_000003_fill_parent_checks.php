<?php

use App\Services\SkillChecks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// v0.4.0 子（Laravel など）だけにチェックがあって、親（PHP など）の行が無い人に、親の行（年数なし＝経験あり）を補う。
// 子を選ぶと親にも自動でチェックが入る決まりに、すでにあるデータを合わせる。冪等で、データは消さない。
return new class extends Migration
{
    public function up(): void
    {
        SkillChecks::fillParents();
    }

    public function down(): void
    {
        // 補った行（source='autoparent'）だけを消す。本人が保存した親の行は残る
        DB::table('engineer_skill_checks')->where('source', 'autoparent')->delete();
    }
};
