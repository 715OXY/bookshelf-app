<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class NavigationDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_公開画面をジャンル管理リンク付きで描画できる(): void
    {
        $book = Book::factory()->create();

        foreach ([route('books.index'), route('books.show', $book), route('ranking.index')] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('href="'.route('genres.index').'"', false);
        }
    }

    public function test_会員の既存画面とジャンル画面を共通ナビ付きで描画できる(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);
        $book = Book::factory()->create(['user_id' => $user->id]);
        $genre = Genre::factory()->create();
        $book->genres()->sync([$genre->id]);
        $user->favoriteBooks()->attach($book->id);

        foreach ([
            route('books.index'),
            route('books.show', $book),
            route('books.create'),
            route('books.edit', $book),
            route('favorites.index'),
            route('ranking.index'),
            route('genres.index'),
            route('genres.show', $genre),
            route('genres.create'),
            route('genres.edit', $genre),
        ] as $url) {
            $this->get($url)
                ->assertOk()
                ->assertSee('href="'.route('genres.index').'"', false);
        }
    }
}
