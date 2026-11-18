<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;

class BookIndexTest extends BookReadTestCase
{
    public function test_未認証で書籍一覧を取得できる(): void
    {
        Book::factory()->count(3)->create();

        $this->get(self::URI)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta',
            ]);

        $this->assertGuest();
    }

    public function test_指定された公開項目のみを返す(): void
    {
        $book = Book::factory()->create([
            'published_date' => '2026-11-01',
            'description' => null,
            'image_url' => null,
        ]);

        $response = $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('data.0.published_date', '2026-11-01')
            ->assertJsonPath('data.0.description', null)
            ->assertJsonPath('data.0.image_url', null);

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
        ], array_keys($response->json('data.0')));
    }

    public function test_複数ジャンルの情報を取得できる(): void
    {
        $book = Book::factory()->create();
        $genres = Genre::factory()->count(2)->create();

        $book->genres()->sync($genres->modelKeys());

        $response = $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonCount(2, 'data.0.genres');

        $this->assertEqualsCanonicalizing(
            $genres->map(fn (Genre $genre): array => [
                'id' => $genre->id,
                'name' => $genre->name,
            ])->all(),
            $response->json('data.0.genres')
        );
    }

    public function test_平均評価とレビュー件数が正しい(): void
    {
        $book = Book::factory()->create();

        foreach ([3, 4, 5, 5] as $rating) {
            Review::factory()->create([
                'book_id' => $book->id,
                'rating' => $rating,
            ]);
        }

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', 4.25)
            ->assertJsonPath('data.0.reviews_count', 4);
    }

    public function test_レビューがない書籍の集計値を返す(): void
    {
        Book::factory()->create();

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.0.average_rating', null)
            ->assertJsonPath('data.0.reviews_count', 0)
            ->assertJsonStructure([
                'data' => [
                    '*' => ['average_rating', 'reviews_count'],
                ],
            ]);
    }

    public function test_書籍がない場合は空の一覧を返す(): void
    {
        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 1);
    }

    public function test_acceptヘッダーなしでも入力エラーを返す(): void
    {
        $this->get(self::URI.'?page=0')
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '入力内容に誤りがあります。',
                'errors' => [
                    'page' => ['ページ番号は1以上で入力してください。'],
                ],
            ]);
    }
}
