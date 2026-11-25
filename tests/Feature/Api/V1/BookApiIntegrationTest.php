<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;

class BookApiIntegrationTest extends BookWriteTestCase
{
    public function test_認証会員が書籍の登録から削除まで一貫して操作できる(): void
    {
        $payload = $this->validPayload();
        $owner = User::factory()->create();
        $this->authenticateApiUser($owner);

        $reader = User::factory()->create();
        $newGenres = Genre::factory()->count(2)->create();

        $otherBook = Book::factory()->create([
            'isbn' => '9781234567891',
        ]);

        $before = $this->bookSnapshot();

        // 書籍を登録する。
        $response = $this->post(self::URI, $payload)
            ->assertCreated()
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.isbn', $payload['isbn'])
            ->assertJsonPath('data.reviews_count', 0)
            ->assertJsonPath('data.average_rating', null);

        $bookId = $response->json('data.id');
        $book = Book::findOrFail($bookId);
        $uri = self::URI.'/'.$bookId;

        // 一覧と詳細に登録結果が反映される。
        $this->get(self::URI.'?keyword='.$payload['isbn'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bookId);

        $this->get($uri)
            ->assertOk()
            ->assertJsonPath('data.id', $bookId)
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonCount(2, 'data.genres')
            ->assertJsonCount(0, 'data.reviews');

        // 既存Web機能に相当する関連データを準備する。
        $review = Review::factory()->create([
            'book_id' => $bookId,
            'user_id' => $reader->id,
            'rating' => 4,
        ]);

        $book->favoritedByUsers()->sync([$reader->id]);
        $review->likedByUsers()->sync([$owner->id]);

        $beforeUpdate = $this->bookSnapshot();

        // 書籍情報とジャンルを更新する。
        $updated = $payload;

        $updated['title'] = '統合テストによる更新後の書籍';
        $updated['isbn'] = '9781234567892';
        $updated['genres'] = $newGenres->modelKeys();

        $response = $this->put($uri, $updated)
            ->assertOk()
            ->assertJsonPath('data.id', $bookId)
            ->assertJsonPath('data.title', $updated['title'])
            ->assertJsonPath('data.isbn', $updated['isbn'])
            ->assertJsonPath('data.reviews_count', 1)
            ->assertJsonCount(1, 'data.reviews');

        $this->assertEquals(
            4,
            $response->json('data.average_rating')
        );

        $this->assertEqualsCanonicalizing(
            $updated['genres'],
            array_column($response->json('data.genres'), 'id')
        );

        $afterUpdate = $this->bookSnapshot();

        foreach ([
            'users',
            'genres',
            'reviews',
            'favorites',
            'review_likes',
        ] as $table) {
            $this->assertSame(
                $beforeUpdate[$table],
                $afterUpdate[$table]
            );
        }

        $this->assertDatabaseHas('books', [
            'id' => $bookId,
            'user_id' => $owner->id,
            'isbn' => $updated['isbn'],
        ]);

        // 一覧と詳細に更新結果が反映される。
        $this->get(self::URI.'?keyword='.$payload['isbn'])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $this->get(self::URI.'?keyword='.$updated['isbn'])
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $bookId)
            ->assertJsonPath('data.0.title', $updated['title']);

        $this->get($uri)
            ->assertOk()
            ->assertJsonPath('data.title', $updated['title'])
            ->assertJsonPath('data.reviews.0.id', $review->id)
            ->assertJsonPath('data.reviews_count', 1);

        // 書籍と関連データを削除する。
        $this->delete($uri)->assertNoContent();

        $this->get($uri)
            ->assertNotFound()
            ->assertExactJson([
                'message' => '指定された書籍は存在しません。',
            ]);

        $this->get(self::URI.'?keyword='.$updated['isbn'])
            ->assertOk()
            ->assertJsonCount(0, 'data');

        // 操作対象外の書籍と全既存データが保持される。
        $this->assertDatabaseHas('books', [
            'id' => $otherBook->id,
        ]);

        $this->assertGuest('web');
        $this->assertSame($before, $this->bookSnapshot());
    }
}
