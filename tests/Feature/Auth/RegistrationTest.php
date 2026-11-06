<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_未認証ユーザーが会員登録画面を表示できる(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('お名前')
            ->assertSee('メールアドレス')
            ->assertSee('パスワード確認');
    }

    public function test_会員登録で入力を正規化して保存し自動ログインする(): void
    {
        $response = $this
            ->withSession([
                'url.intended' => 'http://localhost/unused',
            ])
            ->post('/register', [
                'name' => '　認証確認ユーザー　',
                'email' => ' NEW@example.com ',
                'password' => 'Example123!',
                'password_confirmation' => 'Example123!',
                'remember' => true,
                'two_factor_secret' => 'untrusted-value',
            ]);

        $response
            ->assertRedirect('/')
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('url.intended');

        $this->assertDatabaseCount('users', 1);

        $user = User::firstOrFail();

        $this->assertSame('認証確認ユーザー', $user->name);
        $this->assertSame('new@example.com', $user->email);
        $this->assertNotSame('Example123!', $user->password);
        $this->assertTrue(Hash::check('Example123!', $user->password));
        $this->assertNull($user->remember_token);
        $this->assertNull($user->two_factor_secret);
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_未入力で登録すると必須項目の日本語エラーが表示される(): void
    {
        $response = $this->from('/register')->post('/register', []);

        $response
            ->assertRedirect('/register')
            ->assertSessionHasErrors([
                'name' => '名前を入力してください。',
                'email' => 'メールアドレスを入力してください。',
                'password' => 'パスワードを入力してください。',
                'password_confirmation' => '確認用パスワードを入力してください。',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_登録失敗時は名前とメールのみ保持してユーザーを作成しない(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => '　確認ユーザー　',
            'email' => ' NEW@example.com ',
            'password' => 'short',
            'password_confirmation' => 'short',
            'extra' => 'untrusted-value',
        ]);

        $response
            ->assertRedirect('/register')
            ->assertSessionHasErrors([
                'password' => 'パスワードは8文字以上で入力してください。',
            ])
            ->assertSessionHasInput('name', '確認ユーザー')
            ->assertSessionHasInput('email', 'new@example.com')
            ->assertSessionMissing('_old_input.password')
            ->assertSessionMissing('_old_input.password_confirmation')
            ->assertSessionMissing('_old_input.extra');

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }

    public function test_メール重複時はユーザーを追加せず既存データも変更しない(): void
    {
        $existing = User::factory()->create([
            'email' => 'existing@example.com',
        ]);

        $before = $existing->fresh()->getRawOriginal();

        $response = $this->from('/register')->post('/register', [
            'name' => '別のユーザー',
            'email' => 'EXISTING@example.com',
            'password' => 'Example123!',
            'password_confirmation' => 'Example123!',
        ]);

        $response
            ->assertRedirect('/register')
            ->assertSessionHasErrors([
                'email' => 'このメールアドレスは既に登録されています。',
            ]);

        $this->assertDatabaseCount('users', 1);
        $this->assertSame(
            $before,
            $existing->fresh()->getRawOriginal()
        );
        $this->assertGuest('web');
    }

    public function test_パスワード確認不一致時はエラーを返してユーザーを作成しない(): void
    {
        $response = $this->from('/register')->post('/register', [
            'name' => '確認ユーザー',
            'email' => 'new@example.com',
            'password' => 'Example123!',
            'password_confirmation' => 'Different123!',
        ]);

        $response
            ->assertRedirect('/register')
            ->assertSessionHasErrors([
                'password' => 'パスワードと確認用の入力が一致しません。',
            ]);

        $this->assertDatabaseCount('users', 0);
        $this->assertGuest('web');
    }
}
