<?php

namespace Tests\Feature\Auth;

use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthBusinessRedirectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        config(['app.url' => 'http://localhost']);
    }

    #[DataProvider('復帰対象の業務画面')]
    public function test_ログイン後に業務画面へ復帰して認可を適用する(
        string $routeName,
        bool $otherOwner,
        int $expectedStatus
    ): void {
        $user = User::factory()->create([
            'email' => 'auth@example.com',
            'password' => Hash::make('Example123!'),
            'remember_token' => null,
        ]);

        $parameters = [];

        if ($routeName === 'books.edit') {
            $owner = $otherOwner
                ? User::factory()->create()
                : $user;

            $book = Book::factory()->for($owner)->create();

            $parameters['book'] = $book;
        }

        if ($routeName === 'favorites.index') {
            $parameters['page'] = 1;
        }

        $destination = route($routeName, $parameters);

        $before = $user->fresh()->getRawOriginal();

        $this->get($destination)
            ->assertRedirect(route('login'))
            ->assertSessionHas('url.intended', $destination);

        $this->assertGuest('web');

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => 'auth@example.com',
                'password' => 'Example123!',
            ])
            ->assertRedirect($destination)
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('url.intended');

        $this->assertAuthenticatedAs($user, 'web');

        $this->get($destination)
            ->assertStatus($expectedStatus);

        $this->assertAuthenticatedAs($user, 'web');

        $this->assertSame(
            $before,
            $user->fresh()->getRawOriginal()
        );
    }

    public static function 復帰対象の業務画面(): array
    {
        return [
            '書籍登録画面' => ['books.create', false, 200],
            'お気に入り一覧' => ['favorites.index', false, 200],
            'ジャンル一覧' => ['genres.index', false, 200],
            '本人の書籍編集画面' => ['books.edit', false, 200],
            '他会員の書籍編集画面' => ['books.edit', true, 403],
        ];
    }
}
