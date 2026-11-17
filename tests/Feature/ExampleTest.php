<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_application_returns_a_successful_response(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('genres.index').'"', false);

        $this->actingAs(User::factory()->create());

        $this->get('/')
            ->assertOk()
            ->assertSee('href="'.route('genres.index').'"', false);
    }
}
