<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class ReviewLikeTransactionTest extends TestCase
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

    #[DataProvider('途中で失敗させる操作')]
    public function test_途中失敗時は変更を戻し成功扱いにしない(
        bool $registered,
        string $targetSql
    ): void {
        config(['app.debug' => false]);

        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $review = Review::factory()->create();
        $otherReview = Review::factory()->create();

        // 操作対象外の関連も準備する。
        $otherUser->likedReviews()->attach($review->id);
        $user->likedReviews()->attach($otherReview->id);

        if ($registered) {
            $user->likedReviews()->attach($review->id);
        }

        $before = DB::table('review_likes')
            ->orderBy('id')->get()->toArray();
        $countBefore = $review->likedByUsers()->count();

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
                        'review-like-transaction-test-failure'
                    );
                }
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $response = $this->actingAs($user, 'web')
                ->post(route('reviews.like', $review));
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
            $before,
            DB::table('review_likes')
                ->orderBy('id')->get()->toArray()
        );
        $this->assertSame(
            $countBefore,
            $review->likedByUsers()->count()
        );
    }

    /**
     * @return array<string, array{bool, string}>
     */
    public static function 途中で失敗させる操作(): array
    {
        return [
            '追加途中' => [false, 'insert into review_likes '],
            '解除途中' => [true, 'delete from review_likes '],
        ];
    }
}
