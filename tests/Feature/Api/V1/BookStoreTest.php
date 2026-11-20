<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;

class BookStoreTest extends BookWriteTestCase
{
    public function test_未認証で書籍と複数ジャンルを登録できる(): void
    {
        $payload = $this->validPayload();

        $response = $this->post(self::URI, $payload);

        $response
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.author', $payload['author'])
            ->assertJsonPath('data.isbn', $payload['isbn'])
            ->assertJsonPath('data.published_date', $payload['published_date'])
            ->assertJsonPath('data.description', $payload['description'])
            ->assertJsonPath('data.image_url', $payload['image_url'])
            ->assertJsonCount(2, 'data.genres');

        $book = Book::query()->sole();

        $this->assertDatabaseHas('books', [
            'id' => $response->json('data.id'),
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

        $this->assertDatabaseCount('book_genres', 2);
        $this->assertGuest();
    }

    public function test_任意項目を省略すると空値で登録される(): void
    {
        $payload = $this->validPayload();

        unset($payload['description'], $payload['image_url']);

        $response = $this->postJson(self::URI, $payload);

        $response
            ->assertCreated()
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.image_url', null);

        $this->assertDatabaseHas('books', [
            'id' => $response->json('data.id'),
            'description' => null,
            'image_url' => null,
        ]);
    }

    public function test_登録成功時は指定された公開項目と初期集計値を返す(): void
    {
        $response = $this->postJson(
            self::URI,
            $this->validPayload()
        );

        $response
            ->assertCreated()
            ->assertJsonPath('data.average_rating', null)
            ->assertJsonPath('data.reviews_count', 0)
            ->assertJsonPath('data.reviews', []);

        $this->assertEqualsCanonicalizing(
            ['data'],
            array_keys($response->json())
        );

        $this->assertEqualsCanonicalizing([
            'id',
            'title',
            'author',
            'isbn',
            'published_date',
            'description',
            'image_url',
            'genres',
            'average_rating',
            'reviews_count',
            'reviews',
        ], array_keys($response->json('data')));

        foreach ($response->json('data.genres') as $genre) {
            $this->assertEqualsCanonicalizing(
                ['id', 'name'],
                array_keys($genre)
            );
        }
    }

    public function test_不正な入力では登録せず日本語エラーを返す(): void
    {
        $payload = $this->validPayload();
        $payload['title'] = '';

        $before = $this->bookSnapshot();

        $this->post(self::URI, $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonPath(
                'errors.title.0',
                'タイトルは必須です。'
            );

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_未定義の入力項目は保存しない(): void
    {
        $payload = $this->validPayload();
        $payload['id'] = 999999;
        $payload['created_at'] = '2000-01-01 00:00:00';

        $response = $this->postJson(self::URI, $payload);

        $response->assertCreated();

        $book = Book::query()->sole();

        $this->assertNotEquals(999999, $book->id);
        $this->assertNotSame(
            '2000-01-01 00:00:00',
            $book->getRawOriginal('created_at')
        );

        $this->assertEquals($payload['user_id'], $book->user_id);
    }
}
