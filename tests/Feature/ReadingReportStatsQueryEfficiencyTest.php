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
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingReportStatsQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $connection = DB::connection();
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());
        $result = $connection->selectOne('SELECT DATABASE() AS database_name');
        $this->assertSame('testing', $result->database_name);
    }

    // Q01
    public function test_四統計の集計は追加クエリを発行せずデータ増加後も再取得しない(): void
    {
        $user = User::factory()->create();
        $service = app(ReadingReportService::class);
        $ids = $books = $genres = [];
        $previous = null;

        foreach ([1 => 1, 5 => 4, 10 => 5] as $bookCount => $additional) {
            [$newIds, $newBooks, $newGenres] = $this->createReviews($user, count($books) + 1, $additional);
            $ids = array_merge($ids, $newIds);
            $books = array_merge($books, $newBooks);
            $genres = array_merge($genres, $newGenres);

            $this->assertCount($bookCount, $books);
            $this->assertCount($bookCount * 2, $ids);
            $this->assertCount($bookCount * 2, $genres);

            [$retrieved, $retrievalSelects, $retrievalQueries] = $this->measure($user, fn () => $service->getReviewsFor($user));
            [$stats, $extraSelects, $extraQueries] = $this->measure($user, fn () => $service->getStats($retrieved));
            [$combined, $selects, $queries] = $this->measure($user, function () use ($service, $user): array {
                $reviews = $service->getReviewsFor($user);

                return [$reviews, $service->getStats($reviews)];
            });

            $this->assertReviews($retrieved, $user, $ids);
            $this->assertReviews($combined[0], $user, $ids);
            $this->assertGreaterThan(0, $retrievalSelects);
            $this->assertGreaterThan(0, $selects);
            $this->assertSame(0, $extraSelects, '集計単独でSELECTが追加されています。');
            $this->assertSame(0, $extraQueries, '集計単独でSQLが追加されています。');
            $this->assertSame($retrievalSelects, $selects, '取得＋集計でSELECTが増えています。');
            $this->assertSame($retrievalQueries, $queries, '取得＋集計でSQLが増えています。');
            $this->assertStats($stats, $books, $genres);
            $this->assertStats($combined[1], $books, $genres);
            if ($previous !== null) {
                $this->assertSame($previous, [$retrievalSelects, $retrievalQueries], 'データ増加で取得SQLが増えています。');
            }
            $previous = [$retrievalSelects, $retrievalQueries];
        }
    }

    /**
     * @param  Collection<int, Review>  $reviews
     * @param  list<int>  $ids
     */
    private function assertReviews(Collection $reviews, User $user, array $ids): void
    {
        $this->assertCount(count($ids), $reviews);
        $this->assertEqualsCanonicalizing($ids, $reviews->modelKeys());
        foreach ($reviews as $review) {
            $this->assertSame($user->id, $review->user_id);
            $this->assertTrue($review->relationLoaded('book'));
            $book = $review->getRelation('book');
            $this->assertInstanceOf(Book::class, $book);
            $this->assertTrue($book->relationLoaded('genres'));
            $this->assertCount(2, $book->getRelation('genres'));
        }
    }

    /**
     * 計測外で評価5・4と、各書籍に異なる2ジャンルを追加する。
     *
     * @return array{list<int>, list<Book>, list<Genre>}
     */
    private function createReviews(User $user, int $first, int $count): array
    {
        $ids = [];
        $books = [];
        $genres = [];
        foreach (range($first, $first + $count - 1) as $number) {
            $book = Book::factory()->for($user)->create([
                'title' => '計測書籍'.$number, 'author' => '計測著者'.$number,
                'isbn' => sprintf('978000000%04d', $number), 'published_date' => '2026-11-01',
            ]);
            $firstGenre = Genre::factory()->create(['name' => '計測ジャンル'.$number.'A']);
            $secondGenre = Genre::factory()->create(['name' => '計測ジャンル'.$number.'B']);
            $book->genres()->sync([$firstGenre->id, $secondGenre->id]);
            $books[] = $book;
            array_push($genres, $firstGenre, $secondGenre);
            foreach ([5, 4] as $rating) {
                $ids[] = Review::factory()->for($user)->for($book)->create(['rating' => $rating])->id;
            }
        }

        return [$ids, $books, $genres];
    }

    /**
     * Fixtureから独立に指定した評価5・4の期待値と、完全な4統計契約を照合する。
     *
     * @param  array<string, mixed>  $stats
     * @param  list<Book>  $books
     * @param  list<Genre>  $genres
     */
    private function assertStats(array $stats, array $books, array $genres): void
    {
        $count = count($books);
        $this->assertSame(['summary', 'rating_distribution', 'top_rated_books', 'genre_ratings'], array_keys($stats));
        $this->assertSame(['total_reviews' => $count * 2, 'books_read' => $count, 'average_rating' => 4.5], $stats['summary']);
        $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        $this->assertSame([0, 0, 0, $count, $count], $stats['rating_distribution']->all());
        $this->assertSame($count * 2, $stats['rating_distribution']->sum());
        $this->assertTrue(array_is_list($stats['top_rated_books']));
        $this->assertTrue(array_is_list($stats['genre_ratings']));
        // Fixtureは数値ID昇順に作成。全候補が同評価・同件数なので先頭5件が期待順位。
        $this->assertSame(array_map(fn (Book $book): array => [
            'id' => $book->id, 'title' => $book->title, 'author' => $book->author, 'rating' => 5,
        ], array_slice($books, 0, 5)), $stats['top_rated_books']);
        $this->assertSame(array_map(fn (Genre $genre): array => [
            'id' => $genre->id, 'name' => $genre->name, 'count' => 2, 'average_rating' => 4.5,
        ], array_slice($genres, 0, 5)), $stats['genre_ratings']);
    }

    /**
     * 対象接続の処理だけを計測し、例外時も元のDispatcherへ戻す。
     * Dispatcher不在時は設置前に失敗させ、計測不能を0件成功にしない。
     *
     * @param  callable(): mixed  $operation
     * @return array{mixed, int, int}
     */
    private function measure(User $user, callable $operation): array
    {
        $connection = $user->getConnection();
        $this->assertSame(DB::connection(), $connection);
        $this->assertSame($connection, $user->reviews()->getQuery()->getConnection());
        foreach ([new Review, new Book, new Genre] as $model) {
            $this->assertSame($connection, $model->getConnection());
        }
        $originalDispatcher = $connection->getEventDispatcher();
        $this->assertNotNull($originalDispatcher);
        $dispatcher = clone $originalDispatcher;
        $selects = 0;
        $queries = 0;
        $dispatcher->listen(QueryExecuted::class, function (QueryExecuted $event) use ($connection, &$selects, &$queries): void {
            if ($event->connection !== $connection) {
                return;
            }
            $queries++;
            if (preg_match('/^\s*select\b/i', $event->sql) === 1) {
                $selects++;
            }
        });

        try {
            $connection->setEventDispatcher($dispatcher);
            $result = $operation();

            return [$result, $selects, $queries];
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }
    }
}
