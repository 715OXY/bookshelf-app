<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingReportBasicStatsQueryEfficiencyTest extends TestCase
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

    public function test_取得済みレビューの集計で追加クエリを発行せず取得集計全体でも再取得しない(): void
    {
        $user = User::factory()->create();
        $service = app(ReadingReportService::class);
        $expectedIds = [];
        $previousSelects = null;

        foreach ([1, 9] as $additionalBooks) {
            $expectedIds = array_merge($expectedIds, $this->createReviews($user, $additionalBooks));

            [$baselineReviews, $baselineSelects, $baselineQueries] = $this->measureQueries($service, $user, false);
            [$reviews, $selects, $queries, $extraSelects, $extraQueries, $stats] = $this->measureQueries($service, $user, true);
            $bookCount = intdiv(count($expectedIds), 2);

            $this->assertEqualsCanonicalizing($expectedIds, $baselineReviews->modelKeys());
            $this->assertEqualsCanonicalizing($expectedIds, $reviews->modelKeys());
            $this->assertSame([$user->id], $reviews->pluck('user_id')->unique()->values()->all());
            $this->assertGreaterThan(0, $baselineSelects);
            $this->assertGreaterThan(0, $selects);
            $this->assertSame(0, $extraSelects, '集計時にSELECTが追加されています。');
            $this->assertSame(0, $extraQueries, '集計時にSQLが追加されています。');
            $this->assertSame($baselineSelects, $selects);
            $this->assertSame($baselineQueries, $queries);
            $this->assertSame([
                'total_reviews' => count($expectedIds),
                'books_read' => $bookCount,
                'average_rating' => 3.0,
            ], $stats['summary']);
            $this->assertSame([$bookCount, 0, 0, 0, $bookCount], $stats['rating_distribution']->all());
            $this->assertSame(count($expectedIds), $stats['rating_distribution']->sum());

            if ($previousSelects !== null) {
                $this->assertSame($previousSelects, $selects, '書籍・レビュー増加でSELECT数が増えています。');
            }

            $previousSelects = $selects;
        }
    }

    /**
     * 計測範囲外で、各書籍に評価1・5の本人レビューを作成する。
     *
     * @return array<int, int>
     */
    private function createReviews(User $user, int $bookCount): array
    {
        return collect(range(1, $bookCount))
            ->flatMap(function () use ($user): array {
                $book = Book::factory()->for($user)->create();

                return collect([1, 5])
                    ->map(fn (int $rating): int => Review::factory()->for($user)->for($book)
                        ->create(['rating' => $rating])->id)
                    ->all();
            })
            ->all();
    }

    /**
     * 取得直後と集計直後を区切り、SELECTと全SQLを計測してDispatcherを復元する。
     * falseは取得のみの基準計測であり、Serviceへ設定を渡すものではない。
     *
     * @return array{Collection<int, Review>, int, int, int, int, array|null}
     */
    private function measureQueries(ReadingReportService $service, User $user, bool $aggregate): array
    {
        $connection = $user->getConnection();
        $this->assertSame(DB::connection(), $connection);
        $this->assertSame($connection, $user->reviews()->getQuery()->getConnection());
        $this->assertSame($connection, (new Book)->getConnection());
        $this->assertSame($connection, (new Genre)->getConnection());
        $originalDispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($originalDispatcher);
        $dispatcher = clone $originalDispatcher;
        $selectCount = 0;
        $queryCount = 0;

        $dispatcher->listen(
            QueryExecuted::class,
            function (QueryExecuted $event) use (&$selectCount, &$queryCount, $connection): void {
                $this->assertSame($connection, $event->connection);
                $queryCount++;

                if (preg_match('/^\\s*select\\b/i', $event->sql) === 1) {
                    $selectCount++;
                }
            }
        );

        try {
            $connection->setEventDispatcher($dispatcher);
            $reviews = $service->getReviewsFor($user);
            $retrievalSelects = $selectCount;
            $retrievalQueries = $queryCount;
            $stats = $aggregate ? $service->getBasicStats($reviews) : null;
            $extraSelects = $selectCount - $retrievalSelects;
            $extraQueries = $queryCount - $retrievalQueries;

            $reviews->each(function (Review $review) use ($connection): void {
                $this->assertSame($connection, $review->getConnection());
                $this->assertTrue($review->relationLoaded('book'));
                $book = $review->getRelation('book');
                $this->assertInstanceOf(Book::class, $book);
                $this->assertTrue($book->relationLoaded('genres'));
            });

            return [$reviews, $selectCount, $queryCount, $extraSelects, $extraQueries, $stats];
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
    }
}
