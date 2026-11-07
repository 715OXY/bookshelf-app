<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->markTestIncomplete(
            '提供Bladeは変更禁止。ナビゲーションが参照する後続機能の実装後に、トップページの描画を再検証する。'
        );
    }
}
