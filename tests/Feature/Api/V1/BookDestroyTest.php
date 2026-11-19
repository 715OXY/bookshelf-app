<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;

class BookDestroyTest extends BookWriteTestCase
{
    public function test_未認証で書籍と関連データを削除できる(): void
    {
        $book = $this->createBookWithRelations();

        $review = $book->reviews()->firstOrFail();

        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseHas('favorites', [
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseHas('review_likes', [
            'review_id' => $review->id,
        ]);

        $this->delete(self::URI.'/'.$book->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('books', [
            'id' => $book->id,
        ]);
        $this->assertDatabaseMissing('reviews', [
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseMissing('review_likes', [
            'review_id' => $review->id,
        ]);
        $this->assertDatabaseMissing('favorites', [
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseMissing('book_genres', [
            'book_id' => $book->id,
        ]);

        $this->assertGuest();
    }

    public function test_削除対象以外の書籍と関連データを保持する(): void
    {
        $book = $this->createBookWithRelations();
        $otherBook = $this->createBookWithRelations();

        $reviewIds = $book->reviews()->pluck('id')->all();

        $before = $this->bookSnapshot();

        $this->deleteJson(self::URI.'/'.$book->id)
            ->assertNoContent();

        $expected = $before;

        $deleteConditions = [
            'books' => fn (array $row): bool => $row['id'] === $book->id,
            'reviews' => fn (array $row): bool => $row['book_id'] === $book->id,
            'review_likes' => fn (array $row): bool => in_array($row['review_id'], $reviewIds, true),
            'favorites' => fn (array $row): bool => $row['book_id'] === $book->id,
            'book_genres' => fn (array $row): bool => $row['book_id'] === $book->id,
        ];

        foreach ($deleteConditions as $table => $condition) {
            $expected[$table] = array_values(array_filter(
                $expected[$table],
                fn (array $row): bool => ! $condition($row)
            ));
        }

        $this->assertSame($expected, $this->bookSnapshot());

        $this->assertDatabaseHas('books', [
            'id' => $otherBook->id,
        ]);
    }

    public function test_関連データのない書籍も削除できる(): void
    {
        $book = Book::factory()->create();
        $book->genres()->detach();

        $this->assertDatabaseMissing('book_genres', [
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseMissing('reviews', [
            'book_id' => $book->id,
        ]);
        $this->assertDatabaseMissing('favorites', [
            'book_id' => $book->id,
        ]);

        $this->deleteJson(self::URI.'/'.$book->id)
            ->assertNoContent();

        $this->assertDatabaseMissing('books', [
            'id' => $book->id,
        ]);
    }

    /**
     * 関連データを持つ検証用書籍を生成する。
     */
    private function createBookWithRelations(): Book
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $genres = Genre::factory()->count(2)->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
        ]);

        $book->genres()->sync($genres->modelKeys());
        $book->favoritedByUsers()->sync([$otherUser->id]);

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $otherUser->id,
            'rating' => 4,
        ]);

        $review->likedByUsers()->sync([$owner->id]);

        return $book;
    }
}
