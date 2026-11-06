<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_ログアウトでセッションを無効化しログイン画面へ戻る(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web');
        $this->withSession([
            'logout_probe' => 'before-logout',
        ]);

        $previousSessionId = session()->getId();
        $previousToken = session()->token();

        $this->assertNotEmpty($previousToken);

        $this->post('/logout')
            ->assertRedirect('/login')
            ->assertSessionMissing('logout_probe');

        $this->assertGuest('web');
        $this->assertNotSame(
            $previousSessionId,
            session()->getId()
        );
        $this->assertNotEmpty(session()->token());
        $this->assertNotSame(
            $previousToken,
            session()->token()
        );
        $this->assertDatabaseCount('users', 1);

        $this->get('/login')
            ->assertOk()
            ->assertSee('ログイン');
    }

    public function test_未認証でログアウトするとログイン画面へ戻る(): void
    {
        $this->post('/logout')
            ->assertRedirect('/login');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_ge_tリクエストではログアウトできない(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user, 'web')
            ->get('/logout')
            ->assertStatus(405);

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
    }
}
