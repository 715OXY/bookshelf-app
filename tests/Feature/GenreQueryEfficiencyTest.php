<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GenreQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_ジャンル数が増えても一覧の取得クエリ数が増えない(): void
    {
        $viewer = User::factory()->create();
        $genres = new Collection;
        $queryCounts = [];

        foreach ([1, 9] as $additionalCount) {
            for ($i = 0; $i < $additionalCount; $i++) {
                $genre = Genre::factory()->create([
                    'name' => '一覧検証ジャンル'.($genres->count() + 1).'。',
                ]);

                $books = Book::factory()->count(2)->create();

                foreach ($books as $book) {
                    $book->genres()->sync([$genre->id]);
                }

                // Factoryによって生成された未使用ジャンルを除外する。
                Genre::query()->whereDoesntHave('books')->delete();

                $genres->push($genre);
            }

            $this->actingAs($viewer->fresh(), 'web');

            [$response, $queryCount] = $this->画面取得と計測(
                route('genres.index')
            );

            $expectedCount = $additionalCount === 1 ? 1 : 10;

            $response->assertOk()
                ->assertViewHas(
                    'genres',
                    function ($displayed) use ($genres, $expectedCount): bool {
                        return $displayed->count()
                            === $expectedCount
                            && $displayed->whereIn('id', $genres->modelKeys())
                                ->every(fn (Genre $genre): bool => (int) $genre->books_count === 2);
                    }
                );

            foreach ($genres as $genre) {
                $response->assertSeeText($genre->name);
            }

            $queryCounts[] = $queryCount;
        }

        $this->クエリ数を比較する('Genre index', $queryCounts);
    }

    public function test_書籍数が増えてもジャンル詳細の取得クエリ数が増えない(): void
    {
        $viewer = User::factory()->create();

        $genres = Genre::factory()->count(2)->create();
        $targetGenre = $genres->first();

        $otherBook = Book::factory()->create([
            'title' => '対象外ジャンルの確認書籍',
        ]);

        $books = collect();
        $queryCounts = [];

        foreach ([1, 9] as $additionalCount) {
            for ($i = 0; $i < $additionalCount; $i++) {
                $book = Book::factory()->create([
                    'title' => 'ジャンル詳細確認書籍'.($books->count() + 1).'。',
                ]);

                $book->genres()->sync($genres->modelKeys());
                $books->push($book);
            }

            $this->actingAs($viewer->fresh(), 'web');

            [$response, $queryCount] = $this->画面取得と計測(
                route('genres.show', $targetGenre)
            );

            $expectedCount = $additionalCount === 1 ? 1 : 10;

            $response->assertOk()
                ->assertDontSeeText($otherBook->title)
                ->assertViewHas(
                    'books',
                    fn ($displayed): bool => $displayed->count() === $expectedCount
                        && $displayed->total() === $expectedCount
                        && $displayed->every(
                            fn (Book $book): bool => $book->relationLoaded('genres')
                                && $book->genres->count() === 2
                        )
                );

            foreach ($books as $book) {
                $response->assertSeeText($book->title)
                    ->assertSee('href="'.route('books.show', $book).'"', false);
            }

            foreach ($genres as $genre) {
                $response->assertSeeText($genre->name);
            }

            $queryCounts[] = $queryCount;
        }

        $this->クエリ数を比較する('Genre detail', $queryCounts);
    }

    /**
     * @return array{TestResponse, int}
     */
    private function 画面取得と計測(string $url): array
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $response = $this->get($url);
            $queries = DB::getQueryLog();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        $selectQueries = array_filter(
            $queries,
            fn (array $query): bool => preg_match('/^\s*select\b/i', $query['query']) === 1
        );

        return [$response, count($selectQueries)];
    }

    private function クエリ数を比較する(string $label, array $counts): void
    {
        fwrite(STDOUT, sprintf(
            "\n%s SELECT: 1 item=%d, 10 items=%d\n",
            $label,
            $counts[0],
            $counts[1]
        ));

        $this->assertGreaterThan(0, $counts[0]);
        $this->assertSame($counts[0], $counts[1]);
    }
}
