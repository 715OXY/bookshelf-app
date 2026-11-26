<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingReportBasicStatsIntegrationTest extends TestCase
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

    // 集計単位・平均・丸めはユーザー承認済み暫定仕様に基づく。
    public function test_本人レビューだけを登録者やジャンル所属に関係なく集計する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $otherBook = Book::factory()->for($otherUser)->create();
        $ownBook = Book::factory()->for($user)->create();
        $ownBook->genres()->detach();
        $first = Review::factory()->for($user)->for($otherBook)->create(['rating' => 5]);
        $second = Review::factory()->for($user)->for($otherBook)->create(['rating' => 1]);
        $third = Review::factory()->for($user)->for($ownBook)->create(['rating' => 4]);
        Review::factory()->for($otherUser)->for($ownBook)->create(['rating' => 2]);
        $service = app(ReadingReportService::class);

        $reviews = $service->getReviewsFor($user);

        $this->assertEqualsCanonicalizing([$first->id, $second->id, $third->id], $reviews->modelKeys());
        $this->assertSame([$user->id], $reviews->pluck('user_id')->unique()->values()->all());
        $this->assertTrue($reviews->find($third->id)->book->genres->isEmpty());
        $this->assertBasicStats($service, $reviews, 3, 2, 10 / 3, [1, 0, 0, 1, 1]);
    }

    public function test_同じサービスで会員を切り替えても集計結果が混入しない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $emptyUser = User::factory()->create();
        $book = Book::factory()->for($otherUser)->create();
        $userReview = Review::factory()->for($user)->for($book)->create(['rating' => 1]);
        $otherReview = Review::factory()->for($otherUser)->for($book)->create(['rating' => 5]);
        $service = app(ReadingReportService::class);

        foreach ([
            [$user, [$userReview->id], 1, 1.0, [1, 0, 0, 0, 0]],
            [$otherUser, [$otherReview->id], 1, 5.0, [0, 0, 0, 0, 1]],
            [$user, [$userReview->id], 1, 1.0, [1, 0, 0, 0, 0]],
            [$emptyUser, [], 0, 0.0, [0, 0, 0, 0, 0]],
        ] as [$target, $ids, $books, $average, $distribution]) {
            $reviews = $service->getReviewsFor($target);

            $this->assertSame($ids, $reviews->modelKeys());
            $this->assertSame($ids === [] ? [] : [$target->id], $reviews->pluck('user_id')->unique()->all());
            $this->assertBasicStats($service, $reviews, count($ids), $books, $average, $distribution);
        }
    }

    public function test_レビュー追加更新削除後は再取得したデータから再計算する(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $service = app(ReadingReportService::class);
        $first = Review::factory()->for($user)->for($book)->create(['rating' => 5]);

        $this->assertBasicStats($service, $service->getReviewsFor($user), 1, 1, 5.0, [0, 0, 0, 0, 1]);

        $second = Review::factory()->for($user)->for($book)->create(['rating' => 1]);

        $this->assertBasicStats($service, $service->getReviewsFor($user), 2, 1, 3.0, [1, 0, 0, 0, 1]);

        $first->update(['rating' => 3]);

        $this->assertBasicStats($service, $service->getReviewsFor($user), 2, 1, 2.0, [1, 0, 1, 0, 0]);

        $first->delete();

        $this->assertBasicStats($service, $service->getReviewsFor($user), 1, 1, 1.0, [1, 0, 0, 0, 0]);

        $second->delete();

        $this->assertBasicStats($service, $service->getReviewsFor($user), 0, 0, 0.0, [0, 0, 0, 0, 0]);
    }

    /**
     * 取得済みの本人レビューから、承認済みの部分集計契約を確認する。
     *
     * @param  Collection<int, Review>  $reviews
     * @param  array<int, int>  $distribution
     */
    private function assertBasicStats(
        ReadingReportService $service,
        Collection $reviews,
        int $totalReviews,
        int $booksRead,
        float $averageRating,
        array $distribution
    ): void {
        $stats = $service->getBasicStats($reviews);

        $this->assertSame(['summary', 'rating_distribution'], array_keys($stats));
        $this->assertSame($totalReviews, $stats['summary']['total_reviews']);
        $this->assertSame($booksRead, $stats['summary']['books_read']);
        $this->assertIsFloat($stats['summary']['average_rating']);
        $this->assertEqualsWithDelta($averageRating, $stats['summary']['average_rating'], 1.0E-12);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        $this->assertSame($distribution, $stats['rating_distribution']->all());
        $this->assertSame($totalReviews, $stats['rating_distribution']->sum());
    }
}
