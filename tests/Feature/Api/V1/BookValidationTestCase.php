<?php

namespace Tests\Feature\Api\V1;

use App\Http\Requests\Api\V1\IndexBookRequest;
use App\Http\Requests\Api\V1\StoreBookRequest;
use App\Http\Requests\Api\V1\UpdateBookRequest;
use App\Models\Book;
use App\Models\Genre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

abstract class BookValidationTestCase extends TestCase
{
    use RefreshDatabase;

    protected const URI = '/api/testing/books';

    protected function setUp(): void
    {
        parent::setUp();

        // 入力検証専用ルート。正式APIの認証・保存処理は別テストで検証する。
        Route::middleware('api')->group(function (): void {
            Route::get(self::URI, function (IndexBookRequest $request): JsonResponse {
                return response()->json($request->validated());
            });

            Route::post(self::URI, function (StoreBookRequest $request): JsonResponse {
                return response()->json($request->validated());
            });

            Route::put(self::URI.'/{book}', function (UpdateBookRequest $request, Book $book): JsonResponse {
                return response()->json($request->validated());
            });
        });
    }

    /**
     * 正常な書籍登録用の入力データ。
     *
     * @return array<string, mixed>
     */
    protected function validPayload(): array
    {
        return [
            'title' => 'API入力検証用の書籍',
            'author' => 'テスト著者',
            'isbn' => '9781234567890',
            'published_date' => '2026-11-01',
            'description' => null,
            'image_url' => null,
            'genres' => [Genre::factory()->create()->id],
        ];
    }

    /**
     * 書籍とジャンル紐付けのDB状態。
     *
     * @return array<string, array<int, array<string, mixed>>>
     */
    protected function bookSnapshot(): array
    {
        $snapshot = [];

        foreach (['books', 'book_genres'] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}
