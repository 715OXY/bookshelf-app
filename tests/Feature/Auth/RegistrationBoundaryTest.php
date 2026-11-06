<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RegistrationBoundaryTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('登録できない入力例')]
    public function test_不正な登録入力は指定文言で拒否し保存しない(
        array $changes,
        array $expectedErrors
    ): void {
        $input = array_replace($this->正常な入力(), $changes);

        $this->from('/register')
            ->post('/register', $input)
            ->assertRedirect('/register')
            ->assertSessionHasErrors($expectedErrors);

        $this->assertSame(
            array_map(
                static fn (string $message): array => [$message],
                $expectedErrors
            ),
            session('errors')->getBag('default')->messages()
        );

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public static function 登録できない入力例(): array
    {
        return [
            '名前が空白のみ' => [
                ['name' => '　  　'],
                ['name' => '名前を入力してください。'],
            ],
            '名前が配列' => [
                ['name' => ['確認ユーザー']],
                ['name' => '名前は文字列で入力してください。'],
            ],
            '名前が256文字' => [
                ['name' => str_repeat('あ', 256)],
                ['name' => '名前は255文字以下で入力してください。'],
            ],
            'メールが配列' => [
                ['email' => ['new@example.com']],
                ['email' => 'メールアドレスは文字列で入力してください。'],
            ],
            'メール形式が不正' => [
                ['email' => 'invalid'],
                ['email' => 'メールアドレスは有効なメールアドレス形式で入力してください。'],
            ],
            'メールが256文字' => [
                ['email' => str_repeat('a', 244).'@example.com'],
                ['email' => 'メールアドレスは255文字以下で入力してください。'],
            ],
            'パスワードが配列' => [
                ['password' => ['Example123!']],
                ['password' => 'パスワードは文字列で入力してください。'],
            ],
            'パスワードが7文字' => [
                ['password' => 'Abc123!'],
                ['password' => 'パスワードは8文字以上で入力してください。'],
            ],
            'パスワードが65文字' => [
                ['password' => str_repeat('a', 65)],
                ['password' => 'パスワードは64文字以下で入力してください。'],
            ],
            'パスワードに半角空白を含む' => [
                ['password' => 'Example 123!'],
                ['password' => 'パスワードは空白を含まない半角英数字・記号で入力してください。'],
            ],
            'パスワードに全角文字を含む' => [
                ['password' => 'Example123あ'],
                ['password' => 'パスワードは空白を含まない半角英数字・記号で入力してください。'],
            ],
            '確認欄が配列' => [
                ['password_confirmation' => ['Example123!']],
                [
                    'password' => 'パスワードと確認用の入力が一致しません。',
                    'password_confirmation' => '確認用パスワードは文字列で入力してください。',
                ],
            ],
            '確認欄が65文字' => [
                ['password_confirmation' => str_repeat('a', 65)],
                [
                    'password' => 'パスワードと確認用の入力が一致しません。',
                    'password_confirmation' => '確認用パスワードは64文字以下で入力してください。',
                ],
            ],
        ];
    }

    #[DataProvider('登録できる境界値')]
    public function test_許容する境界値で登録できる(array $changes): void
    {
        $input = array_replace($this->正常な入力(), $changes);
        $input['password_confirmation'] = $input['password'];

        $this->post('/register', $input)
            ->assertSessionHasNoErrors()
            ->assertRedirect('/');

        $this->assertDatabaseCount('users', 1);

        $user = User::firstOrFail();

        $this->assertSame($input['name'], $user->name);
        $this->assertSame($input['email'], $user->email);
        $this->assertTrue(Hash::check($input['password'], $user->password));
        $this->assertAuthenticatedAs($user, 'web');
    }

    public static function 登録できる境界値(): array
    {
        $email255 = str_repeat('a', 64).'@'
            .str_repeat('b', 60).'.'
            .str_repeat('c', 60).'.'
            .str_repeat('d', 60).'.example';

        return [
            '名前が255文字' => [
                ['name' => str_repeat('あ', 255)],
            ],
            'メールが255文字' => [
                ['email' => $email255],
            ],
            'パスワードが8文字' => [
                ['password' => 'Abc123!x'],
            ],
            'パスワードが64文字' => [
                ['password' => str_repeat('a', 64)],
            ],
            '英字のみのパスワードも許容する' => [
                ['password' => 'abcdefgh'],
            ],
        ];
    }

    private function 正常な入力(): array
    {
        return [
            'name' => '確認ユーザー',
            'email' => 'new@example.com',
            'password' => 'Example123!',
            'password_confirmation' => 'Example123!',
        ];
    }
}
