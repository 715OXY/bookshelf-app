<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class BookTransactionTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('途中で失敗させる操作')]
    public function test_途中失敗時は部分変更を戻し成功通知を出さない(
        string $method,
        string $path,
        string $targetSql
    ): void {
        config(['app.debug' => false]);

        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $genres = Genre::factory()->count(3)->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'isbn' => '9781234567890',
        ]);

        $book->genres()->sync([$genres[0]->id]);
        $book->favoritedByUsers()->sync([$otherUser->id]);

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $otherUser->id,
            'rating' => 4,
        ]);
        $review->likedByUsers()->sync([$owner->id]);

        // 操作対象外のデータも変わらないことを比較する。
        Book::factory()->create([
            'user_id' => $otherUser->id,
            'isbn' => '9781234567891',
        ]);

        $payload = [
            'title' => 'ロールバック検証用',
            'author' => 'テスト著者',
            'isbn' => '9781234567892',
            'published_date' => '2026-11-01',
            'description' => '更新途中の値',
            'image_url' => null,
            'genres' => [$genres[1]->id, $genres[2]->id],
        ];

        $before = $this->DB状態();

        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        $this->assertNotNull($originalDispatcher);

        // 元のイベント登録を残し、テスト用の監視だけを追加する。
        $dispatcher = clone $originalDispatcher;
        $injected = false;

        $dispatcher->listen(
            QueryExecuted::class,
            function (QueryExecuted $event) use ($targetSql, &$injected): void {
                $sql = strtolower(str_replace(['`', '"'], '', $event->sql));

                if (! $injected && str_starts_with($sql, $targetSql)) {
                    $injected = true;

                    throw new RuntimeException(
                        'bookshelf-transaction-test-failure'
                    );
                }
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $response = $this->actingAs($owner, 'web')
                ->call($method, sprintf($path, $book->id), $payload);
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        // 意図したDB変更まで到達したことを確認する。
        $this->assertTrue(
            $injected,
            '対象の更新クエリに到達せず、別の原因で失敗しています。'
        );

        $response
            ->assertStatus(500)
            ->assertHeaderMissing('Location')
            ->assertSessionMissing('success');

        $this->assertSame($before, $this->DB状態());
    }

    public static function 途中で失敗させる操作(): array
    {
        return [
            '登録途中' => [
                'POST',
                '/books',
                'insert into book_genres ',
            ],
            '更新途中' => [
                'PUT',
                '/books/%d',
                'insert into book_genres ',
            ],
            '削除途中' => [
                'DELETE',
                '/books/%d',
                'delete from books ',
            ],
        ];
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function DB状態(): array
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
