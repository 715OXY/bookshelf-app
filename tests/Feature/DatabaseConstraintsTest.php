<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DatabaseConstraintsTest extends TestCase
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

    /**
     * @dataProvider invalidRatingProvider
     */
    public function test_範囲外の評価値は登録できない(int $rating): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('reviews_rating_check');

        DB::table('reviews')->insert([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => $rating,
            'comment' => null,
        ]);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function invalidRatingProvider(): array
    {
        return [
            'below minimum' => [0],
            'above maximum' => [6],
        ];
    }

    /**
     * @dataProvider duplicatePivotProvider
     */
    public function test_中間テーブルに同じ組み合わせを重複登録できない(
        string $table,
        string $constraint
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $genre = $book->genres()->sole();
        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create();

        $row = match ($table) {
            'book_genres' => [
                'book_id' => $book->id,
                'genre_id' => $genre->id,
            ],
            'favorites' => [
                'user_id' => $user->id,
                'book_id' => $book->id,
            ],
            'review_likes' => [
                'user_id' => $user->id,
                'review_id' => $review->id,
            ],
        };

        // BookFactoryですでに作成される紐付け以外は、1件目を登録する。
        if ($table !== 'book_genres') {
            DB::table($table)->insert($row);
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage($constraint);

        DB::table($table)->insert($row);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function duplicatePivotProvider(): array
    {
        return [
            'book genres' => [
                'book_genres',
                'book_genres_book_id_genre_id_unique',
            ],
            'favorites' => [
                'favorites',
                'favorites_user_id_book_id_unique',
            ],
            'review likes' => [
                'review_likes',
                'review_likes_user_id_review_id_unique',
            ],
        ];
    }

    public function test_書籍削除時に関連データが連鎖削除される(): void
    {
        $owner = User::factory()->create();
        $reviewer = User::factory()->create();
        $book = Book::factory()->for($owner)->create();
        $genre = $book->genres()->sole();
        $review = Review::factory()
            ->for($reviewer)
            ->for($book)
            ->create();

        $owner->favoriteBooks()->attach($book->id);
        $owner->likedReviews()->attach($review->id);

        $book->delete();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('favorites', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('review_likes', [
            'review_id' => $review->id,
        ]);

        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $reviewer->id]);
    }

    public function test_レビュー削除時に対象レビューのいいねだけが削除される(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $reviews = Review::factory()
            ->count(2)
            ->for($user)
            ->for($book)
            ->create();

        $target = $reviews->first();
        $remaining = $reviews->last();

        $user->likedReviews()->attach([
            $target->id,
            $remaining->id,
        ]);

        $target->delete();

        $this->assertDatabaseMissing('reviews', ['id' => $target->id]);
        $this->assertDatabaseMissing('review_likes', [
            'review_id' => $target->id,
        ]);

        $this->assertDatabaseHas('reviews', ['id' => $remaining->id]);
        $this->assertDatabaseHas('review_likes', [
            'user_id' => $user->id,
            'review_id' => $remaining->id,
        ]);
        $this->assertDatabaseHas('books', ['id' => $book->id]);
        $this->assertDatabaseHas('users', ['id' => $user->id]);
    }

    public function test_書籍に紐付くジャンルは削除できない(): void
    {
        $book = Book::factory()->create();
        $genre = $book->genres()->sole();

        try {
            $genre->delete();
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
            $this->assertStringContainsString(
                'book_genres_genre_id_foreign',
                $exception->getMessage()
            );
            $this->assertDatabaseHas('genres', ['id' => $genre->id]);
            $this->assertDatabaseHas('book_genres', [
                'book_id' => $book->id,
                'genre_id' => $genre->id,
            ]);

            return;
        }

        $this->fail('書籍に紐付くジャンルが削除されました。');
    }

    public function test_書籍から参照されているユーザーは削除できない(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        try {
            $user->delete();
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
            $this->assertStringContainsString(
                'books_user_id_foreign',
                $exception->getMessage()
            );
            $this->assertDatabaseHas('users', ['id' => $user->id]);
            $this->assertDatabaseHas('books', [
                'id' => $book->id,
                'user_id' => $user->id,
            ]);

            return;
        }

        $this->fail('書籍から参照されているユーザーが削除されました。');
    }
}
