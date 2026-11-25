<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BookIndexQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('閲覧者の認証状態')]
    public function test_書籍数が増えても一覧の取得クエリ数が増えない(
        bool $authenticated,
        bool $filtered
    ): void {
        $user = User::factory()->create();

        $genres = Genre::factory()->count(2)->create();

        $queryCounts = [];

        foreach ([1, 9] as $additionalCount) {
            for ($i = 0; $i < $additionalCount; $i++) {
                $book = Book::factory()->for($user)->create(['title' => 'PHP検索の対象']);

                $book->genres()->sync($genres->modelKeys());

                Review::factory()->create([
                    'book_id' => $book->id,
                    'user_id' => $user->id,
                    'rating' => 5,
                ]);
            }

            if ($authenticated) {
                $this->actingAs($user->fresh(), 'web');
            }

            $expectedCount = $additionalCount === 1 ? 1 : 10;

            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                $response = $this->get(route('books.index', $filtered
                    ? ['keyword' => 'PHP検索', 'genre' => $genres[0]->id]
                    : []));

                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }

            $response->assertOk()
                ->assertSeeText($book->title)
                ->assertViewHas(
                    'books',
                    function ($books) use ($expectedCount): bool {
                        return $books->count() === $expectedCount
                            && $books->every(
                                fn (Book $book): bool => $book->relationLoaded('genres')
                                    && $book->genres->count() === 2
                                    && (float) $book->reviews_avg_rating === 5.0
                            );
                    }
                );

            foreach ($genres as $genre) {
                $response->assertSeeText($genre->name);
            }

            if ($authenticated) {
                $this->assertAuthenticatedAs($user, 'web');
            } else {
                $this->assertGuest('web');
            }

            $selectQueries = array_filter(
                $queries,
                fn (array $query): bool => preg_match('/^\s*select\b/i', $query['query']) === 1
            );

            $queryCounts[] = count($selectQueries);
        }

        fwrite(STDOUT, sprintf(
            "\nBook index SELECT [%s, %s]: 1 book=%d, 10 books=%d\n",
            $authenticated ? 'authenticated' : 'guest',
            $filtered ? 'filtered' : 'unfiltered',
            $queryCounts[0],
            $queryCounts[1]
        ));

        $this->assertGreaterThan(0, $queryCounts[0]);

        $this->assertSame(
            $queryCounts[0],
            $queryCounts[1],
            '書籍数の増加によってSELECTクエリ数が増えている'
        );
    }

    public static function 閲覧者の認証状態(): array
    {
        return [
            'ゲスト・条件なし' => [false, false],
            '認証済み会員・条件なし' => [true, false],
            'ゲスト・検索とジャンル' => [false, true],
            '認証済み会員・検索とジャンル' => [true, true],
        ];
    }
}
