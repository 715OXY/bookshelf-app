<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use PHPUnit\Framework\Attributes\DataProvider;

class BookIndexSearchTest extends BookReadTestCase
{
    #[DataProvider('keywordSearchCases')]
    public function test_キーワードで書籍を部分一致検索できる(
        string $keyword
    ): void {
        $book = Book::factory()->create([
            'title' => 'Laravel実践入門',
            'author' => '山田太郎',
            'isbn' => '9781234567890',
        ]);

        Book::factory()->create([
            'title' => 'PHP基礎講座',
            'author' => '鈴木花子',
            'isbn' => '9789876543210',
        ]);

        $this->getJson(self::URI.'?'.http_build_query([
            'keyword' => $keyword,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function keywordSearchCases(): array
    {
        return [
            'タイトル' => ['実践'],
            '著者' => ['山田'],
            'ISBN' => ['123456'],
        ];
    }

    public function test_ジャンルで書籍を絞り込める(): void
    {
        $genre = Genre::factory()->create();
        $book = Book::factory()->create();

        $book->genres()->sync([$genre->id]);

        Book::factory()->create();

        $this->getJson(self::URI.'?'.http_build_query([
            'genre_id' => $genre->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_キーワードとジャンルを組み合わせて検索できる(): void
    {
        $genre = Genre::factory()->create();
        $otherGenre = Genre::factory()->create();

        $matched = Book::factory()->create([
            'title' => 'Laravel入門',
            'author' => '山田太郎',
        ]);

        $keywordOnly = Book::factory()->create([
            'title' => 'PHP入門',
            'author' => 'Laravel研究会',
        ]);

        $genreOnly = Book::factory()->create([
            'title' => 'データベース入門',
            'author' => '鈴木花子',
        ]);

        $matched->genres()->sync([$genre->id]);
        $keywordOnly->genres()->sync([$otherGenre->id]);
        $genreOnly->genres()->sync([$genre->id]);

        $this->getJson(self::URI.'?'.http_build_query([
            'keyword' => 'Laravel',
            'genre_id' => $genre->id,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $matched->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_検索条件に一致しない場合は空の一覧を返す(): void
    {
        Book::factory()->create([
            'title' => 'Laravel入門',
            'author' => '山田太郎',
        ]);

        $this->getJson(self::URI.'?'.http_build_query([
            'keyword' => '該当しない検索文字列',
        ]))
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 0);
    }

    #[DataProvider('literalSearchCases')]
    public function test_like特殊文字を通常の文字として検索する(
        string $keyword,
        string $matchedTitle,
        string $otherTitle
    ): void {
        $book = Book::factory()->create([
            'title' => $matchedTitle,
            'author' => '山田太郎',
        ]);

        Book::factory()->create([
            'title' => $otherTitle,
            'author' => '鈴木花子',
        ]);

        $this->getJson(self::URI.'?'.http_build_query([
            'keyword' => $keyword,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $book->id)
            ->assertJsonPath('meta.total', 1);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function literalSearchCases(): array
    {
        return [
            'パーセント' => ['%', '100%理解するPHP', '100点を目指すPHP'],
            'アンダースコア' => ['_', 'PHP_basic入門', 'PHP-basic入門'],
            '感嘆符' => ['!', 'PHP入門!', 'PHP入門'],
        ];
    }
}
