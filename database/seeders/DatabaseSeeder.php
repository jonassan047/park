<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

// 公開版にも手元にも入れてよいものだけ（技術マスタの辞書）。
// 見本データは入れない。手元で試したいときだけ：php artisan db:seed --class=SampleSeeder
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        \App\Support\SkillCatalog::sync(); // 技術マスタの辞書
    }
}
