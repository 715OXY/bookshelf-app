<?php

namespace Tests\Unit\Services;

use App\Models\Review;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;
use PHPUnit\Framework\TestCase;

class ReadingReportBasicStatsTest extends TestCase
{
    // S02・S04・S06・S08はユーザー承認済み暫定仕様であり、PM正式承認は未確認。
    public function test_複数レビューを個別計上し読了冊数だけ重複排除する(): void
    {
        $reviews = $this->makeReviews([[10, 5], [10, 1], [20, 4]]);

        $stats = (new ReadingReportService)->getBasicStats($reviews);

        $this->assertSame(['summary', 'rating_distribution'], array_keys($stats));
        $this->assertSame(['total_reviews', 'books_read', 'average_rating'], array_keys($stats['summary']));
        $this->assertSame(3, $stats['summary']['total_reviews']);
        $this->assertSame(2, $stats['summary']['books_read']);
        $this->assertEqualsWithDelta(10 / 3, $stats['summary']['average_rating'], 1.0E-12);
        $this->assertSame([1, 0, 0, 1, 1], $stats['rating_distribution']->all());
    }

    public function test_平均は丸めずfloatで返す(): void
    {
        $reviews = $this->makeReviews([[10, 5], [10, 1], [20, 4]]);

        $average = (new ReadingReportService)->getBasicStats($reviews)['summary']['average_rating'];

        $this->assertIsFloat($average);
        $this->assertEqualsWithDelta(10 / 3, $average, 1.0E-12);
    }

    public function test_整数相当の平均と単一レビューでもfloatを維持する(): void
    {
        $service = new ReadingReportService;

        foreach ([
            [[[10, 1]], 1.0],
            [[[10, 5]], 5.0],
            [[[10, 1], [20, 5]], 3.0],
        ] as [$entries, $expected]) {
            $average = $service->getBasicStats($this->makeReviews($entries))['summary']['average_rating'];

            $this->assertIsFloat($average);
            $this->assertSame($expected, $average);
        }
    }

    public function test_星1から5を順番どおり整数件数で返す(): void
    {
        $entries = (new SupportCollection([5, 4, 3, 2, 1]))
            ->flatMap(fn (int $rating): array => array_fill(0, $rating, [10, $rating]))
            ->all();

        $stats = (new ReadingReportService)->getBasicStats($this->makeReviews($entries));
        $distribution = $stats['rating_distribution'];

        $this->assertSame(SupportCollection::class, $distribution::class);
        $this->assertSame([0, 1, 2, 3, 4], $distribution->keys()->all());
        $this->assertSame([1, 2, 3, 4, 5], $distribution->all());
        $this->assertSame(15, $stats['summary']['total_reviews']);
        $this->assertSame($stats['summary']['total_reviews'], $distribution->sum());
    }

    public function test_存在しない評価区分を0で補完し総数と一致する(): void
    {
        $reviews = $this->makeReviews([[10, 2], [20, 2], [20, 5]]);

        $stats = (new ReadingReportService)->getBasicStats($reviews);
        $distribution = $stats['rating_distribution'];

        $this->assertCount(5, $distribution);
        $this->assertSame([0, 2, 0, 0, 1], $distribution->all());
        $this->assertSame(3, $stats['summary']['total_reviews']);
        $this->assertSame($stats['summary']['total_reviews'], $distribution->sum());
    }

    public function test_0件では件数0とfloatの平均0と5区分0を返す(): void
    {
        $stats = (new ReadingReportService)->getBasicStats(new Collection);

        $this->assertSame([
            'total_reviews' => 0,
            'books_read' => 0,
            'average_rating' => 0.0,
        ], $stats['summary']);
        $this->assertIsFloat($stats['summary']['average_rating']);
        $this->assertSame(SupportCollection::class, $stats['rating_distribution']::class);
        $this->assertSame([0, 1, 2, 3, 4], $stats['rating_distribution']->keys()->all());
        $this->assertSame([0, 0, 0, 0, 0], $stats['rating_distribution']->all());
        $this->assertSame(0, $stats['rating_distribution']->sum());
    }

    public function test_集計前後で入力コレクションとモデルを変更しない(): void
    {
        $models = $this->makeReviews([[20, 4], [10, 5], [10, 1]])->all();
        $models[0]->setRelation('book', null);
        $reviews = new Collection([9 => $models[0], 2 => $models[1], 7 => $models[2]]);
        $beforeModels = $reviews->all();
        $snapshot = fn (Review $review): array => [
            'attributes' => $review->getAttributes(),
            'original' => $review->getRawOriginal(),
            'relations' => $review->getRelations(),
            'book_loaded' => $review->relationLoaded('book'),
        ];
        $beforeState = $reviews->map($snapshot)->all();

        $stats = (new ReadingReportService)->getBasicStats($reviews);

        $this->assertSame([9, 2, 7], $reviews->keys()->all());
        $this->assertCount(3, $reviews);
        $this->assertSame($beforeModels, $reviews->all());
        $this->assertSame($beforeState, $reviews->map($snapshot)->all());
        $this->assertSame([1, 0, 0, 1, 1], $stats['rating_distribution']->all());
    }

    /**
     * DBやFactoryを使わず、同じ会員のレビュー属性だけを準備する。
     *
     * @param  array<int, array{int, int}>  $entries
     * @return Collection<int, Review>
     */
    private function makeReviews(array $entries): Collection
    {
        return new Collection((new SupportCollection($entries))
            ->map(fn (array $entry): Review => new Review([
                'user_id' => 1,
                'book_id' => $entry[0],
                'rating' => $entry[1],
            ]))
            ->all());
    }
}
