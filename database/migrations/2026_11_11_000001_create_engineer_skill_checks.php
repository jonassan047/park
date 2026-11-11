<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// v0.4.0 スキル登録画面の全面変更（チェック式）。チェックした項目を入れる新しい表。
// 今の表（engineer_skills）は消さず・書かず・読まずに残す（次のバージョンで消す）。
return new class extends Migration
{
    public function up(): void
    {
        // MySQL・MariaDB の既定の照合順序（utf8mb4_unicode_ci）は、「バッチ」と「パッチ」・「ジョブ」と「じょぶ」を同じとみなし、
        // 一意の鍵に当たってしまう。名前の3つの列は、文字そのままで比べる utf8mb4_bin にする（sqlite は元から文字そのまま）
        $bin = in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true);
        $col = function ($column) use ($bin) {
            if ($bin) $column->collation('utf8mb4_bin');
            return $column;
        };
        Schema::create('engineer_skill_checks', function (Blueprint $t) use ($col) {
            $t->id();
            $t->foreignId('engineer_id')->constrained('engineers')->cascadeOnDelete();
            $t->string('section', 10);                   // skill_form.json の節の key（roles・phases・lang・db・os・pm・dev）
            $col($t->string('parent', 80)->default(''));       // グループの名前。役割・工程の項目と、親そのものの行は空
            $col($t->string('item', 120));                     // 項目の名前。親の行はグループの名前。その他は「その他」
            $col($t->string('other_text', 40)->default(''));   // 「その他」に書いた名前。その他以外は空
            $t->unsignedTinyInteger('years_min')->nullable(); // 0＝1年未満・1＝1年以上・3＝3年以上・5＝5年以上・空＝年数なし（経験あり）
            $t->string('source', 10)->default('form');   // form（画面）・migrated（移行）・seed（ダミー・見本）
            $t->timestamps();
            $t->unique(['engineer_id', 'section', 'parent', 'item', 'other_text'], 'esc_unique');
            $t->index(['section', 'item'], 'esc_item');
        });
    }

    public function down(): void
    {
        // 画面で保存した内容も消える。今の表（engineer_skills）は元のまま残っている
        Schema::dropIfExists('engineer_skill_checks');
    }
};
