<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// v0.17.0 EG ごとの担当者（engineers.staff_user_id と付け替えの記録）と、パートナー側の追跡（提案の依頼・最後の結果）
// 足すだけ（外部キーは張らない）。今ある EG の担当者は、同じ migration の中で埋める
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engineers', function (Blueprint $t) {
            $t->unsignedBigInteger('staff_user_id')->nullable()->index(); // その EG の担当者（users の id）
        });
        Schema::create('engineer_staff_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('engineer_id')->index();
            $t->unsignedBigInteger('from_user_id')->nullable();
            $t->unsignedBigInteger('to_user_id');
            $t->unsignedBigInteger('changed_by')->nullable();   // システムの付け替えは空
            $t->string('reason', 20);
            $t->dateTime('created_at')->nullable();
        });
        Schema::table('case_mails', function (Blueprint $t) {
            $t->dateTime('handoff_at')->nullable();             // パートナーが受注 SES に提案を頼んだ日
            $t->unsignedBigInteger('handoff_by')->nullable();
            $t->text('handoff_note')->nullable();
            $t->string('outcome', 10)->nullable();              // パートナーが記録した最後の結果：won／lost
            $t->text('outcome_note')->nullable();
            $t->unsignedBigInteger('outcome_by')->nullable();
            $t->dateTime('outcome_at')->nullable();
        });

        // 埋め戻し：今ある EG 全部（退会済も）。500件ずつ。app のクラスは使わず、「使える担当者」の条件をここに書く
        $usable = function (int $sesId, ?int $userId = null) {
            if (DB::table('ses_companies')->where('id', $sesId)->value('status') !== 'active') return null;
            $q = DB::table('users')->whereIn('role', ['ses', 'admin'])->where('ses_id', $sesId)->where('active', 1)->whereNull('left_at')->whereNotNull('registered_at');
            return $userId === null ? $q : $q->where('id', $userId);
        };
        DB::table('engineers')->select('id', 'ses_id')->chunkById(500, function ($rows) use ($usable) {
            foreach ($rows as $e) {
                $sesId = (int) $e->ses_id;
                $pick = null;
                $reg = DB::table('users')->where('engineer_id', $e->id)->orderByRaw("role = 'eg' desc")->orderBy('id')->value('registered_by');
                if ($reg && ($q = $usable($sesId, (int) $reg)) && $q->exists()) $pick = (int) $reg;
                if (!$pick) {
                    $rep = DB::table('ses_companies')->where('id', $sesId)->value('rep_user_id');
                    if ($rep && ($q = $usable($sesId, (int) $rep)) && $q->exists()) $pick = (int) $rep;
                }
                if (!$pick && ($q = $usable($sesId))) $pick = (int) $q->orderBy('id')->value('id') ?: null;
                if (!$pick) continue; // 会社が停止・退会済のときだけ。読むときの代わりの決まりで扱う
                DB::table('engineers')->where('id', $e->id)->update(['staff_user_id' => $pick]);
                DB::table('engineer_staff_logs')->insert(['engineer_id' => $e->id, 'from_user_id' => null, 'to_user_id' => $pick, 'changed_by' => null, 'reason' => 'backfill', 'created_at' => now()->format('Y-m-d H:i:s')]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('case_mails', fn (Blueprint $t) => $t->dropColumn(['handoff_at', 'handoff_by', 'handoff_note', 'outcome', 'outcome_note', 'outcome_by', 'outcome_at']));
        Schema::dropIfExists('engineer_staff_logs');
        Schema::table('engineers', fn (Blueprint $t) => $t->dropColumn('staff_user_id'));
    }
};
