<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class GenreTransactionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('failedOperations')]
    public function test_障害時はジャンルの変更を戻して内部情報を公開しない(
        string $method,
        string $targetSql
    ): void {
        config(['app.debug' => false]);

        $user = User::factory()->create();

        $genre = Genre::factory()->create([
            'name' => '変更前ジャンル',
        ]);

        $otherGenre = Genre::factory()->create();
        $book = Book::factory()->create();
        $book->genres()->attach($otherGenre->id);

        $url = match ($method) {
            'POST' => route('genres.store'),
            'PUT' => route('genres.update', $genre),
            'DELETE' => route('genres.destroy', $genre),
        };

        $payload = $method === 'DELETE'
            ? []
            : ['name' => '障害検証ジャンル'];

        $before = $this->databaseSnapshot();

        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        $this->assertNotNull($originalDispatcher);

        $dispatcher = clone $originalDispatcher;
        $injected = false;

        $dispatcher->listen(
            QueryExecuted::class,
            function (QueryExecuted $event) use ($targetSql, &$injected): void {
                $sql = strtolower(str_replace(['`', '"'], '', $event->sql));

                if (! $injected && str_starts_with($sql, $targetSql)) {
                    $injected = true;

                    throw new RuntimeException(
                        'GENRE_TRANSACTION_TEST_INTERNAL_SECRET'
                    );
                }
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $response = $this->actingAs($user, 'web')
                ->call($method, $url, $payload);
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertTrue(
            $injected,
            '障害注入対象のSQLに到達していません。'
        );

        $response
            ->assertStatus(500)
            ->assertHeaderMissing('Location')
            ->assertSessionMissing('success')
            ->assertDontSee('GENRE_TRANSACTION_TEST_INTERNAL_SECRET')
            ->assertDontSee('SQLSTATE');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertSame($before, $this->databaseSnapshot());
    }

    public static function failedOperations(): array
    {
        return [
            '登録途中' => ['POST', 'insert into genres '],
            '更新途中' => ['PUT', 'update genres set '],
            '削除途中' => ['DELETE', 'delete from genres '],
        ];
    }

    private function databaseSnapshot(): array
    {
        $snapshot = [];

        foreach ([
            'users',
            'books',
            'genres',
            'reviews',
            'book_genres',
            'favorites',
            'review_likes',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
