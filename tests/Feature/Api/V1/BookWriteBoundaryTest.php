<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\User;

class BookWriteBoundaryTest extends BookWriteTestCase
{
    public function test_許容最大文字数で書籍を登録できる(): void
    {
        $payload = $this->boundaryPayload();
        $owner = User::factory()->create();
        $this->authenticateApiUser($owner);

        $response = $this->post(self::URI, $payload)
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonCount(1, 'data.genres');

        $book = Book::findOrFail($response->json('data.id'));

        $this->assertStoredPayload($book, $payload);

        $this->assertSame($owner->id, $book->user_id);
        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('book_genres', 1);

        foreach ([
            'title',
            'author',
            'isbn',
            'published_date',
            'description',
            'image_url',
        ] as $field) {
            $response->assertJsonPath('data.'.$field, $payload[$field]);
        }

        $this->assertGuest('web');
    }

    public function test_許容最大文字数で書籍を更新できる(): void
    {
        $payload = $this->boundaryPayload();
        $book = Book::factory()->create();
        $this->authenticateApiUser($book->user);

        $ownerId = $book->user_id;

        $payload['isbn'] = $book->isbn;

        $response = $this->put(
            self::URI.'/'.$book->id,
            $payload
        )
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonCount(1, 'data.genres');

        $book->refresh();

        $this->assertStoredPayload($book, $payload);

        $this->assertSame($ownerId, $book->user_id);
        $this->assertDatabaseCount('books', 1);
        $this->assertDatabaseCount('book_genres', 1);

        foreach ([
            'title',
            'author',
            'isbn',
            'published_date',
            'description',
            'image_url',
        ] as $field) {
            $response->assertJsonPath('data.'.$field, $payload[$field]);
        }

        $this->assertGuest('web');
    }

    /**
     * 許容上限・下限の入力データを生成する。
     *
     * @return array<string, mixed>
     */
    private function boundaryPayload(): array
    {
        $payload = $this->validPayload();
        $prefix = 'https://example.com/';

        $payload['title'] = str_repeat('書', 255);
        $payload['author'] = str_repeat('著', 255);
        $payload['description'] = str_repeat('説', 10000);
        $payload['image_url'] = $prefix.str_repeat(
            'a',
            2048 - strlen($prefix)
        );
        $payload['genres'] = [$payload['genres'][0]];

        return $payload;
    }

    /**
     * 保存された書籍情報とジャンルを検証する。
     *
     * @param  array<string, mixed>  $payload
     */
    private function assertStoredPayload(
        Book $book,
        array $payload
    ): void {
        $this->assertSame($payload['title'], $book->title);
        $this->assertSame($payload['author'], $book->author);
        $this->assertSame($payload['isbn'], $book->isbn);
        $this->assertSame(
            $payload['published_date'],
            $book->published_date->format('Y-m-d')
        );
        $this->assertSame($payload['description'], $book->description);
        $this->assertSame($payload['image_url'], $book->image_url);

        $this->assertEqualsCanonicalizing(
            $payload['genres'],
            $book->genres()->pluck('genres.id')->all()
        );
    }
}
