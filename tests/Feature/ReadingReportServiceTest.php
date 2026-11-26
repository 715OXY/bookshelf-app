<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use App\Services\ReadingReportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadingReportServiceTest extends TestCase
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

    public function test_指定会員のレビューだけを書籍登録者に関係なく取得する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $ownBook = Book::factory()->for($user)->create();
        $otherBook = Book::factory()->for($otherUser)->create();

        $ownBookReview = Review::factory()->for($user)->for($ownBook)
            ->create(['rating' => 2]);
        $otherBookReview = Review::factory()->for($user)->for($otherBook)
            ->create(['rating' => 5]);

        Review::factory()->for($otherUser)->for($ownBook)
            ->create(['rating' => 4]);
        Review::factory()->for($otherUser)->for($otherBook)
            ->create(['rating' => 3]);

        $reviews = app(ReadingReportService::class)->getReviewsFor($user);

        $this->assertInstanceOf(Collection::class, $reviews);
        $this->assertCount(2, $reviews);
        $this->assertEqualsCanonicalizing(
            [$ownBookReview->id, $otherBookReview->id],
            $reviews->modelKeys()
        );
        $this->assertSame([$user->id], $reviews->pluck('user_id')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            [$ownBook->id, $otherBook->id],
            $reviews->pluck('book_id')->all()
        );
    }

    public function test_同じサービスで会員を切り替えても取得結果が混入しない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->for($otherUser)->create();
        $userReview = Review::factory()->for($user)->for($book)
            ->create(['rating' => 1]);
        $otherReview = Review::factory()->for($otherUser)->for($book)
            ->create(['rating' => 5]);
        $service = app(ReadingReportService::class);

        foreach ([$user, $otherUser, $user] as $target) {
            $reviews = $service->getReviewsFor($target);
            $expected = $target->is($user) ? $userReview : $otherReview;

            $this->assertSame([$expected->id], $reviews->modelKeys());
            $this->assertSame([$target->id], $reviews->pluck('user_id')->all());
            $this->assertSame([$expected->rating], $reviews->pluck('rating')->all());
        }
    }

    public function test_同一書籍への複数レビューを評価値とともにすべて保持する(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $expectedRatings = collect([1, 2, 3, 4, 5, 1])
            ->mapWithKeys(function (int $rating) use ($user, $book): array {
                $review = Review::factory()->for($user)->for($book)
                    ->create(['rating' => $rating]);

                return [$review->id => $rating];
            });

        $reviews = app(ReadingReportService::class)->getReviewsFor($user);

        $this->assertCount(6, $reviews);
        $this->assertSame(
            $expectedRatings->sortKeys()->all(),
            $reviews->mapWithKeys(
                fn (Review $review): array => [$review->id => $review->rating]
            )->sortKeys()->all()
        );
        $this->assertSame([$user->id], $reviews->pluck('user_id')->unique()->values()->all());
        $this->assertSame([$book->id], $reviews->pluck('book_id')->unique()->values()->all());
    }

    public function test_他会員にレビューがあっても本人が0件なら空のコレクションを返す(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        Review::factory()->for($otherUser)->for($book)
            ->create(['rating' => 5]);

        $reviews = app(ReadingReportService::class)->getReviewsFor($user);

        $this->assertInstanceOf(Collection::class, $reviews);
        $this->assertTrue($reviews->isEmpty());
        $this->assertSame([], $reviews->modelKeys());
    }

    public function test_書籍と複数ジャンルを先読みしジャンル未所属書籍も保持する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $genreBook = Book::factory()->for($otherUser)->create([
            'title' => '複数ジャンルの確認書籍',
            'author' => '関連確認の著者',
            'published_date' => '2026-11-01',
        ]);
        $ungroupedBook = Book::factory()->for($user)->create([
            'title' => 'ジャンル未所属の確認書籍',
            'author' => '未所属確認の著者',
            'published_date' => '2026-11-02',
        ]);
        $technology = Genre::factory()->create(['name' => 'レポート確認用技術書']);
        $science = Genre::factory()->create(['name' => 'レポート確認用科学']);

        $genreBook->genres()->sync([$technology->id, $science->id]);
        $ungroupedBook->genres()->detach();

        $genreReview = Review::factory()->for($user)->for($genreBook)
            ->create(['rating' => 4]);
        $ungroupedReview = Review::factory()->for($user)->for($ungroupedBook)
            ->create(['rating' => 2]);

        $reviews = app(ReadingReportService::class)->getReviewsFor($user);

        $this->assertEqualsCanonicalizing(
            [$genreReview->id, $ungroupedReview->id],
            $reviews->modelKeys()
        );

        foreach ([
            [$genreReview, $genreBook],
            [$ungroupedReview, $ungroupedBook],
        ] as [$expectedReview, $expectedBook]) {
            $review = $reviews->find($expectedReview->id);

            $this->assertInstanceOf(Review::class, $review);
            $this->assertSame($user->id, $review->user_id);
            $this->assertSame($expectedReview->rating, $review->rating);
            $this->assertTrue($review->relationLoaded('book'));
            $this->assertInstanceOf(Book::class, $review->book);
            $this->assertSame($expectedBook->id, $review->book->id);
            $this->assertSame($expectedBook->title, $review->book->title);
            $this->assertSame($expectedBook->author, $review->book->author);
            $this->assertTrue($review->book->relationLoaded('genres'));
            $this->assertInstanceOf(Collection::class, $review->book->genres);
        }

        $this->assertSame(
            collect([
                $technology->id => $technology->name,
                $science->id => $science->name,
            ])->sortKeys()->all(),
            $reviews->find($genreReview->id)->book->genres
                ->pluck('name', 'id')->sortKeys()->all()
        );
        $this->assertTrue(
            $reviews->find($ungroupedReview->id)->book->genres->isEmpty()
        );
    }
}
