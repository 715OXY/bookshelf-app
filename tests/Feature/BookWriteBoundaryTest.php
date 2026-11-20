<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BookWriteBoundaryTest extends TestCase
{
    use RefreshDatabase;

    public function test_許容最大文字数で書籍を登録できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();
        $payload = $this->境界値入力($genre);

        $response = $this->actingAs($user, 'web')
            ->post(route('books.store'), $payload);

        $book = Book::query()
            ->where('isbn', $payload['isbn'])
            ->firstOrFail();

        $response
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors();

        $this->保存内容を確認する($book, $user, $payload);
        $this->assertDatabaseCount('books', 1);
    }

    public function test_許容最大文字数で書籍を更新できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $user->id,
        ]);

        $payload = $this->境界値入力($genre);
        $payload['isbn'] = $book->isbn;

        $this->actingAs($user, 'web')
            ->put(route('books.update', $book), $payload)
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors();

        $this->保存内容を確認する(
            $book->fresh(),
            $user,
            $payload
        );

        $this->assertDatabaseCount('books', 1);
    }

    private function 境界値入力(Genre $genre): array
    {
        return [
            'title' => str_repeat('書', 255),
            'author' => str_repeat('著', 255),
            'isbn' => '9781234567890',
            'published_date' => '2024-02-29',
            'description' => str_repeat('説', 10000),
            'image_url' => 'https://example.com/'.str_repeat('a', 2028),
            'genres' => [$genre->id],
        ];
    }

    private function 保存内容を確認する(
        Book $book,
        User $user,
        array $payload
    ): void {
        $this->assertSame(255, mb_strlen($book->title));
        $this->assertSame(255, mb_strlen($book->author));
        $this->assertSame(10000, mb_strlen($book->description));
        $this->assertSame(2048, mb_strlen($book->image_url));

        $this->assertSame($payload['title'], $book->title);
        $this->assertSame($payload['author'], $book->author);
        $this->assertSame($payload['isbn'], $book->isbn);
        $this->assertSame(
            $payload['published_date'],
            $book->published_date->format('Y-m-d')
        );
        $this->assertSame($payload['description'], $book->description);
        $this->assertSame($payload['image_url'], $book->image_url);
        $this->assertSame($user->id, $book->user_id);
        $this->assertSame(
            $payload['genres'],
            $book->genres()->pluck('genres.id')->all()
        );

        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $payload['genres'][0],
        ]);
    }
}
