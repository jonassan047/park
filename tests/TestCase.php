<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    // テストは見本データで動かす（各テストの $seed = true で入る）
    protected $seeder = \Database\Seeders\SampleSeeder::class;
}
