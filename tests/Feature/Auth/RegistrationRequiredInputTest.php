<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationRequiredInputTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('必須項目の未送信例')]
    public function test_必須項目の未送信を拒否して既存データを保持する(
        string $field,
        array $expectedErrors
    ): void {
        $existing = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $before = $existing->fresh()->getRawOriginal();

        $input = [
            'name' => '確認ユーザー',
            'email' => 'new@example.com',
            'password' => 'Example123!',
            'password_confirmation' => 'Example123!',
            'extra' => 'untrusted-value',
        ];

        unset($input[$field]);

        $this->assertGuest('web');

        $this->from('/register')
            ->post('/register', $input)
            ->assertRedirect('/register')
            ->assertSessionHasErrors($expectedErrors)
            ->assertSessionHas('_old_input', [
                'name' => $input['name'] ?? '',
                'email' => $input['email'] ?? '',
            ]);

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
            $existing->fresh()->getRawOriginal()
        );
    }

    public static function 必須項目の未送信例(): array
    {
        return [
            '名前の未送信' => [
                'name',
                ['name' => '名前を入力してください。'],
            ],
            'メールの未送信' => [
                'email',
                ['email' => 'メールアドレスを入力してください。'],
            ],
            'パスワードの未送信' => [
                'password',
                ['password' => 'パスワードを入力してください。'],
            ],
            '確認用パスワードの未送信' => [
                'password_confirmation',
                [
                    'password' => 'パスワードと確認用の入力が一致しません。',
                    'password_confirmation' => '確認用パスワードを入力してください。',
                ],
            ],
        ];
    }
}
