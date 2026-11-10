<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReviewTransactionTest extends TestCase
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

    public function test_削除途中の例外でレビューといいねが復元され成功通知が出ない(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create();

        $likers = User::factory()->count(3)->create();
        $review->likedByUsers()->attach($likers->modelKeys());

        $reviewBefore = DB::table('reviews')
            ->where('id', $review->id)
            ->first();

        $likesBefore = DB::table('review_likes')
            ->where('review_id', $review->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->assertCount(3, $likesBefore);

        $eventName = 'eloquent.deleted: '.Review::class;
        $failureInjected = false;

        Event::listen(
            $eventName,
            function (Review $deleted) use ($review, &$failureInjected): void {
                if ($deleted->id !== $review->id) {
                    return;
                }

                // 実際にレビューといいねが削除された後で例外を発生させる。
                $this->assertDatabaseMissing('reviews', [
                    'id' => $review->id,
                ]);
                $this->assertDatabaseMissing('review_likes', [
                    'review_id' => $review->id,
                ]);

                $failureInjected = true;

                throw new RuntimeException('レビュー削除失敗のテスト');
            }
        );

        try {
            $this->actingAs($user)
                ->delete(route('reviews.destroy', $review))
                ->assertStatus(500)
                ->assertSessionMissing('success');
        } finally {
            Event::forget($eventName);
        }

        $this->assertTrue($failureInjected);

        $this->assertEquals(
            $reviewBefore,
            DB::table('reviews')->where('id', $review->id)->first()
        );

        $this->assertEquals(
            $likesBefore,
            DB::table('review_likes')
                ->where('review_id', $review->id)
                ->orderBy('id')
                ->get()
                ->toArray()
        );
    }

    #[DataProvider('保存途中で失敗させる操作')]
    public function test_投稿と更新の途中失敗で変更を戻し成功通知を出さない(
        string $method,
        string $targetSql
    ): void {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create([
            'rating' => 2,
            'comment' => '変更前',
        ]);

        $user->likedReviews()->attach($review->id);

        $reviewsBefore = DB::table('reviews')
            ->orderBy('id')
            ->get()
            ->toArray();

        $likesBefore = DB::table('review_likes')
            ->orderBy('id')
            ->get()
            ->toArray();

        $url = $method === 'POST'
            ? route('reviews.store', ['book' => $review->book_id])
            : route('reviews.update', $review);

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
                        'review-save-transaction-test-failure'
                    );
                }
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $response = $this->actingAs($user, 'web')
                ->call($method, $url, [
                    'rating' => '5',
                    'comment' => '保存途中の内容',
                ]);
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        $this->assertTrue(
            $injected,
            '対象の書き込みに到達せず、別の原因で失敗しています。'
        );

        $response
            ->assertStatus(500)
            ->assertHeaderMissing('Location')
            ->assertSessionMissing('success');

        $this->assertEquals(
            $reviewsBefore,
            DB::table('reviews')->orderBy('id')->get()->toArray()
        );

        $this->assertEquals(
            $likesBefore,
            DB::table('review_likes')->orderBy('id')->get()->toArray()
        );
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function 保存途中で失敗させる操作(): array
    {
        return [
            '投稿途中' => ['POST', 'insert into reviews '],
            '更新途中' => ['PUT', 'update reviews set '],
        ];
    }
}
