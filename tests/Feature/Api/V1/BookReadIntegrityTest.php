<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use App\Models\Review;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class BookReadIntegrityTest extends BookWriteTestCase
{
    #[DataProvider('readOperations')]
    public function test_参照系_ap_iは既存データを変更しない(
        string $path,
        int $status
    ): void {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $genre = Genre::factory()->create();

        $book = Book::factory()->create([
            'user_id' => $owner->id,
            'title' => 'Laravel実践入門',
            'isbn' => '9781234567890',
        ]);

        $book->genres()->sync([$genre->id]);
        $book->favoritedByUsers()->sync([$reader->id]);

        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $reader->id,
            'rating' => 4,
        ]);

        $review->likedByUsers()->sync([$owner->id]);

        Book::factory()->create([
            'title' => 'PHP基礎講座',
            'isbn' => '9781234567891',
        ]);

        $uri = self::URI.strtr($path, [
            '{book}' => (string) $book->id,
            '{genre}' => (string) $genre->id,
        ]);

        $before = $this->bookSnapshot();

        $response = $this->get($uri)
            ->assertStatus($status)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location');

        if ($status === 200) {
            $response->assertJsonStructure(['data']);
        } else {
            $response->assertJsonStructure(['message']);
        }

        $this->assertSame($before, $this->bookSnapshot());
        $this->assertGuest();
    }

    public static function readOperations(): array
    {
        return [
            '書籍一覧' => ['', 200],
            'キーワード検索' => ['?keyword=Laravel', 200],
            'ジャンル絞り込み' => ['?genre_id={genre}', 200],
            '複合検索とページ分割' => [
                '?keyword=Laravel&genre_id={genre}&page=1&per_page=1',
                200,
            ],
            '最終ページ超過' => ['?page=2147483647', 200],
            '書籍詳細' => ['/{book}', 200],
            '不存在書籍' => ['/0', 404],
            '不正なページ件数' => ['?per_page=0', 422],
            '不存在ジャンル' => ['?genre_id=2147483647', 422],
        ];
    }
}
