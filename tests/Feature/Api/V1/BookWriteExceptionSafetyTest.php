<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

class BookWriteExceptionSafetyTest extends BookWriteTestCase
{
    #[DataProvider('unsafeOperations')]
    public function test_書き込み途中の不適切な例外応答を公開しない(
        string $method,
        string $type
    ): void {
        config(['app.debug' => true]);

        $payload = $this->validPayload();

        $owner = User::findOrFail($payload['user_id']);
        $reader = User::factory()->create();
        $oldGenre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
        ]);

        $book->genres()->sync([$oldGenre->id]);
        $book->favoritedByUsers()->sync([$reader->id]);

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $reader->id,
        ]);

        $review->likedByUsers()->sync([$owner->id]);

        Book::factory()->create();

        unset($payload['user_id']);
        $payload['isbn'] = $book->isbn;

        if ($method === 'DELETE') {
            $payload = [];
        }

        $targetSql = $method === 'PUT'
            ? 'insert into book_genres '
            : 'delete from books ';

        $before = $this->bookSnapshot();

        $connection = DB::connection();
        $original = $connection->getEventDispatcher();

        $this->assertNotNull($original);

        $dispatcher = clone $original;
        $injected = false;

        $dispatcher->listen(
            QueryExecuted::class,
            function (QueryExecuted $event) use (
                $targetSql,
                $type,
                &$injected
            ): void {
                $sql = strtolower(
                    str_replace(['`', '"'], '', $event->sql)
                );

                if ($injected || ! str_starts_with($sql, $targetSql)) {
                    return;
                }

                $injected = true;

                $response = match ($type) {
                    'html' => response(
                        '<html>API_UNSAFE_SECRET</html>',
                        500
                    ),
                    'json' => response()->json([
                        'message' => 'API_UNSAFE_SECRET',
                    ], 422),
                    'redirect' => redirect('/API_UNSAFE_SECRET'),
                };

                $response->headers->set(
                    'X-Internal-Secret',
                    'API_UNSAFE_SECRET'
                );

                throw new HttpResponseException($response);
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $response = $this->call(
                $method,
                self::URI.'/'.$book->id,
                $payload
            );
        } finally {
            $connection->setEventDispatcher($original);
        }

        $this->assertTrue(
            $injected,
            '障害注入対象のSQLに到達していません。'
        );

        $response
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertHeaderMissing('X-Internal-Secret')
            ->assertExactJson([
                'message' => 'サーバー内部でエラーが発生しました。',
            ])
            ->assertDontSee('API_UNSAFE_SECRET');

        $this->assertSame($before, $this->bookSnapshot());
        $this->assertGuest();
    }

    public static function unsafeOperations(): array
    {
        return [
            '更新・HTML応答' => ['PUT', 'html'],
            '更新・JSON応答' => ['PUT', 'json'],
            '更新・リダイレクト応答' => ['PUT', 'redirect'],
            '削除・HTML応答' => ['DELETE', 'html'],
            '削除・JSON応答' => ['DELETE', 'json'],
            '削除・リダイレクト応答' => ['DELETE', 'redirect'],
        ];
    }
}
