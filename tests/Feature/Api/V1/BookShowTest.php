<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use PHPUnit\Framework\Attributes\DataProvider;

class BookShowTest extends BookReadTestCase
{
    public function test_未認証で書籍詳細を取得できる(): void
    {
        $book = Book::factory()->create();

        $this->get(self::URI.'/'.$book->id)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('data.id', $book->id)
            ->assertJsonPath('data.title', $book->title)
            ->assertJsonPath('data.author', $book->author)
            ->assertJsonPath('data.isbn', $book->isbn);

        $this->assertGuest();
    }

    public function test_指定された公開項目のみを返す(): void
    {
        $book = Book::factory()->create([
            'published_date' => '2026-11-01',
            'description' => null,
            'image_url' => null,
        ]);

        $response = $this->getJson(self::URI.'/'.$book->id)
            ->assertOk()
            ->assertJsonPath('data.published_date', '2026-11-01')
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.image_url', null);

        $this->assertSame(['data'], array_keys($response->json()));

        $this->assertEqualsCanonicalizing([
            'id',
            'title',
            'author',
            'isbn',
            'published_date',
            'description',
            'image_url',
            'genres',
            'average_rating',
            'reviews_count',
            'reviews',
        ], array_keys($response->json('data')));
    }

    public function test_複数ジャンルの情報を取得できる(): void
    {
        $book = Book::factory()->create();
        $genres = Genre::factory()->count(2)->create();

        $book->genres()->sync($genres->modelKeys());

        $response = $this->getJson(self::URI.'/'.$book->id)
            ->assertOk()
            ->assertJsonCount(2, 'data.genres');

        $this->assertEqualsCanonicalizing(
            $genres->map(fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->all(),
            $response->json('data.genres')
        );
    }

    public function test_複数レビューと投稿者情報を取得できる(): void
    {
        $book = Book::factory()->create();

        $reviews = Review::factory()->count(3)->create([
            'book_id' => $book->id,
        ]);

        $expected = $reviews->map(fn (Review $review): array => [
            'id' => $review->id,
            'user_name' => $review->user->name,
            'rating' => $review->rating,
            'comment' => $review->comment,
            'created_at' => $review->created_at->toIso8601String(),
        ])->all();

        $response = $this->getJson(self::URI.'/'.$book->id)
            ->assertOk()
            ->assertJsonCount(3, 'data.reviews')
            ->assertJsonPath('data.reviews_count', 3);

        $this->assertEqualsCanonicalizing(
            $expected,
            $response->json('data.reviews')
        );
    }

    public function test_平均評価を小数第2位まで丸める(): void
    {
        $book = Book::factory()->create();

        foreach ([3, 4, 4] as $rating) {
            Review::factory()->create([
                'book_id' => $book->id,
                'rating' => $rating,
            ]);
        }

        $this->getJson(self::URI.'/'.$book->id)
            ->assertOk()
            ->assertJsonPath('data.average_rating', 3.67)
            ->assertJsonPath('data.reviews_count', 3);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', 3.67)
            ->assertJsonPath('data.0.reviews_count', 3);
    }

    public function test_レビューがない書籍の集計値と空配列を返す(): void
    {
        $book = Book::factory()->create();

        $this->getJson(self::URI.'/'.$book->id)
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'average_rating',
                    'reviews_count',
                    'reviews',
                ],
            ])
            ->assertJsonPath('data.average_rating', null)
            ->assertJsonPath('data.reviews_count', 0)
            ->assertJsonPath('data.reviews', []);
    }

    #[DataProvider('missingBookCases')]
    public function test_存在しない書籍には日本語のエラーを返す(
        string $bookId
    ): void {
        $this->get(self::URI.'/'.$bookId)
            ->assertNotFound()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '指定された書籍は存在しません。',
            ]);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function missingBookCases(): array
    {
        return [
            '不存在書籍' => ['2147483647'],
            '不正な文字列' => ['invalid'],
        ];
    }
}
