<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

// 役割の項目名の閉じ括弧を全角にそろえた（（PM) → （PM））。保存済みのチェックの名前も合わせる。冪等。
// 新しい名前の行がすでにある人（通常は無い）は、古い名前の行を消さずにそのままにする（一意キーにぶつからないため）。
return new class extends Migration
{
    const MAP = ['プロジェクトマネージャー（PM)' => 'プロジェクトマネージャー（PM）', 'プロジェクトリーダー（PL)' => 'プロジェクトリーダー（PL）'];

    public function up(): void
    {
        foreach (self::MAP as $old => $new) {
            $has = DB::table('engineer_skill_checks')->where('section', 'roles')->where('item', $new)->pluck('engineer_id')->all();
            DB::table('engineer_skill_checks')->where('section', 'roles')->where('item', $old)->whereNotIn('engineer_id', $has ?: [0])
                ->update(['item' => $new]);
        }
    }

    public function down(): void
    {
        foreach (self::MAP as $old => $new) {
            $has = DB::table('engineer_skill_checks')->where('section', 'roles')->where('item', $old)->pluck('engineer_id')->all();
            DB::table('engineer_skill_checks')->where('section', 'roles')->where('item', $new)->whereNotIn('engineer_id', $has ?: [0])
                ->update(['item' => $old]);
        }
    }
};
