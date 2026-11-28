<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use App\Services\ReadingReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection as SupportCollection;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingReportStatsIntegrationTest extends TestCase
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

    // 集計単位・代表評価・副ソートはユーザー承認済み暫定仕様に基づく。
    public function test_本人レビューを登録者に関係なく四統計へ反映する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $novel = Genre::factory()->create(['name' => '小説']);
        $mystery = Genre::factory()->create(['name' => 'ミステリー']);
        $bookA = Book::factory()->for($otherUser)->create(['title' => '書籍A', 'author' => '著者A']);
        $bookB = Book::factory()->for($user)->create(['title' => '書籍B', 'author' => '著者B']);
        $bookC = Book::factory()->for($otherUser)->create(['title' => '書籍C', 'author' => '著者C']);
        $bookD = Book::factory()->for($user)->create(['title' => '書籍D', 'author' => '著者D']);
        $bookA->genres()->sync([$novel->id, $mystery->id]);
        $bookB->genres()->sync([$novel->id]);
        $bookC->genres()->detach();
        $bookD->genres()->sync([$mystery->id]);
        $ids = [];
        foreach ([[$bookA, [5, 1]], [$bookB, [4]], [$bookC, [5]], [$bookD, [3]]] as [$book, $ratings]) {
            foreach ($ratings as $rating) {
                $ids[] = Review::factory()->for($user)->for($book)->create(['rating' => $rating])->id;
            }
        }
        Review::factory()->for($otherUser)->for($bookB)->create(['rating' => 2]);
        $this->assertLessThan($bookC->id, $bookA->id);

        $stats = $this->assertStatsFor(
            app(ReadingReportService::class), $user, $ids, [5, 4, 3.6], [1, 0, 1, 1, 2],
            [[$bookA->id, 5], [$bookC->id, 5], [$bookB->id, 4]],
            [[$novel->id, 3, 10 / 3], [$mystery->id, 3, 3.0]]
        );

        $this->assertSame(['書籍A', '書籍C', '書籍B'], array_column($stats['top_rated_books'], 'title'));
        $this->assertSame(['著者A', '著者C', '著者B'], array_column($stats['top_rated_books'], 'author'));
        $this->assertSame(['小説', 'ミステリー'], array_column($stats['genre_ratings'], 'name'));
    }

    public function test_同じサービスで会員を切り替えても混入しない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $emptyUser = User::factory()->create();
        $genreA = Genre::factory()->create(['name' => '会員Aのジャンル']);
        $genreB = Genre::factory()->create(['name' => '会員Bのジャンル']);
        $bookA = Book::factory()->for($otherUser)->create();
        $bookB = Book::factory()->for($user)->create();
        $bookA->genres()->sync([$genreA->id]);
        $bookB->genres()->sync([$genreB->id]);
        $reviewA = Review::factory()->for($user)->for($bookA)->create(['rating' => 4]);
        $reviewB = Review::factory()->for($otherUser)->for($bookB)->create(['rating' => 2]);
        $service = app(ReadingReportService::class);

        $first = $this->assertStatsFor($service, $user, [$reviewA->id], [1, 1, 4.0], [0, 0, 0, 1, 0], [[$bookA->id, 4]], [[$genreA->id, 1, 4.0]]);
        $this->assertStatsFor($service, $otherUser, [$reviewB->id], [1, 1, 2.0], [0, 1, 0, 0, 0], [], [[$genreB->id, 1, 2.0]]);
        $again = $this->assertStatsFor($service, $user, [$reviewA->id], [1, 1, 4.0], [0, 0, 0, 1, 0], [[$bookA->id, 4]], [[$genreA->id, 1, 4.0]]);
        $this->assertEquals($first, $again);
        $this->assertStatsFor($service, $emptyUser, [], [0, 0, 0.0], [0, 0, 0, 0, 0], [], []);
    }

    public function test_追加更新削除後に再取得すると両順位も再計算される(): void
    {
        $user = User::factory()->create();
        $genreA = Genre::factory()->create();
        $genreB = Genre::factory()->create();
        // Bの書籍IDを小さくし、Aの評価更新時に書籍順位も逆転させる。
        $bookB = Book::factory()->for($user)->create();
        $bookA = Book::factory()->for($user)->create();
        $bookA->genres()->sync([$genreA->id]);
        $bookB->genres()->sync([$genreB->id]);
        $reviewA = Review::factory()->for($user)->for($bookA)->create(['rating' => 3]);
        $reviewB = Review::factory()->for($user)->for($bookB)->create(['rating' => 4]);
        $service = app(ReadingReportService::class);
        $ids = [$reviewA->id, $reviewB->id];

        $this->assertStatsFor($service, $user, $ids, [2, 2, 3.5], [0, 0, 1, 1, 0], [[$bookB->id, 4]], [[$genreB->id, 1, 4.0], [$genreA->id, 1, 3.0]]);

        $highest = Review::factory()->for($user)->for($bookA)->create(['rating' => 5]);
        $withAdded = [...$ids, $highest->id];

        $this->assertStatsFor($service, $user, $withAdded, [3, 2, 4.0], [0, 0, 1, 1, 1], [[$bookA->id, 5], [$bookB->id, 4]], [[$genreA->id, 2, 4.0], [$genreB->id, 1, 4.0]]);

        $highest->update(['rating' => 4]);

        $this->assertStatsFor($service, $user, $withAdded, [3, 2, 11 / 3], [0, 0, 1, 2, 0], [[$bookB->id, 4], [$bookA->id, 4]], [[$genreB->id, 1, 4.0], [$genreA->id, 2, 3.5]]);

        $highest->delete();

        $this->assertStatsFor($service, $user, $ids, [2, 2, 3.5], [0, 0, 1, 1, 0], [[$bookB->id, 4]], [[$genreB->id, 1, 4.0], [$genreA->id, 1, 3.0]]);
    }

    /**
     * 本人取得と先読みを確認し、独立して指定した4統計の期待値を照合する。
     *
     * @param  list<int>  $ids
     * @param  array{int, int, float}  $summary
     * @param  list<int>  $distribution
     * @param  list<array{int, int}>  $books
     * @param  list<array{int, int, float}>  $genres
     * @return array<string, mixed>
     */
    private function assertStatsFor(
        ReadingReportService $service,
        User $user,
        array $ids,
        array $summary,
        array $distribution,
        array $books,
        array $genres
    ): array {
        $reviews = $service->getReviewsFor($user);
        $this->assertEqualsCanonicalizing($ids, $reviews->modelKeys());
        foreach ($reviews as $review) {
            $this->assertSame($user->id, $review->user_id);
            $this->assertTrue($review->relationLoaded('book'));
            $this->assertInstanceOf(Book::class, $review->getRelation('book'));
            $this->assertTrue($review->getRelation('book')->relationLoaded('genres'));
        }
        $stats = $service->getStats($reviews);
        $this->assertSame(['summary', 'rating_distribution', 'top_rated_books', 'genre_ratings'], array_keys($stats));
        $this->assertSame(['total_reviews', 'books_read', 'average_rating'], array_keys($stats['summary']));
        $this->assertSame($summary[0], $stats['summary']['total_reviews']);
        $this->assertSame($summary[1], $stats['summary']['books_read']);
        $this->assertIsFloat($stats['summary']['average_rating']);
        $this->assertEqualsWithDelta($summary[2], $stats['summary']['average_rating'], 1.0E-12);
        $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        $this->assertSame($distribution, $stats['rating_distribution']->all());
        $this->assertSame($summary[0], $stats['rating_distribution']->sum());
        $this->assertTrue(array_is_list($stats['top_rated_books']));
        $this->assertTrue(array_is_list($stats['genre_ratings']));
        $this->assertSame($books, array_map(fn (array $book): array => [$book['id'], $book['rating']], $stats['top_rated_books']));
        foreach ($stats['top_rated_books'] as $book) {
            $this->assertSame(['id', 'title', 'author', 'rating'], array_keys($book));
            $this->assertIsString($book['title']);
            $this->assertIsString($book['author']);
        }
        $this->assertCount(count($genres), $stats['genre_ratings']);
        foreach ($genres as $index => [$id, $count, $average]) {
            $actual = $stats['genre_ratings'][$index];
            $this->assertSame(['id', 'name', 'count', 'average_rating'], array_keys($actual));
            $this->assertSame($id, $actual['id']);
            $this->assertSame($count, $actual['count']);
            $this->assertIsString($actual['name']);
            $this->assertIsFloat($actual['average_rating']);
            $this->assertEqualsWithDelta($average, $actual['average_rating'], 1.0E-12);
        }

        return $stats;
    }
}
