<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;

class BookUpdateTest extends BookWriteTestCase
{
    public function test_未認証で書籍とジャンルを更新できる(): void
    {
        $payload = $this->validPayload();

        $book = Book::factory()->create([
            'user_id' => $payload['user_id'],
            'isbn' => $payload['isbn'],
        ]);

        $oldGenre = Genre::factory()->create();
        $book->genres()->sync([$oldGenre->id]);

        unset($payload['user_id']);

        $response = $this->put(self::URI.'/'.$book->id, $payload);

        $response
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.author', $payload['author'])
            ->assertJsonPath('data.isbn', $payload['isbn'])
            ->assertJsonPath('data.published_date', $payload['published_date'])
            ->assertJsonPath('data.description', $payload['description'])
            ->assertJsonPath('data.image_url', $payload['image_url'])
            ->assertJsonCount(2, 'data.genres');

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'user_id' => $book->user_id,
            ...collect($payload)->except('genres')->all(),
        ]);

        $this->assertEqualsCanonicalizing(
            $payload['genres'],
            $book->genres()->pluck('genres.id')->all()
        );

        $this->assertEqualsCanonicalizing(
            $payload['genres'],
            array_column($response->json('data.genres'), 'id')
        );

        $this->assertDatabaseMissing('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $oldGenre->id,
        ]);

        $this->assertDatabaseHas('genres', ['id' => $oldGenre->id]);
        $this->assertDatabaseCount('books', 1);
        $this->assertGuest();
    }

    public function test_任意項目を省略すると空値で更新される(): void
    {
        $payload = $this->validPayload();

        $book = Book::factory()->create([
            'description' => '更新前の説明',
            'image_url' => 'https://example.com/old.jpg',
        ]);

        unset(
            $payload['user_id'],
            $payload['description'],
            $payload['image_url']
        );

        $this->putJson(self::URI.'/'.$book->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.image_url', null);

        $this->assertDatabaseHas('books', [
            'id' => $book->id,
            'description' => null,
            'image_url' => null,
        ]);
    }

    public function test_他の書籍と重複する識別番号では更新しない(): void
    {
        $payload = $this->validPayload();
        $book = Book::factory()->create();

        Book::factory()->create([
            'isbn' => $payload['isbn'],
        ]);

        unset($payload['user_id']);

        $before = $this->bookSnapshot();

        $this->put(self::URI.'/'.$book->id, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonPath(
                'errors.isbn.0',
                'そのISBNは既に使用されています。'
            );

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_登録者を指定すると更新しない(): void
    {
        $payload = $this->validPayload();
        $book = Book::factory()->create();

        $before = $this->bookSnapshot();

        $this->put(self::URI.'/'.$book->id, $payload)
            ->assertUnprocessable()
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonPath(
                'errors.user_id.0',
                '登録者IDは変更できません。'
            );

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_更新後も既存レビューと評価情報を保持する(): void
    {
        $payload = $this->validPayload();
        $book = Book::factory()->create();

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'rating' => 4,
            'comment' => '更新前のレビュー',
        ]);

        unset($payload['user_id']);

        $before = $this->bookSnapshot();

        $this->putJson(self::URI.'/'.$book->id, $payload)
            ->assertOk()
            ->assertJsonPath('data.average_rating', 4)
            ->assertJsonPath('data.reviews_count', 1)
            ->assertJsonCount(1, 'data.reviews')
            ->assertJsonPath('data.reviews.0.id', $review->id)
            ->assertJsonPath('data.reviews.0.user_name', $review->user->name)
            ->assertJsonPath('data.reviews.0.rating', 4)
            ->assertJsonPath('data.reviews.0.comment', $review->comment);

        $after = $this->bookSnapshot();

        foreach ([
            'users',
            'genres',
            'reviews',
            'favorites',
            'review_likes',
        ] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }
    }
}
