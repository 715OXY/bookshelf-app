<?php

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Review;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use PHPUnit\Framework\TestCase;

class ReadingReportTopRatedBooksTest extends TestCase
{
    // TOP5の集約・順位はユーザー承認済み暫定仕様。PM正式承認とは区別する。
    public function test_最高評価を採用し四以上の書籍を重複なく返す(): void
    {
        $reviews = $this->makeReviews([[10, 5], [10, 1], [20, 3], [30, 4]]);

        $books = (new ReadingReportService)->getStats($reviews)['top_rated_books'];

        $this->assertSame([
            ['id' => 10, 'title' => '書籍10', 'author' => '著者10', 'rating' => 5],
            ['id' => 30, 'title' => '書籍30', 'author' => '著者30', 'rating' => 4],
        ], $books);
    }

    public function test_評価降順と数値の書籍識別子昇順で並べる(): void
    {
        $reviews = $this->makeReviews([['10', 5], ['2', 5], ['1', 4]]);

        $books = (new ReadingReportService)->getStats($reviews)['top_rated_books'];

        $this->assertSame([2, 10, 1], array_column($books, 'id'));
        $this->assertSame([5, 5, 4], array_column($books, 'rating'));
    }

    public function test_入力順序を変えても書籍順位が変わらない(): void
    {
        $reviews = $this->makeReviews([[10, 1], [2, 5], [10, 5], [30, 4]]);
        $service = new ReadingReportService;

        $first = $service->getStats($reviews)['top_rated_books'];
        $reversed = $service->getStats(new Collection(array_reverse($reviews->all())))['top_rated_books'];

        $this->assertSame([2, 10, 30], array_column($first, 'id'));
        $this->assertSame($first, $reversed);
    }

    public function test_対象件数に応じた連続した書籍リストを返す(): void
    {
        $service = new ReadingReportService;

        foreach ([0 => [], 1 => [1], 4 => [1, 2, 3, 4], 5 => [1, 2, 3, 4, 5], 6 => [1, 2, 3, 4, 5]] as $count => $ids) {
            $entries = $count === 0 ? [] : array_map(fn (int $id): array => [$id, 4], range(1, $count));
            $books = $service->getStats($this->makeReviews($entries))['top_rated_books'];

            $this->assertTrue(array_is_list($books));
            $this->assertCount(count($ids), $books);
            $this->assertSame($ids, array_column($books, 'id'));

            foreach ($books as $book) {
                $this->assertSame(['id', 'title', 'author', 'rating'], array_keys($book));
                $this->assertIsInt($book['id']);
                $this->assertIsString($book['title']);
                $this->assertIsString($book['author']);
                $this->assertSame(4, $book['rating']);
            }
        }
    }

    public function test_同評価の五件目境界を識別子で決め未所属も含める(): void
    {
        $entries = array_map(fn (int $id): array => [$id, 5], [6, 4, 2, 5, 1, 3]);
        $reviews = $this->makeReviews($entries);

        $stats = (new ReadingReportService)->getStats($reviews);

        $this->assertSame([1, 2, 3, 4, 5], array_column($stats['top_rated_books'], 'id'));
        $this->assertSame([5, 5, 5, 5, 5], array_column($stats['top_rated_books'], 'rating'));
        $this->assertSame([], $stats['genre_ratings']);
    }

    /**
     * 同一会員のレビューと未所属書籍を、DB・Factoryなしで準備する。
     *
     * @param  list<array{int|string, int}>  $entries
     * @return Collection<int, Review>
     */
    private function makeReviews(array $entries): Collection
    {
        $books = [];
        $reviews = [];

        foreach ($entries as [$id, $rating]) {
            if (! isset($books[$id])) {
                $book = new Book;
                $book->setRawAttributes(['id' => $id, 'title' => '書籍'.$id, 'author' => '著者'.$id], true);
                $book->setRelation('genres', new Collection);
                $books[$id] = $book;
            }

            $review = new Review;
            $review->setRawAttributes(['user_id' => 1, 'book_id' => $id, 'rating' => $rating], true);
            $review->setRelation('book', $books[$id]);
            $reviews[] = $review;
        }

        return new Collection($reviews);
    }
}
