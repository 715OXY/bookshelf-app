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

class BookDetailQueryEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('閲覧者の認証状態')]
    public function test_レビュー数が増えても書籍詳細の取得クエリ数が増えない(
        bool $authenticated
    ): void {
        $viewer = User::factory()->create();
        $book = Book::factory()->create();
        $genres = Genre::factory()->count(2)->create();
        $book->genres()->sync($genres->modelKeys());

        $viewer->favoriteBooks()->attach($book->id);

        $reviews = collect();
        $queryCounts = [];

        foreach ([1, 9] as $additionalCount) {
            for ($i = 0; $i < $additionalCount; $i++) {
                $author = User::factory()->create([
                    'name' => '投稿者確認'.($reviews->count() + 1),
                ]);

                $review = Review::factory()->create([
                    'book_id' => $book->id,
                    'user_id' => $author->id,
                    'rating' => 5,
                    'comment' => 'レビュー確認本文'.($reviews->count() + 1).'。',
                ]);

                $review->likedByUsers()->attach([
                    $viewer->id,
                    $author->id,
                ]);

                $reviews->push($review);
            }

            if ($authenticated) {
                $this->actingAs($viewer->fresh(), 'web');
            }

            DB::enableQueryLog();
            DB::flushQueryLog();

            try {
                $response = $this->get(route('books.show', $book));

                $queries = DB::getQueryLog();
            } finally {
                DB::disableQueryLog();
                DB::flushQueryLog();
            }

            $expectedCount = $additionalCount === 1 ? 1 : 10;

            $response->assertOk()
                ->assertSeeText($book->title)
                ->assertViewHas(
                    'book',
                    function (Book $displayed) use ($expectedCount): bool {
                        return $displayed->relationLoaded('genres')
                            && $displayed->genres->count() === 2
                            && $displayed->relationLoaded('reviews')
                            && $displayed->reviews->count() === $expectedCount
                            && $displayed->reviews->every(
                                fn (Review $review): bool => $review->relationLoaded('user')
                                    && $review->relationLoaded('likedByUsers')
                                    && $review->likedByUsers->count() === 2
                            );
                    }
                );

            foreach ($genres as $genre) {
                $response->assertSeeText($genre->name);
            }

            foreach ($reviews as $review) {
                $response->assertSeeText($review->comment)
                    ->assertSeeText($review->user->name);
            }

            if ($authenticated) {
                $response
                    ->assertSee('title="お気に入りから削除"', false)
                    ->assertSeeText('いいね済み (2)');

                $this->assertAuthenticatedAs($viewer, 'web');
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
            "\nBook detail SELECT [%s]: 1 review=%d, 10 reviews=%d\n",
            $authenticated ? 'authenticated' : 'guest',
            $queryCounts[0],
            $queryCounts[1]
        ));

        $this->assertGreaterThan(0, $queryCounts[0]);

        $this->assertSame(
            $queryCounts[0],
            $queryCounts[1],
            'レビュー数の増加によってSELECTクエリ数が増えている'
        );
    }

    public static function 閲覧者の認証状態(): array
    {
        return [
            'ゲスト' => [false],
            '認証済み会員' => [true],
        ];
    }
}
