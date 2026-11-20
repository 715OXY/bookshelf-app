<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginValidationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('不正なログイン入力')]
    public function test_不正なログイン入力を拒否して既存データを保持する(
        array $input,
        array $expectedErrors
    ): void {
        $user = User::factory()->create([
            'email' => 'auth@example.com',
            'password' => Hash::make('Example123!'),
            'remember_token' => null,
        ]);

        $before = $user->fresh()->getRawOriginal();

        $input['remember'] = true;
        $input['extra'] = 'untrusted-value';

        $this->from('/login')
            ->post('/login', $input)
            ->assertRedirect('/login')
            ->assertSessionHasErrors($expectedErrors)
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.remember')
            ->assertSessionMissing('_old_input.extra')
            ->assertSessionMissing('success');

        $this->assertSame(
            array_map(
                static fn (string $message): array => [$message],
                $expectedErrors
            ),
            session('errors')->getBag('default')->messages()
        );

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);

        $this->assertSame(
            $before,
            $user->fresh()->getRawOriginal()
        );
    }

    public static function 不正なログイン入力(): array
    {
        return [
            'メールのみ未入力' => [
                ['password' => 'Example123!'],
                ['email' => 'メールアドレスを入力してください。'],
            ],
            'パスワードのみ未入力' => [
                ['email' => 'auth@example.com'],
                ['password' => 'パスワードを入力してください。'],
            ],
            'メールが配列' => [
                [
                    'email' => ['auth@example.com'],
                    'password' => 'Example123!',
                ],
                ['email' => 'メールアドレスは文字列で入力してください。'],
            ],
            'パスワードが配列' => [
                [
                    'email' => 'auth@example.com',
                    'password' => ['Example123!'],
                ],
                ['password' => 'パスワードは文字列で入力してください。'],
            ],
            'メールが256文字' => [
                [
                    'email' => str_repeat('a', 244).'@example.com',
                    'password' => 'Example123!',
                ],
                ['email' => 'メールアドレスは255文字以下で入力してください。'],
            ],
        ];
    }
}
