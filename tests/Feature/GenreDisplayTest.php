<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenreDisplayTest extends TestCase
{
    use RefreshDatabase;

    /**
     * ジャンル一覧に正しい書籍数を表示する。
     */
    public function test_ジャンル一覧に書籍数が表示される(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $books = Book::factory()->count(2)->create();
        $genre->books()->attach($books->modelKeys());

        $this->actingAs($user)
            ->get(route('genres.index'))
            ->assertOk()
            ->assertViewHas('genres', function ($genres) use ($genre): bool {
                $target = $genres->firstWhere('id', $genre->id);

                return $target !== null
                    && $target->books_count === 2;
            });
    }

    /**
     * ジャンル未登録でも一覧を表示する。
     */
    public function test_ジャンルが0件でも一覧を表示できる(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('genres.index'))
            ->assertOk()
            ->assertSee('ジャンルが登録されていません。');
    }

    /**
     * ジャンル詳細に対象書籍だけを取得する。
     */
    public function test_ジャンル詳細に対象書籍だけが含まれる(): void
    {
        $user = User::factory()->create();

        $targetGenre = Genre::factory()->create();
        $otherGenre = Genre::factory()->create();

        $targetBook = Book::factory()->create();
        $otherBook = Book::factory()->create();

        $targetGenre->books()->attach($targetBook->id);
        $otherGenre->books()->attach($otherBook->id);

        $this->actingAs($user)
            ->get(route('genres.show', $targetGenre))
            ->assertOk()
            ->assertViewHas('books', function ($books) use ($targetBook, $otherBook): bool {
                return $books->contains('id', $targetBook->id)
                    && ! $books->contains('id', $otherBook->id);
            });
    }

    /**
     * ジャンル登録・編集フォームを表示する。
     */
    public function test_ジャンル登録編集フォームを表示できる(): void
    {
        $user = User::factory()->create();
        $genre = Genre::factory()->create();

        $this->actingAs($user)
            ->get(route('genres.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('genres.edit', $genre))
            ->assertOk()
            ->assertViewHas('genre', function ($viewGenre) use ($genre): bool {
                return $viewGenre->id === $genre->id;
            });
    }

    /**
     * ジャンル数が増えても書籍数取得のクエリが増えない。
     */
    public function test_ジャンル一覧の書籍数取得で_nプラス1が発生しない(): void
    {
        $user = User::factory()->create();

        Genre::factory()->count(5)->create();

        $this->actingAs($user);

        $queries = [];

        DB::listen(
            function ($query) use (&$queries): void {
                $queries[] = $query->sql;
            }
        );

        $this->get(route('genres.index'))
            ->assertOk();

        $countQueries = array_filter(
            $queries,
            fn (string $sql): bool => str_contains(
                strtolower($sql),
                'from `genres`'
            )
        );

        $this->assertCount(1, $countQueries);
    }
}
