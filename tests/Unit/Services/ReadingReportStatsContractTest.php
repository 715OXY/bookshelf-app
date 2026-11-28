<?php

namespace Tests\Unit\Services;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection as SupportCollection;
use PHPUnit\Framework\TestCase;

class ReadingReportStatsContractTest extends TestCase
{
    public function test_完全な統計でも既存の部分集計契約を維持する(): void
    {
        $reviews = $this->makeReviews();
        $service = new ReadingReportService;
        $basic = $service->getBasicStats($reviews);

        $stats = $service->getStats($reviews);

        $this->assertContract($stats);
        $this->assertSame(['total_reviews' => 5, 'books_read' => 4, 'average_rating' => 3.6], $stats['summary']);
        $this->assertSame([1, 0, 1, 1, 2], $stats['rating_distribution']->all());
        $this->assertSame($basic['summary'], $stats['summary']);
        $this->assertSame($basic['rating_distribution']->all(), $stats['rating_distribution']->all());
        $this->assertSame([
            ['id' => 10, 'title' => '書籍10', 'author' => '著者10', 'rating' => 5],
            ['id' => 30, 'title' => '書籍30', 'author' => '著者30', 'rating' => 5],
            ['id' => 20, 'title' => '書籍20', 'author' => '著者20', 'rating' => 4],
        ], $stats['top_rated_books']);
        $this->assertSame([5, 5, 4], array_column($stats['top_rated_books'], 'rating'));
        $this->assertSame([1, 2], array_column($stats['genre_ratings'], 'id'));
        $this->assertSame(['小説', 'ミステリー'], array_column($stats['genre_ratings'], 'name'));
        $this->assertSame([3, 3], array_column($stats['genre_ratings'], 'count'));
        $this->assertEqualsWithDelta(10 / 3, $stats['genre_ratings'][0]['average_rating'], 1.0E-12);
        $this->assertSame(3.0, $stats['genre_ratings'][1]['average_rating']);
    }

    public function test_集計しても入力と関連モデルの状態を変更しない(): void
    {
        $models = $this->makeReviews()->all();
        $reviews = new Collection([9 => $models[0], 2 => $models[1], 8 => $models[2], 1 => $models[3], 7 => $models[4]]);
        $reviews->first()->comment = '保存していないコメント';
        $reviews->first()->getRelation('book')->title = '保存していない書籍名';
        $before = $this->snapshot($reviews);

        (new ReadingReportService)->getStats($reviews);

        $this->assertSame($before, $this->snapshot($reviews));
        $this->assertSame([9, 2, 8, 1, 7], $reviews->keys()->all());
        $this->assertSame($models[0]->getRelation('book'), $models[1]->getRelation('book'));
    }

    public function test_両方または片方だけ空でも完全な統計を返す(): void
    {
        foreach ([
            [[], ['total_reviews' => 0, 'books_read' => 0, 'average_rating' => 0.0], [0, 0, 0, 0, 0], [], []],
            [[[10, [3], [1]]], ['total_reviews' => 1, 'books_read' => 1, 'average_rating' => 3.0], [0, 0, 1, 0, 0], [], [1]],
            [[[10, [5], []]], ['total_reviews' => 1, 'books_read' => 1, 'average_rating' => 5.0], [0, 0, 0, 0, 1], [10], []],
        ] as [$entries, $summary, $distribution, $bookIds, $genreIds]) {
            $stats = (new ReadingReportService)->getStats($this->makeReviews($entries));

            $this->assertContract($stats);
            $this->assertSame($summary, $stats['summary']);
            $this->assertSame($distribution, $stats['rating_distribution']->all());
            $this->assertSame($bookIds, array_column($stats['top_rated_books'], 'id'));
            $this->assertSame($genreIds, array_column($stats['genre_ratings'], 'id'));
            if ($genreIds !== []) {
                $this->assertSame([['id' => 1, 'name' => '小説', 'count' => 1, 'average_rating' => 3.0]], $stats['genre_ratings']);
            }
        }
    }

    /** @param array<string, mixed> $stats */
    private function assertContract(array $stats): void
    {
        $this->assertSame(['summary', 'rating_distribution', 'top_rated_books', 'genre_ratings'], array_keys($stats));
        $this->assertSame(['total_reviews', 'books_read', 'average_rating'], array_keys($stats['summary']));
        $this->assertIsInt($stats['summary']['total_reviews']);
        $this->assertIsInt($stats['summary']['books_read']);
        $this->assertIsFloat($stats['summary']['average_rating']);
        $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        $this->assertSame($stats['summary']['total_reviews'], $stats['rating_distribution']->sum());
        foreach ($stats['rating_distribution'] as $count) {
            $this->assertIsInt($count);
        }
        foreach (['top_rated_books', 'genre_ratings'] as $key) {
            $this->assertTrue(array_is_list($stats[$key]));
            $this->assertLessThanOrEqual(5, count($stats[$key]));
        }
        foreach ($stats['top_rated_books'] as $book) {
            $this->assertSame(['id', 'title', 'author', 'rating'], array_keys($book));
            $this->assertIsInt($book['id']);
            $this->assertIsString($book['title']);
            $this->assertIsString($book['author']);
            $this->assertIsInt($book['rating']);
            $this->assertContains($book['rating'], [4, 5]);
        }
        foreach ($stats['genre_ratings'] as $genre) {
            $this->assertSame(['id', 'name', 'count', 'average_rating'], array_keys($genre));
            $this->assertIsInt($genre['id']);
            $this->assertIsString($genre['name']);
            $this->assertIsInt($genre['count']);
            $this->assertIsFloat($genre['average_rating']);
        }
    }

    /**
     * 同じ書籍・ジャンルのModelを共有し、全関連を先読み相当の状態にする。
     *
     * @param  list<array{int, list<int>, list<int>}>|null  $entries
     * @return Collection<int, Review>
     */
    private function makeReviews(?array $entries = null): Collection
    {
        $entries ??= [[10, [5, 1], [1, 2]], [20, [4], [1]], [30, [5], []], [40, [3], [2]]];
        $genres = [];
        foreach ([1 => '小説', 2 => 'ミステリー'] as $id => $name) {
            $genre = new Genre;
            $genre->setRawAttributes(['id' => $id, 'name' => $name], true);
            $genres[$id] = $genre;
        }
        $reviews = [];
        foreach ($entries as [$id, $ratings, $genreIds]) {
            $book = new Book;
            $book->setRawAttributes(['id' => $id, 'title' => '書籍'.$id, 'author' => '著者'.$id], true);
            $book->setRelation('genres', new Collection(array_map(fn (int $genreId): Genre => $genres[$genreId], $genreIds)));
            foreach ($ratings as $rating) {
                $review = new Review;
                $review->setRawAttributes(['user_id' => 1, 'book_id' => $id, 'rating' => $rating], true);
                $review->setRelation('book', $book);
                $reviews[] = $review;
            }
        }

        return new Collection($reviews);
    }

    /**
     * 属性は値で保存し、関連の要素・順序・同一性を別に記録する。
     *
     * @param  Collection<int, Review>  $reviews
     * @return array<string, mixed>
     */
    private function snapshot(Collection $reviews): array
    {
        $states = [];
        foreach ($reviews as $review) {
            $book = $review->getRelation('book');
            foreach ([$review, $book, ...$book->getRelation('genres')->all()] as $model) {
                $relations = [];
                foreach ($model->getRelations() as $name => $relation) {
                    $relations[$name] = $relation instanceof Collection
                        ? ['collection' => spl_object_id($relation), 'items' => $relation->map(fn (Model $item): int => spl_object_id($item))->all()]
                        : spl_object_id($relation);
                }
                $states[spl_object_id($model)] = [
                    'attributes' => $model->getAttributes(),
                    'original' => $model->getRawOriginal(),
                    'relations' => $relations,
                ];
            }
        }

        return [
            'collection' => spl_object_id($reviews),
            'items' => $reviews->map(fn (Review $review): int => spl_object_id($review))->all(),
            'states' => $states,
        ];
    }
}
