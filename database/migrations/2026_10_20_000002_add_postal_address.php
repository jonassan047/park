<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 郵便番号・住所（管理者は users、SES は ses_companies。エンジニアは engineers に既にある）（2026-10-20）。今あるデータは書き換えない
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('postal_code', 8)->nullable()->after('company_name');
            $t->string('address', 200)->nullable()->after('postal_code');
        });
        Schema::table('ses_companies', function (Blueprint $t) {
            $t->string('postal_code', 8)->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['postal_code', 'address']));
        Schema::table('ses_companies', fn (Blueprint $t) => $t->dropColumn('postal_code'));
    }
};
