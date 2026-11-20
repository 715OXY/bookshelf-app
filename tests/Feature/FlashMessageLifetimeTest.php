<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class FlashMessageLifetimeTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('通知の操作と次回閲覧')]
    public function test_操作直後の通知は次回閲覧に残らない(
        string $operation,
        string $message,
        bool $reload
    ): void {
        $user = User::factory()->create();
        $this->actingAs($user);

        $genre = Genre::factory()->create([
            'name' => '通知検証ジャンル',
        ]);

        $book = Book::factory()->for($user)->create();
        $book->genres()->sync([$genre->id]);

        $unusedGenre = Genre::factory()->create();

        $payload = [
            'title' => '通知検証書籍',
            'author' => '通知検証著者',
            'isbn' => '9781234567890',
            'published_date' => '2026-11-01',
            'description' => '通知期間の検証',
            'image_url' => null,
            'genres' => [$genre->id],
        ];

        $bookCount = Book::count();
        $genreCount = Genre::count();

        $response = match ($operation) {
            'book-store' => $this->post(
                route('books.store'), $payload
            ),
            'book-update' => $this->put(
                route('books.update', $book), $payload
            ),
            'book-destroy' => $this->delete(
                route('books.destroy', $book)
            ),
            'genre-store' => $this->post(
                route('genres.store'), ['name' => '新規ジャンル']
            ),
            'genre-update' => $this->put(
                route('genres.update', $genre),
                ['name' => '変更後ジャンル']
            ),
            'genre-destroy' => $this->delete(
                route('genres.destroy', $unusedGenre)
            ),
            'genre-blocked' => $this->delete(
                route('genres.destroy', $genre)
            ),
        };

        $destination = match ($operation) {
            'book-store' => route(
                'books.show',
                Book::where('isbn', $payload['isbn'])->firstOrFail()
            ),
            'book-update' => route('books.show', $book),
            'book-destroy' => route('books.index'),
            default => route('genres.index'),
        };

        $key = $operation === 'genre-blocked'
            ? 'error' : 'success';

        $response->assertRedirect($destination)
            ->assertSessionHas($key, $message)
            ->assertSessionMissing(
                $key === 'success' ? 'error' : 'success'
            )
            ->assertSessionHasNoErrors();

        $this->get($destination)
            ->assertOk()
            ->assertSeeText($message)
            ->assertSessionMissing($key);

        $next = $reload
            ? $destination
            : ($destination === route('genres.index')
                ? route('books.index')
                : route('genres.index'));

        $this->get($next)
            ->assertOk()
            ->assertDontSeeText($message)
            ->assertSessionMissing($key);

        $this->assertAuthenticatedAs($user);

        $this->assertDatabaseCount('books', $bookCount + match ($operation) {
            'book-store' => 1,
            'book-destroy' => -1,
            default => 0,
        });

        $this->assertDatabaseCount('genres', $genreCount + match ($operation) {
            'genre-store' => 1,
            'genre-destroy' => -1,
            default => 0,
        });

        match ($operation) {
            'book-store', 'book-update' => $this->assertDatabaseHas(
                'books', ['isbn' => $payload['isbn'], 'title' => $payload['title']]
            ),
            'book-destroy' => $this->assertDatabaseMissing(
                'books', ['id' => $book->id]
            ),
            'genre-store' => $this->assertDatabaseHas(
                'genres', ['name' => '新規ジャンル']
            ),
            'genre-update' => $this->assertDatabaseHas(
                'genres', ['id' => $genre->id, 'name' => '変更後ジャンル']
            ),
            'genre-destroy' => $this->assertDatabaseMissing(
                'genres', ['id' => $unusedGenre->id]
            ),
            'genre-blocked' => $this->assertDatabaseHas(
                'book_genres', ['book_id' => $book->id, 'genre_id' => $genre->id]
            ),
        };
    }

    public static function 通知の操作と次回閲覧(): array
    {
        $operations = [
            'book-store' => '書籍を登録しました。',
            'book-update' => '書籍情報を更新しました。',
            'book-destroy' => '書籍を削除しました。',
            'genre-store' => 'ジャンルを登録しました。',
            'genre-update' => 'ジャンルを更新しました。',
            'genre-destroy' => 'ジャンルを削除しました。',
            'genre-blocked' => '書籍が紐付いているため、このジャンルは削除できません。',
        ];

        $cases = [];

        foreach ($operations as $operation => $message) {
            $cases[$operation.' 再読み込み'] = [$operation, $message, true];
            $cases[$operation.' 別画面'] = [$operation, $message, false];
        }

        return $cases;
    }
}
