<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Review;
use Illuminate\Support\Facades\DB;

class BookQueryEfficiencyTest extends BookReadTestCase
{
    public function test_書籍数が増えても一覧取得クエリ数が増えない(): void
    {
        $book = Book::factory()->create();

        Review::factory()->create([
            'book_id' => $book->id,
        ]);

        $one = $this->取得クエリ(self::URI, 'data', 1);

        $books = Book::factory()->count(9)->create();

        foreach ($books as $additionalBook) {
            Review::factory()->create([
                'book_id' => $additionalBook->id,
            ]);
        }

        $many = $this->取得クエリ(self::URI, 'data', 10);

        $this->assertNotEmpty($one);
        $this->assertNotEmpty($many);

        $this->assertLessThanOrEqual(
            count($one),
            count($many)
        );

        printf(
            "\n一覧取得SQL: 1件=%d回 / 10件=%d回\n",
            count($one),
            count($many)
        );
    }

    public function test_レビュー数が増えても詳細取得クエリ数が増えない(): void
    {
        $book = Book::factory()->create();

        Review::factory()->create([
            'book_id' => $book->id,
        ]);

        $uri = self::URI.'/'.$book->id;

        $one = $this->取得クエリ($uri, 'data.reviews', 1);

        Review::factory()->count(9)->create([
            'book_id' => $book->id,
        ]);

        $many = $this->取得クエリ($uri, 'data.reviews', 10);

        $this->assertNotEmpty($one);
        $this->assertNotEmpty($many);

        $this->assertLessThanOrEqual(
            count($one),
            count($many)
        );

        printf(
            "\n詳細取得SQL: レビュー1件=%d回 / 10件=%d回\n",
            count($one),
            count($many)
        );
    }

    public function test_最大ページ番号でも巨大なオフセットを発行しない(): void
    {
        Book::factory()->count(3)->create();

        $queries = $this->取得クエリ(
            self::URI.'?page=2147483647&per_page=100',
            'data',
            0
        );

        $this->assertNotEmpty($queries);

        foreach ($queries as $sql) {
            $this->assertDoesNotMatchRegularExpression(
                '/\boffset\b/i',
                $sql
            );

            $this->assertDoesNotMatchRegularExpression(
                '/\blimit\b/i',
                $sql
            );
        }

        $this->assertCount(1, $queries);

        $this->assertMatchesRegularExpression(
            '/\bcount\s*\(/i',
            $queries[0]
        );

        printf(
            "\n最大ページ番号指定時のSQL: %s\n",
            implode("\n", $queries)
        );
    }

    /**
     * API応答の取得時に発行されたSELECT文を計測する。
     *
     * @return array<int, string>
     */
    private function 取得クエリ(
        string $uri,
        string $jsonPath,
        int $expectedCount
    ): array {
        DB::enableQueryLog();
        DB::flushQueryLog();

        try {
            $this->getJson($uri)
                ->assertOk()
                ->assertJsonCount($expectedCount, $jsonPath);

            $queries = collect(DB::getQueryLog())
                ->pluck('query')
                ->filter(fn (string $sql): bool => preg_match(
                    '/^\s*select\b/i',
                    $sql
                ) === 1)
                ->values()
                ->all();
        } finally {
            DB::disableQueryLog();
            DB::flushQueryLog();
        }

        return $queries;
    }
}
