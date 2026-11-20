<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class InteractionQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('一覧画面の検証条件')]
    public function test_書籍数が増えても一覧画面のクエリ数が増えない(
        string $routeName,
        bool $authenticated
    ): void {
        $viewer = User::factory()->create();
        $otherUser = User::factory()->create();

        $otherBook = Book::factory()->create([
            'title' => '表示対象外の確認書籍',
        ]);

        $otherUser->favoriteBooks()->attach($otherBook->id);

        $books = collect();
        $queryCounts = [];

        foreach ([1, 9] as $additionalCount) {
            for ($i = 0; $i < $additionalCount; $i++) {
                $book = Book::factory()->create([
                    'title' => '一覧確認書籍'.($books->count() + 1).'。',
                ]);

                $viewer->favoriteBooks()->attach($book->id);

                foreach ([4, 5] as $rating) {
                    Review::factory()->create([
                        'book_id' => $book->id,
                        'user_id' => $otherUser->id,
                        'rating' => $rating,
                    ]);
                }

                $books->push($book);
            }

            if ($authenticated) {
                $this->actingAs($viewer->fresh(), 'web');
            }

            [$response, $queryCount] = $this->画面取得と計測(
                route($routeName)
            );

            $expectedCount = $additionalCount === 1 ? 1 : 10;

            $response->assertOk()
                ->assertDontSeeText($otherBook->title);

            foreach ($books as $book) {
                $response->assertSeeText($book->title)
                    ->assertSee(
                        'href="'.route('books.show', $book).'"',
                        false
                    );
            }

            if ($routeName === 'favorites.index') {
                $response->assertViewHas(
                    'books',
                    fn ($displayed): bool => $displayed->count() === $expectedCount
                        && $displayed->total() === $expectedCount
                );

                foreach ($books as $book) {
                    $response->assertSee(
                        'action="'.route('favorites.toggle', $book).'"',
                        false
                    );
                }
            } else {
                $response->assertViewHas(
                    'rankedBooks',
                    fn ($displayed): bool => $displayed->count() === $expectedCount
                        && $displayed->every(
                            fn (Book $book): bool => (float) $book->reviews_avg_rating === 4.5
                                && (int) $book->reviews_count === 2
                        )
                );

                $response->assertSeeText('4.50')
                    ->assertSeeText('(2件のレビュー)');
            }

            if ($authenticated) {
                $this->assertAuthenticatedAs($viewer, 'web');
            } else {
                $this->assertGuest('web');
            }

            $queryCounts[] = $queryCount;
        }

        fwrite(STDOUT, sprintf(
            "\n%s SELECT [%s]: 1 book=%d, 10 books=%d\n",
            $routeName,
            $authenticated ? 'authenticated' : 'guest',
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

    public static function 一覧画面の検証条件(): array
    {
        return [
            'お気に入り一覧・会員' => ['favorites.index', true],
            'ランキング・ゲスト' => ['ranking.index', false],
            'ランキング・会員' => ['ranking.index', true],
        ];
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
}
