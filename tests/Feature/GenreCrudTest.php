<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenreCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_ジャンルを登録できる(): void
    {
        $this->actingAs(User::factory()->create());

        $this->post(route('genres.store'), [
            'name' => '技術書',
        ])
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas('success', 'ジャンルを登録しました。');

        $this->assertDatabaseHas('genres', [
            'name' => '技術書',
        ]);
    }

    public function test_ジャンル名を更新できる(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create(['name' => '旧ジャンル']);

        $this->put(route('genres.update', $genre), [
            'name' => '新ジャンル',
        ])
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas('success', 'ジャンルを更新しました。');

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'name' => '新ジャンル',
        ]);
    }

    public function test_名前を変更せずに更新できる(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create(['name' => '技術書']);

        $this->put(route('genres.update', $genre), [
            'name' => '技術書',
        ])
            ->assertRedirect(route('genres.index'))
            ->assertSessionHasNoErrors();

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
            'name' => '技術書',
        ]);
    }

    public function test_書籍が紐付くジャンルは削除できない(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create();
        $book = Book::factory()->create();
        $book->genres()->attach($genre);

        $this->delete(route('genres.destroy', $genre))
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas(
                'error',
                '書籍が紐付いているため、このジャンルは削除できません。'
            );

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
        ]);

        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $genre->id,
        ]);
    }

    public function test_書籍が紐付かないジャンルは削除できる(): void
    {
        $this->actingAs(User::factory()->create());
        $genre = Genre::factory()->create();

        $this->delete(route('genres.destroy', $genre))
            ->assertRedirect(route('genres.index'))
            ->assertSessionHas('success', 'ジャンルを削除しました。');

        $this->assertDatabaseMissing('genres', [
            'id' => $genre->id,
        ]);
    }

    public function test_削除直前に書籍が紐付いた場合は_d_b制約で削除を防ぐ(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create();
        $book = Book::factory()->create();

        // Controllerの事前チェック後、DELETE直前に紐付きを作る。
        Genre::deleting(function (Genre $deleting) use ($genre, $book): void {
            if ($deleting->is($genre)) {
                $book->genres()->attach($genre);
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->delete(route('genres.destroy', $genre));
            $this->fail('DB外部キー制約による削除失敗を期待しました。');
        } catch (QueryException $e) {
            // DB制約による失敗を通常の削除拒否と区別する。
        }

        $this->assertDatabaseHas('genres', [
            'id' => $genre->id,
        ]);

        $this->assertDatabaseHas('book_genres', [
            'book_id' => $book->id,
            'genre_id' => $genre->id,
        ]);
    }
}
