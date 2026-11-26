<?php

namespace Tests\Feature;

use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class BookPublishedDateMigrationTest extends TestCase
{
    /**
     * DDLの暗黙COMMITを通常テストのトランザクションから分離する。
     * testing内の一時接頭辞付きテーブルだけを作成し、Migration履歴には触れない。
     */
    public function test_nul_l許可と復旧条件を隔離したテーブルで確認する(): void
    {
        $originalName = DB::getDefaultConnection();
        $originalSchema = Schema::getFacadeRoot();
        $originalConnections = config('database.connections');
        $source = DB::connection();
        $this->assertTestingConnection($source);
        $prefix = 'issue79_dates_'.bin2hex(random_bytes(6)).'_';
        config(['database.connections.issue79_migration' => array_replace($source->getConfig(), [
            'name' => 'issue79_migration', 'prefix' => $prefix, 'prefix_indexes' => true,
        ])]);
        $isolated = DB::connection('issue79_migration');
        $ready = false;

        try {
            $this->assertTestingConnection($isolated);
            $this->assertSame($prefix, $isolated->getTablePrefix());
            DB::setDefaultConnection('issue79_migration');
            Schema::swap($isolated->getSchemaBuilder());

            foreach (['users', 'genres', 'books', 'book_genres'] as $table) {
                $this->assertFalse($isolated->getSchemaBuilder()->hasTable($table));
            }

            $ready = true;
            Schema::create('users', function (Blueprint $table): void {
                $table->id();
            });
            Schema::create('genres', function (Blueprint $table): void {
                $table->id();
                $table->string('name')->unique();
            });
            (require database_path('migrations/2026_11_06_043038_create_books_table.php'))->up();
            (require database_path('migrations/2026_11_06_044526_create_book_genres_table.php'))->up();
            $ownerId = DB::table('users')->insertGetId(['id' => 1]);
            $genreId = DB::table('genres')->insertGetId(['name' => '保持するジャンル']);
            $attributes = [
                'user_id' => $ownerId, 'title' => '保持する書籍', 'author' => '保持する著者',
                'isbn' => '9781234567890', 'published_date' => '2024-02-29',
                'description' => '既存の説明', 'image_url' => 'https://example.com/old.jpg',
                'created_at' => '2026-11-01 00:00:00', 'updated_at' => '2026-11-01 00:00:00',
            ];
            $bookId = DB::table('books')->insertGetId($attributes);
            DB::table('book_genres')->insert(['book_id' => $bookId, 'genre_id' => $genreId]);
            $before = $this->snapshot($isolated);
            $columns = $this->columns($isolated, $prefix);
            $constraints = $this->constraints($isolated, $prefix);
            $this->assertSame('NO', $columns['published_date']['IS_NULLABLE']);
            $migration = require database_path('migrations/2026_11_26_000000_make_books_published_date_nullable.php');

            $migration->up();
            $nullableColumns = $columns;
            $nullableColumns['published_date']['IS_NULLABLE'] = 'YES';
            $this->assertSame($nullableColumns, $this->columns($isolated, $prefix));
            $this->assertSame($constraints, $this->constraints($isolated, $prefix));
            $this->assertSame($before, $this->snapshot($isolated));
            $nullId = DB::table('books')->insertGetId(array_replace($attributes, [
                'isbn' => '9781234567891', 'published_date' => null,
            ]));
            $withNull = $this->snapshot($isolated);

            try {
                $migration->down();
                $this->fail('NULLが残る場合は復旧を拒否する必要がある。');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('出版日がNULLの書籍が残っている', $e->getMessage());
            }

            $this->assertSame($withNull, $this->snapshot($isolated));
            $this->assertSame($nullableColumns, $this->columns($isolated, $prefix));
            $this->assertSame($constraints, $this->constraints($isolated, $prefix));

            // 復旧可能な状態を作るため、隔離テーブルの当該テスト行だけを除去する。
            DB::table('books')->where('id', $nullId)->delete();
            $migration->down();
            $this->assertSame($columns, $this->columns($isolated, $prefix));
            $this->assertSame($constraints, $this->constraints($isolated, $prefix));
            $this->assertSame($before, $this->snapshot($isolated));
        } finally {
            try {
                if ($ready) {
                    foreach (['book_genres', 'books', 'genres', 'users'] as $table) {
                        $isolated->getSchemaBuilder()->dropIfExists($table);
                    }
                }
            } finally {
                Schema::swap($originalSchema);
                DB::setDefaultConnection($originalName);
                DB::purge('issue79_migration');
                config(['database.connections' => $originalConnections]);
            }
        }

        $this->assertSame($originalName, DB::getDefaultConnection());
        $this->assertSame($originalSchema, Schema::getFacadeRoot());
        $this->assertSame($originalConnections, config('database.connections'));

        foreach (['users', 'genres', 'books', 'book_genres'] as $table) {
            $remaining = $source->selectOne(
                'SELECT COUNT(*) AS total FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?',
                [$prefix.$table]
            );
            $this->assertSame(0, (int) $remaining->total);
        }
    }

    private function assertTestingConnection(Connection $connection): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());
        $this->assertSame('testing', $connection->selectOne('SELECT DATABASE() AS db')->db);
    }

    private function snapshot(Connection $connection): array
    {
        $rows = [];

        foreach (['users', 'genres', 'books', 'book_genres'] as $table) {
            $rows[$table] = $connection->table($table)->orderBy('id')->get()
                ->map(fn (object $row): array => (array) $row)->all();
        }

        return $rows;
    }

    private function columns(Connection $connection, string $prefix): array
    {
        return collect($connection->select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$prefix.'books']
        ))->mapWithKeys(fn (object $row): array => [$row->COLUMN_NAME => (array) $row])->all();
    }

    private function constraints(Connection $connection, string $prefix): array
    {
        $tables = [$prefix.'books', $prefix.'book_genres'];

        return [
            'keys' => collect($connection->select(
                'SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?) ORDER BY TABLE_NAME, CONSTRAINT_NAME',
                $tables
            ))->map(fn (object $row): array => (array) $row)->all(),
            'references' => collect($connection->select(
                'SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN (?, ?) ORDER BY TABLE_NAME, CONSTRAINT_NAME',
                $tables
            ))->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
