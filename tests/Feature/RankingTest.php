<?php

namespace Tests\Feature;

use App\Http\Controllers\RankingController;
use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RankingTest extends TestCase
{
    use RefreshDatabase;

    public function test_レビュー0件ではランキングが空になる(): void
    {
        Book::factory()->count(3)->create();

        $view = app(RankingController::class)->index();

        $this->assertInstanceOf(View::class, $view);
        $this->assertSame('ranking.index', $view->name());

        $rankedBooks = $view->getData()['rankedBooks'];

        $this->assertTrue($rankedBooks->isEmpty());
    }

    public function test_レビューあり書籍を平均評価降順かつ同平均時はid昇順で取得する(): void
    {
        $user = User::factory()->create();

        $highest = Book::factory()->create();
        $tieFirst = Book::factory()->create();
        $tieSecond = Book::factory()->create();
        $lower = Book::factory()->create();
        $withoutReview = Book::factory()->create();

        Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $highest->id,
            'rating' => 5,
        ]);

        Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $highest->id,
            'rating' => 4,
        ]);

        foreach ([$tieFirst, $tieSecond] as $book) {
            Review::factory()->create([
                'user_id' => $user->id,
                'book_id' => $book->id,
                'rating' => 4,
            ]);
        }

        Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $lower->id,
            'rating' => 3,
        ]);

        $view = app(RankingController::class)->index();
        $rankedBooks = $view->getData()['rankedBooks'];

        $this->assertSame(
            [
                $highest->id,
                $tieFirst->id,
                $tieSecond->id,
                $lower->id,
            ],
            $rankedBooks->pluck('id')->all()
        );

        $this->assertFalse($rankedBooks->contains('id', $withoutReview->id));

        $highestResult = $rankedBooks->firstWhere('id', $highest->id);

        $this->assertNotNull($highestResult);
        $this->assertEqualsWithDelta(
            4.5,
            (float) $highestResult->reviews_avg_rating,
            0.001
        );
        $this->assertSame(2, $highestResult->reviews_count);
    }

    public function test_ランキングは最大10冊まで取得する(): void
    {
        $user = User::factory()->create();
        $books = Book::factory()->count(11)->create();

        foreach ($books as $book) {
            Review::factory()->create([
                'user_id' => $user->id,
                'book_id' => $book->id,
                'rating' => 5,
            ]);
        }

        $view = app(RankingController::class)->index();
        $rankedBooks = $view->getData()['rankedBooks'];

        $expectedIds = $books
            ->pluck('id')
            ->sort()
            ->take(10)
            ->values()
            ->all();

        $this->assertCount(10, $rankedBooks);
        $this->assertSame($expectedIds, $rankedBooks->pluck('id')->all());
    }

    public function test_同一ユーザーの複数レビューをすべて集計する(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 5,
        ]);

        Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 3,
        ]);

        $view = app(RankingController::class)->index();
        $rankedBook = $view->getData()['rankedBooks']->first();

        $this->assertNotNull($rankedBook);
        $this->assertSame($book->id, $rankedBook->id);
        $this->assertEqualsWithDelta(
            4.0,
            (float) $rankedBook->reviews_avg_rating,
            0.001
        );
        $this->assertSame(2, $rankedBook->reviews_count);
    }

    public function test_レビューの投稿更新削除後にランキング集計が再計算される(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->create();

        $this->actingAs($user, 'web')
            ->post(route('reviews.store', $book), [
                'rating' => 5,
                'comment' => '最初のレビュー',
            ])
            ->assertRedirect(route('books.show', $book));

        $review = Review::query()
            ->where('book_id', $book->id)
            ->where('user_id', $user->id)
            ->firstOrFail();

        $afterStore = app(RankingController::class)
            ->index()
            ->getData()['rankedBooks']
            ->firstWhere('id', $book->id);

        $this->assertNotNull($afterStore);
        $this->assertEqualsWithDelta(
            5.0,
            (float) $afterStore->reviews_avg_rating,
            0.001
        );
        $this->assertSame(1, $afterStore->reviews_count);

        $this->actingAs($user, 'web')
            ->put(route('reviews.update', $review), [
                'rating' => 2,
                'comment' => '更新後のレビュー',
            ])
            ->assertRedirect(route('books.show', $book));

        $afterUpdate = app(RankingController::class)
            ->index()
            ->getData()['rankedBooks']
            ->firstWhere('id', $book->id);

        $this->assertNotNull($afterUpdate);
        $this->assertEqualsWithDelta(
            2.0,
            (float) $afterUpdate->reviews_avg_rating,
            0.001
        );
        $this->assertSame(1, $afterUpdate->reviews_count);

        $this->actingAs($user, 'web')
            ->delete(route('reviews.destroy', $review))
            ->assertRedirect(route('books.show', $book));

        $afterDelete = app(RankingController::class)
            ->index()
            ->getData()['rankedBooks'];

        $this->assertFalse($afterDelete->contains('id', $book->id));
    }

    public function test_書籍とレビューが増えてもランキング取得クエリ数が増えない(): void
    {
        $user = User::factory()->create();

        $oneBook = Book::factory()->create();

        Review::factory()->create([
            'user_id' => $user->id,
            'book_id' => $oneBook->id,
            'rating' => 5,
        ]);

        $fewQueries = $this->ランキング取得クエリ数();

        $additionalBooks = Book::factory()->count(9)->create();

        foreach ($additionalBooks as $book) {
            Review::factory()->count(5)->create([
                'user_id' => $user->id,
                'book_id' => $book->id,
            ]);
        }

        $manyQueries = $this->ランキング取得クエリ数();

        $this->assertGreaterThan(0, $fewQueries);
        $this->assertLessThanOrEqual($fewQueries, $manyQueries);
    }

    public function test_ランキングルートは認証middlewareを要求しない(): void
    {
        $route = app('router')
            ->getRoutes()
            ->getByName('ranking.index');

        $this->assertNotNull($route);

        $middleware = $route->gatherMiddleware();

        $this->assertNotContains('auth', $middleware);
        $this->assertNotContains('auth:web', $middleware);
    }

    private function ランキング取得クエリ数(): int
    {
        $connection = DB::connection();
        $originalDispatcher = $connection->getEventDispatcher();

        $this->assertNotNull($originalDispatcher);

        $dispatcher = clone $originalDispatcher;
        $queryCount = 0;

        $dispatcher->listen(
            QueryExecuted::class,
            function (QueryExecuted $event) use (&$queryCount): void {
                $queryCount++;
            }
        );

        $connection->setEventDispatcher($dispatcher);

        try {
            $rankedBooks = app(RankingController::class)
                ->index()
                ->getData()['rankedBooks'];

            foreach ($rankedBooks as $book) {
                $book->title;
                $book->author;
                $book->image_url;
                $book->reviews_avg_rating;
                $book->reviews_count;
            }
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        return $queryCount;
    }
}
