<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

// SES の代表者・担当者の氏名も姓と名に分ける（2026-10-20）。rep_name・contact_name は、姓＋名をつないだものとして残す
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->string('rep_sei', 50)->nullable()->after('rep_name');
            $t->string('rep_mei', 50)->nullable()->after('rep_sei');
            $t->string('contact_sei', 50)->nullable()->after('contact_name');
            $t->string('contact_mei', 50)->nullable()->after('contact_sei');
        });
        DB::table('ses_companies')->orderBy('id')->chunkById(200, function ($rows) {
            foreach ($rows as $row) {
                [$rs, $rm] = split_name($row->rep_name);
                [$cs, $cm] = split_name($row->contact_name);
                DB::table('ses_companies')->where('id', $row->id)->update([
                    'rep_sei' => $rs === null ? null : mb_substr($rs, 0, 50), 'rep_mei' => $rm === null ? null : mb_substr($rm, 0, 50),
                    'contact_sei' => $cs === null ? null : mb_substr($cs, 0, 50), 'contact_mei' => $cm === null ? null : mb_substr($cm, 0, 50)]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('ses_companies', fn (Blueprint $t) => $t->dropColumn(['rep_sei', 'rep_mei', 'contact_sei', 'contact_mei']));
    }
};
