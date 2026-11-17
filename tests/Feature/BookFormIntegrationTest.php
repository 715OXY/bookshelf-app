<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class BookFormIntegrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_書籍編集に既存値と出版日と選択ジャンルを表示する(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $genres = Genre::factory()->count(2)->create();
        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'published_date' => '2026-10-24',
        ]);
        $book->genres()->sync($genres->modelKeys());

        $response = $this->get(route('books.edit', $book))->assertOk();
        $expected = $book->only(['title', 'author', 'isbn', 'description', 'image_url']);
        $expected['published_date'] = '2026-10-24';
        $expected['genres'] = $genres->modelKeys();

        $this->入力値を確認する($response, $expected);
        $response->assertSee('action="'.route('books.update', $book).'"', false)
            ->assertSee('href="'.route('books.show', $book).'"', false);
    }

    public function test_書籍登録エラー後に各入力値と選択ジャンルを再表示する(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();
        $payload = $this->不正ISBNの入力([$genre->id]);

        $this->post(route('books.store'), $payload)
            ->assertRedirect(route('books.create'))
            ->assertSessionHasErrors('isbn');

        $response = $this->get(route('books.create'))->assertOk();
        $this->入力値を確認する($response, $payload);
        $response->assertSeeText('ISBNは半角数字13桁で入力してください。');
        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
    }

    public function test_書籍更新エラー後は既存値より今回の入力を優先して再表示する(): void
    {
        $owner = User::factory()->create();
        $this->actingAs($owner);
        $oldGenre = Genre::factory()->create();
        $newGenre = Genre::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $book->genres()->sync([$oldGenre->id]);
        $before = $book->fresh()->getRawOriginal();
        $payload = $this->不正ISBNの入力([$newGenre->id]);

        $this->put(route('books.update', $book), $payload)
            ->assertRedirect(route('books.edit', $book))
            ->assertSessionHasErrors('isbn');

        $response = $this->get(route('books.edit', $book))->assertOk();
        $this->入力値を確認する($response, $payload);
        $response->assertSeeText('ISBNは半角数字13桁で入力してください。');
        $this->assertSame($before, $book->fresh()->getRawOriginal());
        $this->assertSame([$oldGenre->id], $book->genres()->pluck('genres.id')->all());
    }

    private function 不正ISBNの入力(array $genreIds): array
    {
        return [
            'title' => '再表示確認用タイトル',
            'author' => '再表示確認用著者',
            'isbn' => 'invalid-isbn',
            'published_date' => '2026-11-01',
            'description' => '再表示確認用の説明',
            'image_url' => 'https://example.com/retained-cover.jpg',
            'genres' => $genreIds,
        ];
    }

    private function 入力値を確認する(TestResponse $response, array $expected): void
    {
        $document = new DOMDocument;
        @$document->loadHTML('<?xml encoding="UTF-8">'.$response->getContent());
        $xpath = new DOMXPath($document);

        foreach (['title', 'author', 'isbn', 'published_date', 'image_url'] as $name) {
            $nodes = $xpath->query('//input[@name="'.$name.'"]');
            $this->assertSame(1, $nodes->length, $name.'の入力欄');
            $this->assertSame($expected[$name] ?? '', $nodes->item(0)->getAttribute('value'), $name.'の表示値');
        }

        $nodes = $xpath->query('//textarea[@name="description"]');
        $this->assertSame(1, $nodes->length);
        $this->assertSame($expected['description'] ?? '', $nodes->item(0)->textContent);
        $selected = [];
        foreach ($xpath->query('//input[@name="genres[]" and @checked]') as $node) {
            $selected[] = $node->getAttribute('value');
        }
        $this->assertEqualsCanonicalizing(
            array_map('strval', $expected['genres']),
            $selected,
            '今回選択したジャンルだけを再表示する'
        );
    }
}
