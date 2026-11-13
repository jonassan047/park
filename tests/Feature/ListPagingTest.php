<?php

namespace Tests\Feature;

use Tests\TestCase;

// 一覧のあいうえお順と、1ページ 20 件（2026-11-13）
class ListPagingTest extends TestCase
{
    public function test_sort_kana_orders_gojuon_katakana_as_hiragana_and_blank_last(): void
    {
        $rows = collect([
            (object) ['name' => 'D', 'kana' => ''],
            (object) ['name' => 'B', 'kana' => 'さとう'],
            (object) ['name' => 'C', 'kana' => 'イシカワ'],
            (object) ['name' => 'A', 'kana' => 'あべ'],
        ]);
        $this->assertSame(['D', 'A', 'C', 'B'], sort_kana($rows)->pluck('name')->all());
    }

    public function test_page_slice_is_20_per_page_and_clamps_page(): void
    {
        $list = collect(range(1, 45));
        $this->app['request']->query->set('page', '3');
        [$rows, $p] = page_slice($list);
        $this->assertCount(5, $rows);
        $this->assertSame(3, $p['last']);
        $this->app['request']->query->set('page', '99');
        [$rows, $p] = page_slice($list);
        $this->assertSame(3, $p['page']);
        $this->app['request']->query->set('page', '0');
        [$rows, $p] = page_slice($list);
        $this->assertSame(1, $p['page']);
        $this->assertCount(20, $rows);
    }
}
