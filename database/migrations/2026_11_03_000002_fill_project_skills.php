<?php

use App\Services\ProjectSkills;
use App\Support\SkillCatalog;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// v0.3.0 今ある経歴の文字列から、経歴ごとの技術の索引を作る。
// 技術マスタには足さない。今の表の行は消さない・書き換えない。何度流しても同じ結果になる。
return new class extends Migration
{
    public function up(): void
    {
        SkillCatalog::forget();
        ProjectSkills::rebuildAll();
    }

    public function down(): void
    {
        // 新しい表の行を消すだけ（経歴の元の文字列は残る）
        DB::table('engineer_project_skills')->delete();
    }
};
