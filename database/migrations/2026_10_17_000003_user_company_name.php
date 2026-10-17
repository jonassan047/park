<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// 管理者の企業名（users.company_name）と、メールの差出人の名前（mail_outbox.from_name）（2026-10-17）
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $t) => $t->string('company_name', 100)->nullable()->after('position'));
        Schema::table('mail_outbox', fn (Blueprint $t) => $t->string('from_name', 150)->nullable()->after('to_name'));
    }

    public function down(): void
    {
        Schema::table('mail_outbox', fn (Blueprint $t) => $t->dropColumn('from_name'));
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn('company_name'));
    }
};
