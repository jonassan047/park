<?php

use App\Services\Ids;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// プロバイダーに表示 ID（PV-000001）を付ける。列を足し、今ある行には作った順に付ける（何度流しても安全）
return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('providers', 'code')) {
            Schema::table('providers', fn (Blueprint $t) => $t->string('code', 12)->nullable()->unique());
        }
        DB::table('id_counters')->insertOrIgnore(['kind' => 'PV', 'last_no' => 0]);
        foreach (DB::table('providers')->whereNull('code')->orderBy('id')->pluck('id') as $id) Ids::assign('PV', (int) $id);
    }

    public function down(): void
    {
        Schema::table('providers', fn (Blueprint $t) => $t->dropColumn('code'));
    }
};
