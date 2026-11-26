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

class ReadingReportQueryEfficiencyTest extends TestCase
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

    public function test_レビューと書籍が増えても取得クエリ数が増えず関連参照で追加取得しない(): void
    {
        $user = User::factory()->create();
        $service = app(ReadingReportService::class);
        $expectedIds = $this->createMeasurementReviews($user, 1, 1);

        [$fewReviews, $fewSelects, $fewAdditionalSelects] = $this->measureReviewQueries(
            $service,
            $user
        );

        $this->assertEqualsCanonicalizing($expectedIds, $fewReviews->modelKeys());

        $expectedIds = array_merge(
            $expectedIds,
            $this->createMeasurementReviews($user, 2, 9)
        );

        [$manyReviews, $manySelects, $manyAdditionalSelects] = $this->measureReviewQueries(
            $service,
            $user
        );

        $this->assertEqualsCanonicalizing($expectedIds, $manyReviews->modelKeys());
        $this->assertSame([$user->id], $manyReviews->pluck('user_id')->unique()->values()->all());
        $this->assertGreaterThan(0, $fewSelects);
        $this->assertSame($fewSelects, $manySelects, '件数増加で取得SELECT数が増えています。');
        $this->assertSame(0, $fewAdditionalSelects, '少数データの関連参照で追加SELECTが発生しました。');
        $this->assertSame(0, $manyAdditionalSelects, '増加後の関連参照で追加SELECTが発生しました。');
    }

    /**
     * 計測範囲の外で、複数ジャンルの書籍と各2件のレビューを作成する。
     *
     * @return array<int, int>
     */
    private function createMeasurementReviews(
        User $user,
        int $firstBookNumber,
        int $bookCount
    ): array {
        return collect(range($firstBookNumber, $firstBookNumber + $bookCount - 1))
            ->flatMap(function (int $number) use ($user): array {
                $book = Book::factory()->for($user)->create([
                    'title' => '計測用書籍'.$number,
                    'author' => '計測用著者'.$number,
                    'isbn' => sprintf('978000000%04d', $number),
                    'published_date' => '2026-11-01',
                ]);
                $firstGenre = Genre::factory()->create(['name' => '計測ジャンル'.$number.'A']);
                $secondGenre = Genre::factory()->create(['name' => '計測ジャンル'.$number.'B']);

                $book->genres()->sync([$firstGenre->id, $secondGenre->id]);

                return collect([1, 5])
                    ->map(fn (int $rating): int => Review::factory()->for($user)->for($book)
                        ->create(['rating' => $rating])->id)
                    ->all();
            })
            ->all();
    }

    /**
     * 取得と関連参照のSELECT数を分けて計測し、接続のDispatcherを復元する。
     *
     * @return array{Collection<int, Review>, int, int}
     */
    private function measureReviewQueries(ReadingReportService $service, User $user): array
    {
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        $this->assertNotNull($originalDispatcher);

        $dispatcher = clone $originalDispatcher;
        $selectCount = 0;

        $dispatcher->listen(
            QueryExecuted::class,
            function (QueryExecuted $event) use (&$selectCount): void {
                if (preg_match('/^\\s*select\\b/i', $event->sql) === 1) {
                    $selectCount++;
                }
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $reviews = $service->getReviewsFor($user);
            $retrievalSelects = $selectCount;

            $reviews->each(function (Review $review): void {
                $this->assertTrue($review->relationLoaded('book'));
                $book = $review->book;

                $this->assertInstanceOf(Book::class, $book);
                $this->assertNotEmpty($book->title);
                $this->assertTrue($book->relationLoaded('genres'));
                $this->assertCount(2, $book->genres);

                $book->genres->each(function (Genre $genre): void {
                    $this->assertNotEmpty($genre->name);
                });
            });

            $additionalSelects = $selectCount - $retrievalSelects;
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        return [$reviews, $retrievalSelects, $additionalSelects];
    }
}
