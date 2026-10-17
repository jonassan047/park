<?php

namespace Tests\Feature;

use Tests\TestCase;

class CanonicalHostTest extends TestCase
{
    public function test_other_host_moves_to_app_url(): void
    {
        config(['app.url' => 'https://www.jona.jp/park']);
        $this->get('https://jona.jp/park/login?x=1')->assertStatus(301)->assertRedirect('https://www.jona.jp/park/login?x=1');
    }
}
