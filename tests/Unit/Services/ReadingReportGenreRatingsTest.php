<?php

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ReadingReportGenreRatingsTest extends TestCase
{
    // レビュー単位計上・順位はユーザー承認済み暫定仕様。
    public function test_各レビューを各所属ジャンルへ一件ずつ計上する(): void
    {
        $reviews = $this->makeReviews([[10, [5, 1], [1, 2]], [20, [4], [1]]]);

        $genres = (new ReadingReportService)->getStats($reviews)['genre_ratings'];

        $this->assertSame([1, 2], array_column($genres, 'id'));
        $this->assertSame([3, 2], array_column($genres, 'count'));
        $this->assertSame(['ジャンル1', 'ジャンル2'], array_column($genres, 'name'));
        $this->assertEqualsWithDelta(10 / 3, $genres[0]['average_rating'], 1.0E-12);
        $this->assertSame(3.0, $genres[1]['average_rating']);
        $this->assertSame(5, array_sum(array_column($genres, 'count')));
        $this->assertCount(3, $reviews);
    }

    public function test_未所属と空入力ではジャンル行を作らない(): void
    {
        $service = new ReadingReportService;

        foreach ([[], [[10, [5], []]]] as $entries) {
            $this->assertSame([], $service->getStats($this->makeReviews($entries))['genre_ratings']);
        }
    }

    public function test_表示上同じ平均でも丸め前の平均を優先する(): void
    {
        $reviews = $this->makeReviews([
            [10, [5, 4, 4, 4, 4, 4, 4], [10]],
            [20, [5, 4, 4, 4, 4, 4, 4, 4], [2]],
        ]);

        $genres = (new ReadingReportService)->getStats($reviews)['genre_ratings'];

        $this->assertSame([10, 2], array_column($genres, 'id'));
        $this->assertSame([7, 8], array_column($genres, 'count'));
        $this->assertIsFloat($genres[0]['average_rating']);
        $this->assertIsFloat($genres[1]['average_rating']);
        $this->assertEqualsWithDelta(29 / 7, $genres[0]['average_rating'], 1.0E-12);
        $this->assertEqualsWithDelta(33 / 8, $genres[1]['average_rating'], 1.0E-12);
        $this->assertSame('4.1', number_format($genres[0]['average_rating'], 1));
        $this->assertSame('4.1', number_format($genres[1]['average_rating'], 1));
    }

    public function test_数学的に同じ平均ならレビュー件数を優先する(): void
    {
        $reviews = $this->makeReviews([
            [10, [5, 1, 4], [2]],
            [20, [5, 1, 4, 5, 1, 4], [10]],
        ]);

        $genres = (new ReadingReportService)->getStats($reviews)['genre_ratings'];

        $this->assertSame([10, 2], array_column($genres, 'id'));
        $this->assertSame([6, 3], array_column($genres, 'count'));
        foreach ($genres as $genre) {
            $this->assertIsFloat($genre['average_rating']);
            $this->assertEqualsWithDelta(10 / 3, $genre['average_rating'], 1.0E-12);
        }
    }

    public function test_同平均同件数なら数値のジャンル識別子順にする(): void
    {
        $reviews = $this->makeReviews([[10, [5, 3], ['10', '2']]]);
        $service = new ReadingReportService;

        $genres = $service->getStats($reviews)['genre_ratings'];
        $reversed = $this->makeReviews([[10, [3, 5], ['2', '10']]]);

        $this->assertSame([
            ['id' => 2, 'name' => 'ジャンル2', 'count' => 2, 'average_rating' => 4.0],
            ['id' => 10, 'name' => 'ジャンル10', 'count' => 2, 'average_rating' => 4.0],
        ], $genres);
        $this->assertSame($genres, $service->getStats($reversed)['genre_ratings']);
    }

    public function test_ジャンル件数の境界と五件目の同点を処理する(): void
    {
        $service = new ReadingReportService;

        foreach ([0 => [], 1 => [1], 4 => [1, 2, 3, 4], 5 => [1, 2, 3, 4, 5], 6 => [1, 2, 3, 4, 5]] as $count => $ids) {
            $memberships = $count === 0 ? [] : array_reverse(range(1, $count));
            $genres = $service->getStats($this->makeReviews([[10, [4, 4, 4], $memberships]]))['genre_ratings'];

            $this->assertTrue(array_is_list($genres));
            $this->assertCount(count($ids), $genres);
            $this->assertSame($ids, array_column($genres, 'id'));
            foreach ($genres as $genre) {
                $this->assertSame(['id', 'name', 'count', 'average_rating'], array_keys($genre));
                $this->assertIsInt($genre['id']);
                $this->assertIsString($genre['name']);
                $this->assertSame(3, $genre['count']);
                $this->assertSame(4.0, $genre['average_rating']);
            }
        }
    }

    /**
     * 書籍ごとの評価一覧・所属IDを明示し、関連をメモリ上で設定する。
     *
     * @param  list<array{int, list<int>, list<int|string>}>  $entries
     * @return Collection<int, Review>
     */
    private function makeReviews(array $entries): Collection
    {
        $reviews = [];

        foreach ($entries as [$bookId, $ratings, $genreIds]) {
            $genres = [];
            foreach ($genreIds as $genreId) {
                $genre = new Genre;
                $genre->setRawAttributes(['id' => $genreId, 'name' => 'ジャンル'.$genreId], true);
                $genres[] = $genre;
            }
            $book = new Book;
            $book->setRawAttributes(['id' => $bookId, 'title' => '書籍'.$bookId, 'author' => '著者'.$bookId], true);
            $book->setRelation('genres', new Collection($genres));
            foreach ($ratings as $rating) {
                $review = new Review;
                $review->setRawAttributes(['user_id' => 1, 'book_id' => $bookId, 'rating' => $rating], true);
                $review->setRelation('book', $book);
                $reviews[] = $review;
            }
        }

        return new Collection($reviews);
    }
}
