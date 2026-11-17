<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreBookIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_書籍のジャンル変更が各ジャンルの書籍数と詳細へ反映される(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $oldGenre = Genre::factory()->create();
        $newGenre = Genre::factory()->create();
        $sharedGenre = Genre::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $book->genres()->sync([$oldGenre->id, $sharedGenre->id]);
        $otherBook = Book::factory()->create();
        $otherBook->genres()->sync([$oldGenre->id]);

        $this->書籍数を確認する($oldGenre, 2);
        $this->書籍数を確認する($newGenre, 0);
        $this->書籍数を確認する($sharedGenre, 1);

        $this->put(route('books.update', $book), [
            'title' => $book->title,
            'author' => $book->author,
            'isbn' => $book->isbn,
            'published_date' => $book->published_date->format('Y-m-d'),
            'description' => $book->description,
            'image_url' => $book->image_url,
            'genres' => [$newGenre->id, $sharedGenre->id],
        ])
            ->assertRedirect(route('books.show', $book))
            ->assertSessionHasNoErrors();

        $this->書籍数を確認する($oldGenre, 1);
        $this->書籍数を確認する($newGenre, 1);
        $this->書籍数を確認する($sharedGenre, 1);
        $this->詳細の書籍を確認する($oldGenre, [$otherBook->id]);
        $this->詳細の書籍を確認する($newGenre, [$book->id]);
        $this->詳細の書籍を確認する($sharedGenre, [$book->id]);

        $this->assertEqualsCanonicalizing(
            [$newGenre->id, $sharedGenre->id],
            $book->genres()->pluck('genres.id')->all()
        );
        $this->assertDatabaseHas('book_genres', [
            'book_id' => $otherBook->id,
            'genre_id' => $oldGenre->id,
        ]);
    }

    public function test_書籍削除で11件から10件になったジャンルのページと件数が更新される(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $genre = Genre::factory()->create();
        $books = Book::factory()->count(11)->create(['user_id' => $owner->id]);
        foreach ($books as $book) {
            $book->genres()->sync([$genre->id]);
        }
        $otherGenre = Genre::factory()->create();
        $otherBook = Book::factory()->create();
        $otherBook->genres()->sync([$otherGenre->id]);
        $deletedBook = $books->last();

        $this->書籍数を確認する($genre, 11);
        $this->get(route('genres.show', ['genre' => $genre, 'page' => 2]))
            ->assertOk()
            ->assertViewHas('books', fn ($books): bool => $books->total() === 11
                && $books->currentPage() === 2 && $books->count() === 1);

        $this->delete(route('books.destroy', $deletedBook))
            ->assertRedirect(route('books.index'));

        $this->書籍数を確認する($genre, 10);
        $this->詳細の書籍を確認する($genre, $books->take(10)->modelKeys());
        $this->get(route('genres.show', ['genre' => $genre, 'page' => 2]))
            ->assertRedirect(route('genres.show', $genre));
        $this->書籍数を確認する($otherGenre, 1);
        $this->詳細の書籍を確認する($otherGenre, [$otherBook->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $deletedBook->id]);
        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
    }

    public function test_最後の書籍を削除するとジャンルは0冊表示となり削除できる(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $genre = Genre::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $book->genres()->sync([$genre->id]);

        $this->delete(route('books.destroy', $book))
            ->assertRedirect(route('books.index'));

        $this->書籍数を確認する($genre, 0);
        $this->詳細の書籍を確認する($genre, []);
        $this->get(route('genres.show', $genre))
            ->assertOk()
            ->assertSeeText('このジャンルの書籍はまだ登録されていません。');
        $this->assertDatabaseHas('genres', ['id' => $genre->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id]);

        $this->delete(route('genres.destroy', $genre))
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas('success', 'ジャンルを削除しました。');
        $this->assertDatabaseMissing('genres', ['id' => $genre->id]);
    }

    private function 書籍数を確認する(Genre $genre, int $expected): void
    {
        $this->get(route('genres.index'))
            ->assertOk()
            ->assertViewHas('genres', fn ($genres): bool => $genres->firstWhere('id', $genre->id)?->books_count === $expected
            );
    }

    private function 詳細の書籍を確認する(Genre $genre, array $expectedIds): void
    {
        $response = $this->get(route('genres.show', $genre))->assertOk();
        $books = $response->viewData('books');

        $this->assertSame(count($expectedIds), $books->total());
        $this->assertEqualsCanonicalizing($expectedIds, $books->modelKeys());
    }
}
