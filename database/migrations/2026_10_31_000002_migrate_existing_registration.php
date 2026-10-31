<?php

use App\Services\Registration;
use Illuminate\Database\Migrations\Migration;

// 今いる人を新しい状態に移す。埋めるのは、前の migration で足した新しい列だけ（既にある列は変えない・行を消さない・メールを出さない）
return new class extends Migration
{
    public function up(): void
    {
        Registration::migrateExisting();
    }

    public function down(): void
    {
        // 新しい列は前の migration の down で消えるので、ここでは何もしない
    }
};
