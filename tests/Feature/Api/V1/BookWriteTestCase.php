<?php

namespace Tests\Feature\Api\V1;

use App\Models\Genre;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

abstract class BookWriteTestCase extends TestCase
{
    use RefreshDatabase;

    protected const URI = '/api/v1/books';

    /**
     * テスト専用DBへの接続を初期化前に確認する。
     */
    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());

        $result = $connection->selectOne(
            'SELECT DATABASE() AS database_name'
        );

        $this->assertSame('testing', $result->database_name);
    }

    /**
     * 正常な書籍登録用の入力データを生成する。
     *
     * @return array<string, mixed>
     */
    protected function validPayload(): array
    {
        return [
            'user_id' => User::factory()->create()->id,
            'title' => 'API書込検証用の書籍',
            'author' => 'テスト著者',
            'isbn' => '9781234567890',
            'published_date' => '2026-11-01',
            'description' => 'テスト用の説明',
            'image_url' => 'https://example.com/cover.jpg',
            'genres' => Genre::factory()->count(2)->create()->modelKeys(),
        ];
    }

    /**
     * 書籍関連テーブルのDB状態を取得する。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function bookSnapshot(): array
    {
        $snapshot = [];

        foreach ([
            'users',
            'books',
            'genres',
            'reviews',
            'book_genres',
            'favorites',
            'review_likes',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
