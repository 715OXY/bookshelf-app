<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_認証ユーザーを登録者として書籍と複数ジャンルを保存する(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $genres = Genre::factory()->count(2)->create();

        $payload = $this->入力データ($genres->modelKeys());
        $payload['user_id'] = $otherUser->id;

        $response = $this->actingAs($owner, 'web')
            ->post(route('books.store'), $payload);

        $book = Book::query()
            ->where('isbn', $payload['isbn'])
            ->firstOrFail();

        $response
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('success', '書籍を登録しました。');

        $this->assertDatabaseCount('books', 1);

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'user_id' => $owner->id,
            'title' => $payload['title'],
            'author' => $payload['author'],
            'isbn' => $payload['isbn'],
            'published_date' => $payload['published_date'],
            'description' => null,
            'image_url' => null,
        ]);

        $this->assertEqualsCanonicalizing(
            $genres->modelKeys(),
            $book->genres()->pluck('genres.id')->all()
        );

        $this->assertDatabaseCount('book_genres', 2);
    }

    public function test_所有者を変えずに書籍情報とジャンルを更新する(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $genres = Genre::factory()->count(3)->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'isbn' => '9781234567890',
        ]);

        $oldGenre = $genres[0];
        $book->genres()->sync([$oldGenre->id]);

        $selectedIds = [$genres[1]->id, $genres[2]->id];

        $payload = $this->入力データ($selectedIds);
        $payload['title'] = '更新後の書籍';
        $payload['description'] = '更新後の説明';
        $payload['image_url'] = 'https://example.com/cover.jpg';
        $payload['user_id'] = $otherUser->id;

        $this->actingAs($owner, 'web')
            ->put(route('books.update', $book), $payload)
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHas('success', '書籍情報を更新しました。');

        $this->assertDatabaseCount('books', 1);

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'user_id' => $owner->id,
            'title' => $payload['title'],
            'author' => $payload['author'],
            'isbn' => $payload['isbn'],
            'published_date' => $payload['published_date'],
            'description' => $payload['description'],
            'image_url' => $payload['image_url'],
        ]);

        $this->assertEqualsCanonicalizing(
            $selectedIds,
            $book->genres()->pluck('genres.id')->all()
        );

        $this->assertDatabaseMissing('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $oldGenre->id,
        ]);

        $this->assertDatabaseHas('genres', [
            'id' => $oldGenre->id,
        ]);
    }

    public function test_書籍削除時に対象の関連データを削除する(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
        ]);
        $book->genres()->sync([$genre->id]);
        $book->favoritedByUsers()->sync([$otherUser->id]);

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $otherUser->id,
            'rating' => 4,
        ]);
        $review->likedByUsers()->sync([$owner->id]);

        $otherBook = Book::factory()->create([
            'user_id' => $otherUser->id,
        ]);

        $this->actingAs($owner, 'web')
            ->delete(route('books.destroy', $book))
            ->assertRedirect(route('books.index'))
            ->assertSessionHas('success', '書籍を削除しました。');

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

        $this->assertDatabaseHas('books', [
            'id' => $otherBook->id,
        ]);
        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $owner->id,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $otherUser->id,
        ]);
    }

    /**
     * @param  array<int, int>  $genreIds
     * @return array<string, mixed>
     */
    private function 入力データ(array $genreIds): array
    {
        return [
            'title' => 'CRUD検証用の書籍',
            'author' => 'テスト著者',
            'isbn' => '9781234567890',
            'published_date' => '2026-11-01',
            'description' => null,
            'image_url' => null,
            'genres' => $genreIds,
        ];
    }
}
