<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
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

        // 対象レビューに複数のいいねを付ける。
        $additionalLikers = User::factory()->count(2)->create();

        foreach ($additionalLikers as $liker) {
            $liker->likedReviews()->attach($target->id);
        }

        // レビュー削除後も残すべき書籍の関連データ。
        $user->favoriteBooks()->attach($book->id);
        $genre = $book->genres()->sole();

        $this->assertSame(3, $target->likedByUsers()->count());

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

        $this->assertDatabaseHas('favorites', [
            'user_id' => $user->id,
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $genre->id,
        ]);
        $this->assertDatabaseHas('genres', ['id' => $genre->id]);

        foreach ($additionalLikers as $liker) {
            $this->assertDatabaseHas('users', ['id' => $liker->id]);
        }
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

    public function test_いいねがないレビューも削除できる(): void
    {
        $review = Review::factory()->create();

        $reviewId = $review->id;
        $bookId = $review->book_id;
        $userId = $review->user_id;

        $this->assertSame(0, $review->likedByUsers()->count());

        $review->delete();

        $this->assertDatabaseMissing('reviews', ['id' => $reviewId]);
        $this->assertDatabaseMissing('review_likes', [
            'review_id' => $reviewId,
        ]);
        $this->assertDatabaseHas('books', ['id' => $bookId]);
        $this->assertDatabaseHas('users', ['id' => $userId]);
    }

    public function test_レビューの評価とコメントを更新してもいいねは保持される(): void
    {
        $review = Review::factory()->create([
            'rating' => 2,
            'comment' => '更新前のコメント',
        ]);

        $likers = User::factory()->count(3)->create();
        $review->likedByUsers()->attach($likers->modelKeys());

        $bookId = $review->book_id;
        $userId = $review->user_id;

        $likesBefore = DB::table('review_likes')
            ->where('review_id', $review->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->assertCount(3, $likesBefore);

        $review->update([
            'rating' => 5,
            'comment' => '更新後のコメント',
        ]);

        $this->assertDatabaseHas('reviews', [
            'id' => $review->id,
            'user_id' => $userId,
            'book_id' => $bookId,
            'rating' => 5,
            'comment' => '更新後のコメント',
        ]);

        $likesAfter = DB::table('review_likes')
            ->where('review_id', $review->id)
            ->orderBy('id')
            ->get()
            ->toArray();

        $this->assertEquals($likesBefore, $likesAfter);
    }

    public function test_同じメールアドレスをデータベースへ重複登録できない(): void
    {
        $user = User::factory()->create([
            'email' => 'duplicate@example.com',
        ]);

        $before = $user->fresh()->getRawOriginal();

        try {
            DB::table('users')->insert([
                'name' => '別のユーザー',
                'email' => $user->email,
                'password' => $user->password,
            ]);
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]);
            $this->assertStringContainsString(
                'users_email_unique',
                $exception->getMessage()
            );

            $this->assertDatabaseCount('users', 1);
            $this->assertSame(
                $before,
                $user->fresh()->getRawOriginal()
            );

            return;
        }

        $this->fail('メールアドレスが重複登録されました。');
    }

    public function test_同じジャンル名をデータベースへ重複登録できない(): void
    {
        $genre = Genre::factory()->create([
            'name' => '技術書',
        ]);

        $before = $genre->fresh()->getRawOriginal();

        try {
            DB::table('genres')->insert([
                'name' => $genre->name,
            ]);
        } catch (QueryException $exception) {
            $this->assertSame(1062, $exception->errorInfo[1]);
            $this->assertStringContainsString(
                'genres_name_unique',
                $exception->getMessage()
            );

            $this->assertDatabaseCount('genres', 1);
            $this->assertSame(
                $before,
                $genre->fresh()->getRawOriginal()
            );

            return;
        }

        $this->fail('ジャンル名が重複登録されました。');
    }

    /**
     * @dataProvider referencedUserProvider
     */
    public function test_関連データから参照されているユーザーは削除できない(
        string $table,
        string $constraint
    ): void {
        $user = User::factory()->create();
        $owner = User::factory()->create();
        $book = Book::factory()->for($owner)->create();

        $row = match ($table) {
            'reviews' => [
                'user_id' => $user->id,
                'book_id' => $book->id,
                'rating' => 3,
            ],
            'favorites' => [
                'user_id' => $user->id,
                'book_id' => $book->id,
            ],
            'review_likes' => [
                'user_id' => $user->id,
                'review_id' => Review::factory()
                    ->for($owner)
                    ->for($book)
                    ->create()->id,
            ],
        };

        DB::table($table)->insert($row);

        $before = $user->fresh()->getRawOriginal();

        try {
            $user->delete();
        } catch (QueryException $exception) {
            $this->assertSame(1451, $exception->errorInfo[1]);
            $this->assertStringContainsString(
                $constraint,
                $exception->getMessage()
            );

            $this->assertSame(
                $before,
                $user->fresh()->getRawOriginal()
            );

            $this->assertDatabaseHas($table, $row);
            $this->assertDatabaseHas('users', ['id' => $owner->id]);
            $this->assertDatabaseHas('books', ['id' => $book->id]);

            return;
        }

        $this->fail('関連データから参照されているユーザーが削除されました。');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function referencedUserProvider(): array
    {
        return [
            'reviews' => [
                'reviews',
                'reviews_user_id_foreign',
            ],
            'favorites' => [
                'favorites',
                'favorites_user_id_foreign',
            ],
            'review likes' => [
                'review_likes',
                'review_likes_user_id_foreign',
            ],
        ];
    }

    /**
     * @dataProvider validRatingBoundaryProvider
     */
    public function test_正常境界値の評価をデータベースへ登録できる(
        int $rating
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();

        $reviewId = DB::table('reviews')->insertGetId([
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => $rating,
            'comment' => null,
        ]);

        $this->assertDatabaseCount('reviews', 1);

        $this->assertDatabaseHas('reviews', [
            'id' => $reviewId,
            'user_id' => $user->id,
            'book_id' => $book->id,
            'rating' => $rating,
            'comment' => null,
        ]);

        $review = Review::findOrFail($reviewId);

        $this->assertSame($rating, $review->rating);
        $this->assertNull($review->comment);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function validRatingBoundaryProvider(): array
    {
        return [
            'minimum' => [1],
            'maximum' => [5],
        ];
    }

    /**
     * @dataProvider missingForeignKeyProvider
     */
    public function test_存在しない外部キーを指定して登録できない(
        string $table,
        string $column,
        string $parentTable,
        string $constraint
    ): void {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $genre = Genre::factory()->create();
        $review = Review::factory()
            ->for($user)
            ->for($book)
            ->create();

        $row = match ($table) {
            'reviews' => [
                'user_id' => $user->id,
                'book_id' => $book->id,
                'rating' => 3,
            ],
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

        $missingId = (int) DB::table($parentTable)->max('id') + 1;

        $this->assertDatabaseMissing($parentTable, [
            'id' => $missingId,
        ]);

        $row[$column] = $missingId;

        $before = DB::table($table)
            ->orderBy('id')
            ->get()
            ->toArray();

        try {
            DB::table($table)->insert($row);
        } catch (QueryException $exception) {
            $this->assertSame(1452, $exception->errorInfo[1]);
            $this->assertStringContainsString(
                $constraint,
                $exception->getMessage()
            );

            $this->assertDatabaseMissing($table, $row);

            $after = DB::table($table)
                ->orderBy('id')
                ->get()
                ->toArray();

            $this->assertEquals($before, $after);

            return;
        }

        $this->fail('存在しない外部キーを持つデータが登録されました。');
    }

    /**
     * @return array<string, array{string, string, string, string}>
     */
    public static function missingForeignKeyProvider(): array
    {
        return [
            'reviews user' => [
                'reviews',
                'user_id',
                'users',
                'reviews_user_id_foreign',
            ],
            'reviews book' => [
                'reviews',
                'book_id',
                'books',
                'reviews_book_id_foreign',
            ],
            'book genres book' => [
                'book_genres',
                'book_id',
                'books',
                'book_genres_book_id_foreign',
            ],
            'favorites user' => [
                'favorites',
                'user_id',
                'users',
                'favorites_user_id_foreign',
            ],
            'favorites book' => [
                'favorites',
                'book_id',
                'books',
                'favorites_book_id_foreign',
            ],
            'review likes user' => [
                'review_likes',
                'user_id',
                'users',
                'review_likes_user_id_foreign',
            ],
            'review likes review' => [
                'review_likes',
                'review_id',
                'reviews',
                'review_likes_review_id_foreign',
            ],
        ];
    }
}
