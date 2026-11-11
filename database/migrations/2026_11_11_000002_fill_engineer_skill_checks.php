<?php

use App\Services\SkillChecks;
use App\Support\SkillCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// v0.4.0 今のスキル（engineer_skills）を、新しい表（engineer_skill_checks）に移す。
// 今の表は変えない。すでに新しい表に行がある人は飛ばす（何度流しても同じ結果になる）。
return new class extends Migration
{
    public function up(): void
    {
        SkillCatalog::forget();
        SkillChecks::migrateFromOld();
    }

    public function down(): void
    {
        // 移行で入れた行だけを消す（画面で保存した行は残す）
        DB::table('engineer_skill_checks')->where('source', 'migrated')->delete();
    }
};
