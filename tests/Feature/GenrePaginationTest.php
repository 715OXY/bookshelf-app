<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenrePaginationTest extends TestCase
{
    use RefreshDatabase;

    public function test_対象書籍だけを最新順で10件ずつ表示する(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create();
        $books = Book::factory()->count(11)->create([
            'created_at' => '2026-11-01 12:00:00',
        ]);
        $genre->books()->attach($books->modelKeys());

        // IDより登録日時の降順が優先されることも確認する。
        $books->first()->forceFill([
            'created_at' => '2026-11-02 12:00:00',
        ])->save();

        $otherGenre = Genre::factory()->create();
        $otherBook = Book::factory()->create();
        $otherGenre->books()->attach($otherBook->id);

        $expected = array_merge(
            [$books->first()->id],
            $books->skip(1)->pluck('id')->sortDesc()->values()->all()
        );

        foreach ([1, 2] as $page) {
            $this->get(route('genres.show', [
                'genre' => $genre,
                'page' => $page,
            ]))
                ->assertOk()
                ->assertViewHas('books', function ($books) use ($expected, $page): bool {
                    return $books->total() === 11
                        && $books->perPage() === 10
                        && $books->currentPage() === $page
                        && $books->pluck('id')->all()
                            === array_slice($expected, ($page - 1) * 10, 10);
                });
        }
    }

    public function test_不正ページと範囲超過を対象ジャンル内で補正する(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create();
        $books = Book::factory()->count(11)->create();
        $genre->books()->attach($books->modelKeys());

        foreach (['0', '-1', 'abc', '1.5', ['1']] as $page) {
            $this->get(route('genres.show', [
                'genre' => $genre,
                'page' => $page,
            ]))
                ->assertRedirect(route('genres.show', $genre));
        }

        foreach ([3, 999, '999999999999'] as $page) {
            $this->get(route('genres.show', [
                'genre' => $genre,
                'page' => $page,
            ]))
                ->assertRedirect(route('genres.show', [
                    'genre' => $genre,
                    'page' => 2,
                ]));
        }
    }

    public function test_書籍0件でも表示でき範囲超過は先頭へ戻す(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();

        $this->get(route('genres.show', $genre))
            ->assertOk()
            ->assertSee('このジャンルの書籍はまだ登録されていません。')
            ->assertViewHas('books', fn ($books): bool => $books->total() === 0);

        $this->get(route('genres.show', [
            'genre' => $genre,
            'page' => 2,
        ]))
            ->assertRedirect(route('genres.show', $genre));
    }
}
