<?php

namespace Tests\Feature;

use App\Http\Controllers\ReviewController;
use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewCrudTest extends TestCase
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

    public function test_自己登録書籍へ複数投稿でき投稿者と対象書籍を偽装できない(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $book = Book::factory()->for($user)->create();
        $otherBook = Book::factory()->for($otherUser)->create();

        $this->actingAs($user)
            ->post(route('reviews.store', $book), [
                'rating' => '1',
                'comment' => '最初の感想',
                'user_id' => $otherUser->id,
                'book_id' => $otherBook->id,
            ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'レビューを投稿しました。');

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 1,
            'comment' => '最初の感想',
        ]);

        $this->post(route('reviews.store', $book), [
            'rating' => '5',
        ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseCount('reviews', 2);

        $this->assertSame(
            2,
            Review::query()
                ->where('user_id', $user->id)
                ->where('book_id', $book->id)
                ->count()
        );

        $this->assertDatabaseHas('reviews', [
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 5,
            'comment' => null,
        ]);
    }

    public function test_本人が更新でき投稿者と対象書籍といいねが保持される(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();

        $book = Book::factory()->for($user)->create();
        $otherBook = Book::factory()->for($otherUser)->create();

        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create([
                'rating' => 2,
                'comment' => '更新前',
            ]);

        $otherUser->likedReviews()->attach($review->id);

        $likesBefore = DB::table('review_likes')
            ->where('review_id', $review->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->actingAs($user)
            ->put(route('reviews.update', $review), [
                'rating' => '5',
                'comment' => '更新後',
                'user_id' => $otherUser->id,
                'book_id' => $otherBook->id,
            ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', 'レビューを更新しました。');

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 5,
            'comment' => '更新後',
        ]);

        // コメントを消して更新できることも確認する。
        $this->put(route('reviews.update', $review), [
            'rating' => '5',
            'comment' => '',
        ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => 5,
            'comment' => null,
        ]);

        $likesAfter = DB::table('review_likes')
            ->where('review_id', $review->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->assertEquals($likesBefore, $likesAfter);
    }

    public function test_本人が削除すると対象レビューのいいねだけが削除される(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $target = Review::factory()->for($user)->for($book)->create();
        $remaining = Review::factory()->for($user)->for($book)->create();

        $likers = User::factory()->count(3)->create();

        $target->likedByUsers()->attach($likers->modelKeys());
        $remaining->likedByUsers()->attach($likers->first()->id);

        $this->actingAs($user)
            ->delete(route('reviews.destroy', $target))
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('success', 'レビューを削除しました。');

        $this->assertDatabaseMissing('reviews', ['id' => $target->id]);
        $this->assertDatabaseMissing('review_likes', [
            'review_id' => $target->id,
        ]);

        $this->assertDatabaseHas('reviews', ['id' => $remaining->id]);
        $this->assertDatabaseHas('review_likes', [
            'review_id' => $remaining->id,
            'user_id' => $likers->first()->id,
        ]);
        $this->assertDatabaseCount('review_likes', 1);

        $this->assertDatabaseHas('books', ['id' => $book->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);

        foreach ($likers as $liker) {
            $this->assertDatabaseHas('users', ['id' => $liker->id]);
        }
    }

    public function test_本人の編集画面へ既存の評価とコメントと書籍を渡す(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create([
                'rating' => 4,
                'comment' => '編集前のコメント',
            ]);

        $this->actingAs($user, 'web');

        // Bladeを描画せず、Controllerが返すビューとデータを確認する。
        $view = app(ReviewController::class)->edit($review->fresh());

        $this->assertSame('reviews.edit', $view->name());

        $actual = $view->getData()['review'];

        $this->assertSame($review->id, $actual->id);
        $this->assertSame(4, $actual->rating);
        $this->assertSame('編集前のコメント', $actual->comment);
        $this->assertTrue($actual->relationLoaded('book'));
        $this->assertSame($book->id, $actual->book->id);
        $this->assertSame($book->title, $actual->book->title);
    }
}
