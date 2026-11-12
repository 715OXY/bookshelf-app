<?php

namespace Tests\Feature;

use App\Http\Controllers\BookController;
use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class BookInteractionDataTest extends TestCase
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

    public function test_レビューが増えても表示用データの取得クエリ数が増えない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create();

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $user->id,
        ]);

        $user->favoriteBooks()->attach($book->id);
        $review->likedByUsers()->attach([$user->id, $otherUser->id]);

        $one = $this->表示用データ($user, $book);

        $this->assertTrue($one['favorite']);
        $this->assertCount(1, $one['reviews']);
        $this->assertSame($user->name, $one['reviews'][$review->id]['name']);
        $this->assertTrue($one['reviews'][$review->id]['liked']);
        $this->assertSame(2, $one['reviews'][$review->id]['count']);

        $additionalReviews = Review::factory()->count(9)->create([
            'book_id' => $book->id,
            'user_id' => $otherUser->id,
        ]);

        foreach ($additionalReviews as $additionalReview) {
            $additionalReview->likedByUsers()->attach($otherUser->id);
        }

        $many = $this->表示用データ($user, $book);

        $this->assertCount(10, $many['reviews']);
        $this->assertGreaterThan(0, $one['queries']);
        $this->assertLessThanOrEqual($one['queries'], $many['queries']);

        foreach ($additionalReviews as $additionalReview) {
            $data = $many['reviews'][$additionalReview->id];

            $this->assertSame($otherUser->name, $data['name']);
            $this->assertFalse($data['liked']);
            $this->assertSame(1, $data['count']);
        }
    }

    public function test_操作後の再取得で本人の登録状態といいね数が更新される(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $book = Book::factory()->create();

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $user->id,
        ]);

        $otherUser->likedReviews()->attach($review->id);

        $before = $this->表示用データ($user, $book);

        $this->assertFalse($before['favorite']);
        $this->assertFalse($before['reviews'][$review->id]['liked']);
        $this->assertSame(1, $before['reviews'][$review->id]['count']);

        $this->post(route('favorites.toggle', $book))
            ->assertRedirect(route('books.show', $book));

        $this->post(route('reviews.like', $review))
            ->assertRedirect(route('books.show', $book));

        $added = $this->表示用データ($user, $book);

        $this->assertTrue($added['favorite']);
        $this->assertTrue($added['reviews'][$review->id]['liked']);
        $this->assertSame(2, $added['reviews'][$review->id]['count']);

        $this->post(route('favorites.toggle', $book))
            ->assertRedirect(route('books.show', $book));

        $this->post(route('reviews.like', $review))
            ->assertRedirect(route('books.show', $book));

        $removed = $this->表示用データ($user, $book);

        $this->assertFalse($removed['favorite']);
        $this->assertFalse($removed['reviews'][$review->id]['liked']);
        $this->assertSame(1, $removed['reviews'][$review->id]['count']);

        $this->assertDatabaseHas('review_likes', [
            'user_id' => $otherUser->id,
            'review_id' => $review->id,
        ]);
    }

    /**
     * Bladeで参照する関連データを、毎回新しいModelから取得する。
     *
     * @return array{
     *     favorite: bool,
     *     reviews: array<int, array{name: string, liked: bool, count: int}>,
     *     queries: int
     * }
     */
    private function 表示用データ(User $user, Book $book): array
    {
        $viewer = User::query()->findOrFail($user->id);
        $this->actingAs($viewer, 'web');

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
            $view = app(BookController::class)->show(
                Book::query()->findOrFail($book->id)
            );

            $loadedBook = $view->getData()['book'];

            $favorite = $viewer->favoriteBooks->contains($loadedBook->id);
            $reviews = [];

            foreach ($loadedBook->reviews as $review) {
                $reviews[$review->id] = [
                    'name' => $review->user->name,
                    'liked' => $viewer->likedReviews->contains($review->id),
                    'count' => $review->likedByUsers->count(),
                ];
            }
        } finally {
            $connection->setEventDispatcher($originalDispatcher);
        }

        return [
            'favorite' => $favorite,
            'reviews' => $reviews,
            'queries' => $queryCount,
        ];
    }
}
