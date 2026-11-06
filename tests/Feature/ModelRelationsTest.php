<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ModelRelationsTest extends TestCase
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

    public function test_書籍とレビューの関連を双方向に取得できる(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $review = Review::factory()
            ->for($reviewer)
            ->for($book)
            ->create();

        $this->assertTrue($book->user->is($owner));
        $this->assertTrue($owner->books->contains($book));

        $this->assertTrue($review->user->is($reviewer));
        $this->assertTrue($reviewer->reviews->contains($review));

        $this->assertTrue($review->book->is($book));
        $this->assertTrue($book->reviews->contains($review));

        $genre = $book->genres()->sole();

        $this->assertTrue($genre->books->contains($book));
        $this->assertNotNull($genre->pivot->created_at);
        $this->assertNotNull($genre->pivot->updated_at);
    }

    public function test_同一ユーザーが同一書籍に複数レビューを投稿できる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $reviews = Review::factory()
            ->count(2)
            ->for($user)
            ->for($book)
            ->create();

        $this->assertCount(2, $book->reviews);
        $this->assertCount(2, $user->reviews);

        foreach ($reviews as $review) {
            $this->assertTrue($book->reviews->contains($review));
            $this->assertTrue($user->reviews->contains($review));
        }
    }

    public function test_自分の書籍のお気に入り登録と自分のレビューへのいいねができる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create();

        $user->favoriteBooks()->attach($book->id);
        $user->likedReviews()->attach($review->id);

        $favorite = $user->favoriteBooks()->sole();
        $likedReview = $user->likedReviews()->sole();

        $this->assertTrue($favorite->is($book));
        $this->assertTrue($book->favoritedByUsers->contains($user));
        $this->assertTrue($likedReview->is($review));
        $this->assertTrue($review->likedByUsers->contains($user));

        $this->assertNotNull($favorite->pivot->created_at);
        $this->assertNotNull($favorite->pivot->updated_at);
        $this->assertNotNull($likedReview->pivot->created_at);
        $this->assertNotNull($likedReview->pivot->updated_at);
    }
}
