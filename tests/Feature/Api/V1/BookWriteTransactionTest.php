<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

class BookWriteTransactionTest extends BookWriteTestCase
{
    #[DataProvider('failedOperations')]
    public function test_書き込み途中の障害では部分変更を残さない(
        string $method,
        string $path,
        string $targetSql
    ): void {
        config(['app.debug' => true]);

        $payload = $this->validPayload();

        $owner = User::findOrFail($payload['user_id']);
        $otherUser = User::factory()->create();
        $oldGenre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'isbn' => '9781234567891',
        ]);

        $book->genres()->sync([$oldGenre->id]);
        $book->favoritedByUsers()->sync([$otherUser->id]);

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $otherUser->id,
            'rating' => 4,
        ]);

        $review->likedByUsers()->sync([$owner->id]);

        Book::factory()->create([
            'user_id' => $otherUser->id,
            'isbn' => '9781234567892',
        ]);

        // 登録者IDは入力に含めず、認証会員から決定する。
        unset($payload['user_id']);

        if ($method === 'DELETE') {
            $payload = [];
        }

        $issued = $owner->createToken(
            'issue-69-transaction-test',
            ['*'],
            now()->addHour()
        );
        $this->withToken($issued->plainTextToken);

        $before = $this->bookSnapshot();

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
                        'API_TRANSACTION_TEST_INTERNAL_SECRET'
                    );
                }
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $uri = sprintf($path, $book->id);
            $response = match ($method) {
                'POST' => $this->post($uri, $payload),
                'PUT' => $this->put($uri, $payload),
                'DELETE' => $this->delete($uri),
            };
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertTrue(
            $injected,
            '障害注入対象のSQLに到達していません。'
        );

        $response
            ->assertStatus(500)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertExactJson([
                'message' => 'サーバー内部でエラーが発生しました。',
            ])
            ->assertDontSee('API_TRANSACTION_TEST_INTERNAL_SECRET');

        $this->assertGuest('web');
        $this->assertSame($before, $this->bookSnapshot());
    }

    public static function failedOperations(): array
    {
        return [
            '登録途中' => [
                'POST',
                self::URI,
                'insert into book_genres ',
            ],
            '更新途中' => [
                'PUT',
                self::URI.'/%d',
                'insert into book_genres ',
            ],
            '削除途中' => [
                'DELETE',
                self::URI.'/%d',
                'delete from books ',
            ],
        ];
    }
}
