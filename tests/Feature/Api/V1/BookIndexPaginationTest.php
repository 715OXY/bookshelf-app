<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Genre;
use PHPUnit\Framework\Attributes\DataProvider;

class BookIndexPaginationTest extends BookReadTestCase
{
    public function test_既定値20件でページ分割される(): void
    {
        Book::factory()->count(21)->create();

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 21)
            ->assertJsonPath('meta.per_page', 20)
            ->assertJsonPath('meta.current_page', 1)
            ->assertJsonPath('meta.last_page', 2);
    }

    public function test_指定したページ番号と件数で取得できる(): void
    {
        $books = Book::factory()->count(7)->create([
            'created_at' => '2026-11-18 12:00:00',
        ]);

        $expected = $books->modelKeys();
        rsort($expected);

        $response = $this->getJson(self::URI.'?page=2&per_page=3')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.total', 7)
            ->assertJsonPath('meta.current_page', 2)
            ->assertJsonPath('meta.per_page', 3)
            ->assertJsonPath('meta.last_page', 3);

        $this->assertSame(
            array_slice($expected, 3, 3),
            array_column($response->json('data'), 'id')
        );
    }

    public function test_作成日時の降順で取得できる(): void
    {
        $newer = Book::factory()->create([
            'created_at' => '2026-11-18 12:00:00',
        ]);

        $older = Book::factory()->create([
            'created_at' => '2026-11-17 12:00:00',
        ]);

        $this->getJson(self::URI)
            ->assertOk()
            ->assertJsonPath('data.0.id', $newer->id)
            ->assertJsonPath('data.1.id', $older->id);
    }

    public function test_同一作成日時の場合は_i_dの降順になる(): void
    {
        $books = Book::factory()->count(3)->create([
            'created_at' => '2026-11-18 12:00:00',
        ]);

        $expected = $books->modelKeys();
        rsort($expected);

        $response = $this->getJson(self::URI)->assertOk();

        $this->assertSame(
            $expected,
            array_column($response->json('data'), 'id')
        );
    }

    public function test_ページリンクに検索条件が引き継がれる(): void
    {
        $genre = Genre::factory()->create();

        $books = Book::factory()->count(3)->create([
            'title' => 'Laravel入門',
        ]);

        foreach ($books as $book) {
            $book->genres()->sync([$genre->id]);
        }

        $response = $this->getJson(self::URI.'?'.http_build_query([
            'keyword' => 'Laravel',
            'genre_id' => $genre->id,
            'per_page' => 1,
            'page' => 2,
        ]))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.total', 3);

        foreach (['first', 'last', 'prev', 'next'] as $link) {
            $url = $response->json('links.'.$link);

            $this->assertIsString($url);

            parse_str(parse_url($url, PHP_URL_QUERY) ?? '', $params);

            $this->assertSame('Laravel', $params['keyword']);
            $this->assertSame((string) $genre->id, $params['genre_id']);
            $this->assertSame('1', $params['per_page']);

            $expectedPage = in_array($link, ['first', 'prev'], true)
                ? '1'
                : '3';

            $this->assertSame($expectedPage, $params['page']);
        }
    }

    #[DataProvider('outOfRangePages')]
    public function test_最終ページを超えた場合は空の一覧を返す(
        int $page
    ): void {
        Book::factory()->count(3)->create();

        $this->getJson(self::URI.'?page='.$page)
            ->assertOk()
            ->assertJsonPath('data', [])
            ->assertJsonPath('meta.total', 3)
            ->assertJsonPath('meta.current_page', $page)
            ->assertJsonPath('meta.last_page', 1);
    }

    /**
     * @return array<string, array<int, int>>
     */
    public static function outOfRangePages(): array
    {
        return [
            '最終ページ超過' => [2],
            '最大ページ番号' => [2147483647],
        ];
    }

    public function test_最大ページ件数を受け付ける(): void
    {
        Book::factory()->count(3)->create();

        $this->getJson(self::URI.'?per_page=100')
            ->assertOk()
            ->assertJsonCount(3, 'data')
            ->assertJsonPath('meta.per_page', 100);
    }

    #[DataProvider('invalidPaginationCases')]
    public function test_ページネーションの上限超過を拒否する(
        string $field,
        string $value
    ): void {
        $this->getJson(self::URI.'?'.http_build_query([
            $field => $value,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors($field);
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function invalidPaginationCases(): array
    {
        return [
            'ページ番号上限超過' => ['page', '2147483648'],
            'ページ件数上限超過' => ['per_page', '101'],
        ];
    }
}
