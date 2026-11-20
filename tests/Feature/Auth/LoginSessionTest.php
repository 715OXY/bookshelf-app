<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LoginSessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_ログイン成功時にセッションを再生成する(): void
    {
        $user = $this->ログイン用ユーザーを作成する();
        $before = $user->fresh()->getRawOriginal();

        $this->withSession([
            'login_probe' => 'preserved',
        ]);

        $previousSessionId = session()->getId();
        $previousToken = session()->token();

        $this->assertGuest('web');
        $this->assertNotEmpty($previousSessionId);
        $this->assertNotEmpty($previousToken);

        $this->post('/login', [
            'email' => 'auth@example.com',
            'password' => 'Example123!',
        ])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors()
            ->assertSessionHas('login_probe', 'preserved');

        $this->assertAuthenticatedAs($user, 'web');

        $this->assertNotEmpty(session()->getId());
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
        $this->assertSame(
            $before,
            $user->fresh()->getRawOriginal()
        );
    }

    private function ログイン用ユーザーを作成する(): User
    {
        return User::factory()->create([
            'email' => 'auth@example.com',
            'password' => Hash::make('Example123!'),
            'remember_token' => null,
        ]);
    }
}
