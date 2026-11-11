<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Book;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FavoriteCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());

        $result = $connection->selectOne(
            'SELECT DATABASE() AS database_name'
        );

        $this->assertSame('testing', $result->database_name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            VerifyCsrfToken::class,
            EnforcedFavoriteCsrfMiddleware::class
        );
    }

    #[DataProvider('トークンと操作の組み合わせ')]
    public function test_有効なトークンの場合だけお気に入りを変更する(
        bool $registered,
        ?string $token,
        bool $valid
    ): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create();

        // 他ユーザーの関連が維持されることも確認する。
        $otherUser->favoriteBooks()->attach($book->id);

        if ($registered) {
            $user->favoriteBooks()->attach($book->id);
        }

        $before = DB::table('favorites')->orderBy('id')->get()->toArray();

        $payload = [];

        if ($token !== null) {
            $payload['_token'] = $token;
        }

        $response = $this->actingAs($user, 'web')
            ->withSession([
                '_token' => 'expected-favorite-csrf-token',
                'csrf_probe' => 'preserved',
            ])
            ->post(route('favorites.toggle', $book), $payload);

        $this->assertAuthenticatedAs($user, 'web');
        $response->assertSessionHas('csrf_probe', 'preserved');

        if (! $valid) {
            $response
                ->assertStatus(419)
                ->assertHeaderMissing('Location')
                ->assertSessionMissing('success');

            $this->assertEquals(
                $before,
                DB::table('favorites')->orderBy('id')->get()->toArray()
            );

            return;
        }

        $response
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success');

        $target = [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ];

        if ($registered) {
            $this->assertDatabaseMissing('favorites', $target);
        } else {
            $this->assertDatabaseHas('favorites', $target);
        }

        $this->assertDatabaseHas('favorites', [
            'user_id' => $otherUser->id,
            'book_id' => $book->id,
        ]);

        $this->assertDatabaseCount('favorites', $registered ? 1 : 2);
    }

    /**
     * @return array<string, array{bool, string|null, bool}>
     */
    public static function トークンと操作の組み合わせ(): array
    {
        $cases = [];

        foreach (['追加' => false, '解除' => true] as $name => $registered) {
            $cases[$name.'でトークンなし'] = [$registered, null, false];
            $cases[$name.'でトークン不一致'] = [
                $registered,
                'wrong-token',
                false,
            ];
            $cases[$name.'で有効トークン'] = [
                $registered,
                'expected-favorite-csrf-token',
                true,
            ];
        }

        return $cases;
    }
}

class EnforcedFavoriteCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
