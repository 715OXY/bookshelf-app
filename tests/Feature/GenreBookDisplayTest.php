<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GenreBookDisplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_画像なしと複数ジャンルの書籍カードを表示する(): void
    {
        $this->actingAs(User::factory()->create());

        $genre = Genre::factory()->create(['name' => '対象ジャンル']);
        $secondGenre = Genre::factory()->create(['name' => '追加ジャンル']);
        $book = Book::factory()->create([
            'title' => '表示確認用の書籍',
            'author' => '表示確認用の著者',
            'image_url' => null,
        ]);
        $book->genres()->sync([$genre->id, $secondGenre->id]);

        $this->get(route('genres.show', $genre))
            ->assertOk()
            ->assertSeeText($book->title)
            ->assertSeeText($book->author)
            ->assertSeeText('画像なし')
            ->assertSeeText($genre->name)
            ->assertSeeText($secondGenre->name)
            ->assertSee('href="'.route('books.show', $book).'"', false)
            ->assertViewHas('books', function ($books) use ($book): bool {
                $displayed = $books->first();

                return $displayed !== null
                    && $displayed->is($book)
                    && $displayed->relationLoaded('genres')
                    && $displayed->genres->count() === 2;
            });
    }

    public function test_書籍が1件から10件に増えてもジャンル取得回数が増えない(): void
    {
        $this->actingAs(User::factory()->create());
        $genres = Genre::factory()->count(2)->create();
        $targetGenre = $genres->first();
        $queryCounts = [];

        foreach ([1, 9] as $additionalCount) {
            $books = Book::factory()->count($additionalCount)->create();

            foreach ($books as $book) {
                $book->genres()->sync($genres->modelKeys());
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                $this->get(route('genres.show', $targetGenre))
                    ->assertOk()
                    ->assertViewHas('books', function ($books) use ($additionalCount): bool {
                        $expectedCount = $additionalCount === 1 ? 1 : 10;

                        return $books->count() === $expectedCount
                            && $books->every(fn (Book $book): bool => $book->relationLoaded('genres')
                                && $book->genres->count() === 2
                            );
                    });

                $genreQueries = array_filter(
                    DB::getQueryLog(),
                    function (array $query): bool {
                        $sql = strtolower(str_replace(['`', '"'], '', $query['query']));

                        return preg_match('/\bfrom\s+genres\b/', $sql) === 1;
                    }
                );

                $queryCounts[] = count($genreQueries);
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }
        }

        // ルートの対象ジャンル取得1回と、書籍のジャンル一括取得1回。
        $this->assertSame([2, 2], $queryCounts);
    }
}
