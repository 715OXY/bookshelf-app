<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config(['app.url' => 'http://localhost']);

        Route::middleware(['web', 'auth:web'])
            ->get('/_test/protected', function () {
                return response('protected-page');
            })
            ->name('test.protected');

        Route::middleware(['web', 'auth:web'])
            ->post('/_test/post-only', function () {
                return response('post-only');
            });

        Route::middleware('web')
            ->get('/_test/public', function () {
                return response('public-page');
            });
    }

    public function test_ログイン後は元の認証必須_ge_t画面へクエリを保持して戻る(): void
    {
        $user = $this->ログイン用ユーザーを作成する();
        $destination = 'http://localhost/_test/protected?page=2&genre=3';

        $this->get($destination)
            ->assertRedirect('/login');

        $intended = session('url.intended');

        $this->assertIsString($intended);
        $this->assertSame('http', parse_url($intended, PHP_URL_SCHEME));
        $this->assertSame('localhost', parse_url($intended, PHP_URL_HOST));
        $this->assertSame(
            '/_test/protected',
            parse_url($intended, PHP_URL_PATH)
        );

        parse_str(
            (string) parse_url($intended, PHP_URL_QUERY),
            $query
        );

        ksort($query);

        $this->assertSame([
            'genre' => '3',
            'page' => '2',
        ], $query);

        $this->from('/login')
            ->post('/login', [
                'email' => 'auth@example.com',
                'password' => 'Example123!',
            ])
            ->assertRedirect($intended)
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user, 'web');

        $this->get($intended)
            ->assertOk()
            ->assertSee('protected-page');
    }

    public function test_認証失敗後も安全な戻り先を保持して再試行できる(): void
    {
        $user = $this->ログイン用ユーザーを作成する();
        $destination = 'http://localhost/_test/protected?page=2';

        $this->get($destination)
            ->assertRedirect('/login');

        $this->from('/login')
            ->post('/login', [
                'email' => 'auth@example.com',
                'password' => 'Wrong123!',
            ])
            ->assertRedirect('/login')
            ->assertSessionHasErrors([
                'email' => 'メールアドレスまたはパスワードが正しくありません。',
            ])
            ->assertSessionHas('url.intended', $destination);

        $this->assertGuest('web');

        $this->from('/login')
            ->post('/login', [
                'email' => 'auth@example.com',
                'password' => 'Example123!',
            ])
            ->assertRedirect($destination)
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user, 'web');
    }

    #[DataProvider('採用しない戻り先')]
    public function test_不適切な戻り先はトップ_ur_lへ戻す(mixed $intended): void
    {
        $user = $this->ログイン用ユーザーを作成する();

        $this->withSession(['url.intended' => $intended])
            ->post('/login', [
                'email' => 'auth@example.com',
                'password' => 'Example123!',
            ])
            ->assertRedirect('/')
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
    }

    public static function 採用しない戻り先(): array
    {
        return [
            '外部サイト' => ['https://example.com/_test/protected'],
            '異なるスキーム' => ['https://localhost/_test/protected'],
            '異なるポート' => ['http://localhost:8080/_test/protected'],
            'スキーム省略URL' => ['//example.com/_test/protected'],
            'ユーザー情報付きURL' => ['http://someone@localhost/_test/protected'],
            'フラグメント付きURL' => ['http://localhost/_test/protected#section'],
            '未定義ルート' => ['http://localhost/_test/missing'],
            '公開ページ' => ['http://localhost/_test/public'],
            'ログイン画面' => ['http://localhost/login'],
            '会員登録画面' => ['http://localhost/register'],
            'POST専用ルート' => ['http://localhost/_test/post-only'],
            'API' => ['http://localhost/api/user'],
            '配列' => [['http://localhost/_test/protected']],
        ];
    }

    #[DataProvider('認証済みでアクセスする認証ルート')]
    public function test_認証済みユーザーは認証ルートからトップ_ur_lへ戻る(
        string $method,
        string $path
    ): void {
        $user = $this->ログイン用ユーザーを作成する();

        $this->actingAs($user, 'web')
            ->call($method, $path)
            ->assertRedirect('/');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertDatabaseCount('users', 1);
    }

    public static function 認証済みでアクセスする認証ルート(): array
    {
        return [
            'ログイン画面表示' => ['GET', '/login'],
            'ログイン送信' => ['POST', '/login'],
            '会員登録画面表示' => ['GET', '/register'],
            '会員登録送信' => ['POST', '/register'],
        ];
    }

    private function ログイン用ユーザーを作成する(): User
    {
        return User::factory()->create([
            'email' => 'auth@example.com',
            'password' => Hash::make('Example123!'),
        ]);
    }
}
