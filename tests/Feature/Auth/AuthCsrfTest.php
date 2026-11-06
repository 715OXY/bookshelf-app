<?php

namespace Tests\Feature\Auth;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->app->bind(
            VerifyCsrfToken::class,
            EnforcedAuthCsrfMiddleware::class
        );
    }

    #[DataProvider('CSRF不備の送信例')]
    public function test_csr_f不備では認証状態とユーザーデータを変更しない(
        string $path,
        bool $authenticated,
        ?string $submittedToken
    ): void {
        $user = User::factory()->create([
            'email' => 'auth@example.com',
            'password' => Hash::make('Example123!'),
        ]);

        $before = $user->fresh()->getRawOriginal();

        if ($authenticated) {
            $this->actingAs($user, 'web');
        }

        $this->withSession([
            '_token' => 'expected-csrf-token',
            'csrf_probe' => 'preserved',
        ]);

        $input = [
            'name' => '確認ユーザー',
            'email' => 'new@example.com',
            'password' => 'Example123!',
            'password_confirmation' => 'Example123!',
        ];

        if ($submittedToken !== null) {
            $input['_token'] = $submittedToken;
        }

        $this->post($path, $input)
            ->assertStatus(419)
            ->assertSessionHas('csrf_probe', 'preserved');

        if ($authenticated) {
            $this->assertAuthenticatedAs($user, 'web');
        } else {
            $this->assertGuest('web');
        }

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(
            $before,
            $user->fresh()->getRawOriginal()
        );
    }

    public static function CSRF不備の送信例(): array
    {
        return [
            '登録でトークンなし' => ['/register', false, null],
            '登録でトークン不一致' => ['/register', false, 'wrong-token'],
            'ログインでトークンなし' => ['/login', false, null],
            'ログインでトークン不一致' => ['/login', false, 'wrong-token'],
            'ログアウトでトークンなし' => ['/logout', true, null],
            'ログアウトでトークン不一致' => ['/logout', true, 'wrong-token'],
        ];
    }

    public function test_正しい_csr_fトークンで会員登録できる(): void
    {
        $this->withSession([
            '_token' => 'expected-csrf-token',
        ])->post('/register', [
            '_token' => 'expected-csrf-token',
            'name' => '確認ユーザー',
            'email' => 'new@example.com',
            'password' => 'Example123!',
            'password_confirmation' => 'Example123!',
        ])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('users', 1);
        $this->assertAuthenticatedAs(User::firstOrFail(), 'web');
    }
}

class EnforcedAuthCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
