<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthInvalidFormDisplayTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    #[DataProvider('不正なフォーム入力')]
    public function test_不正入力後も認証フォームを正常に再表示する(
        string $url,
        array $changes,
        string $errorField,
        string $message,
        array $expectedValues
    ): void {
        $user = User::factory()->create([
            'email' => 'auth@example.com',
            'password' => Hash::make('Example123!'),
        ]);

        $before = $user->fresh()->getRawOriginal();

        $input = $url === '/register'
            ? [
                'name' => '　確認ユーザー　',
                'email' => ' NEW@example.com ',
                'password' => 'Example123!',
                'password_confirmation' => 'Example123!',
            ]
            : [
                'email' => ' AUTH@example.com ',
                'password' => 'Example123!',
            ];

        $input = array_replace($input, $changes);
        $input['extra'] = 'untrusted-value';

        $this->from($url)
            ->post($url, $input)
            ->assertRedirect($url)
            ->assertSessionHasErrors([$errorField => $message])
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.extra');

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $user->fresh()->getRawOriginal());

        $form = $this->get($url)
            ->assertOk()
            ->assertSeeText($message);

        $document = new DOMDocument;
        @$document->loadHTML(
            '<?xml encoding="UTF-8">'.$form->getContent()
        );

        $xpath = new DOMXPath($document);

        foreach ($expectedValues as $field => $expected) {
            $selector = '//input[@name="'.$field.'"]';

            $this->assertSame(
                1,
                $xpath->query($selector)->length
            );

            $this->assertSame(
                $expected,
                $xpath->evaluate('string('.$selector.'/@value)')
            );
        }

        $passwordFields = $url === '/register'
            ? ['password', 'password_confirmation']
            : ['password'];

        foreach ($passwordFields as $field) {
            $selector = '//input[@name="'.$field.'"]';

            $this->assertSame(
                1,
                $xpath->query($selector)->length
            );

            $this->assertSame(
                '',
                $xpath->evaluate('string('.$selector.'/@value)')
            );
        }

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame($before, $user->fresh()->getRawOriginal());
    }

    public static function 不正なフォーム入力(): array
    {
        return [
            'ログインでメールが配列' => [
                '/login',
                ['email' => ['auth@example.com']],
                'email',
                'メールアドレスは文字列で入力してください。',
                ['email' => ''],
            ],
            '会員登録で名前が配列' => [
                '/register',
                ['name' => ['確認ユーザー']],
                'name',
                '名前は文字列で入力してください。',
                ['name' => '', 'email' => 'new@example.com'],
            ],
            '会員登録でメールが配列' => [
                '/register',
                ['email' => ['new@example.com']],
                'email',
                'メールアドレスは文字列で入力してください。',
                ['name' => '確認ユーザー', 'email' => ''],
            ],
            'ログインで認証情報が不一致' => [
                '/login',
                ['password' => 'Wrong123!'],
                'email',
                'メールアドレスまたはパスワードが正しくありません。',
                ['email' => 'auth@example.com'],
            ],
            '会員登録でパスワードが短い' => [
                '/register',
                [
                    'password' => 'short',
                    'password_confirmation' => 'short',
                ],
                'password',
                'パスワードは8文字以上で入力してください。',
                ['name' => '確認ユーザー', 'email' => 'new@example.com'],
            ],
        ];
    }
}
