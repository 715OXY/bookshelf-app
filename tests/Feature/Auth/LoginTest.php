<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_未認証ユーザーがログイン画面を表示できる(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('メールアドレス')
            ->assertSee('パスワード')
            ->assertSee('会員登録');
    }

    public function test_未入力でログインすると必須エラーを返す(): void
    {
        $this->from('/login')
            ->post('/login', [])
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'email' => 'メールアドレスを入力してください。',
                'password' => 'パスワードを入力してください。',
            ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_メール形式不正時はメールのみ保持する(): void
    {
        $this->from('/login')
            ->post('/login', [
                'email' => 'invalid',
                'password' => 'Example123!',
                'extra' => 'untrusted-value',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'email' => 'メールアドレスは有効なメールアドレス形式で入力してください。',
            ])
            ->assertSessionHas('_old_input', [
                'email' => 'invalid',
            ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_メールを正規化してログインし永続ログインは有効にしない(): void
    {
        $user = $this->ログイン用ユーザーを作成する();

        $this->from('/login')
            ->post('/login', [
                'email' => ' AUTH@example.com ',
                'password' => 'Example123!',
                'remember' => true,
            ])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors()
            ->assertCookieMissing(
                Auth::guard('web')->getRecallerName()
            );

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
        $this->assertNull($user->fresh()->remember_token);
    }

    #[DataProvider('認証不一致の入力例')]
    public function test_認証不一致時は共通文言とメールのみ保持する(
        string $email,
        string $password
    ): void {
        $user = $this->ログイン用ユーザーを作成する();
        $before = $user->fresh()->getRawOriginal();

        $this->from('/login')
            ->post('/login', [
                'email' => $email,
                'password' => $password,
                'remember' => true,
                'extra' => 'untrusted-value',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ])
            ->assertSessionHas('_old_input', [
                'email' => $email,
            ]);

        $this->assertGuest('web');
        $this->assertDatabaseCount('users', 1);
        $this->assertSame(
            $before,
            $user->fresh()->getRawOriginal()
        );
    }

    public static function 認証不一致の入力例(): array
    {
        return [
            'パスワード誤り' => [
                'auth@example.com',
                'Wrong123!',
            ],
            '未登録メール' => [
                'missing@example.com',
                'Example123!',
            ],
        ];
    }

    public function test_連続して認証に失敗しても正しい情報でログインできる(): void
    {
        $user = $this->ログイン用ユーザーを作成する();

        for ($attempt = 0; $attempt < 6; $attempt++) {
            $this->from('/login')
                ->post('/login', [
                    'email' => 'auth@example.com',
                    'password' => 'Wrong123!',
                ])
                ->assertRedirect('/login')
                ->assertSessionHasErrors([
                    'email' => 'メールアドレスまたはパスワードが正しくありません。',
                ]);

            $this->assertGuest('web');
        }

        $this->from('/login')
            ->post('/login', [
                'email' => 'auth@example.com',
                'password' => 'Example123!',
            ])
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
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
