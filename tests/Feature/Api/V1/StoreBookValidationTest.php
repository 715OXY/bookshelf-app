<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use PHPUnit\Framework\Attributes\DataProvider;

class StoreBookValidationTest extends BookValidationTestCase
{
    public function test_未認証で正常な書籍登録入力を受け付ける(): void
    {
        $payload = $this->validPayload();

        $this->postJson(self::URI, $payload)
            ->assertOk()
            ->assertJson($payload);

        $this->assertGuest();
        $this->assertDatabaseCount('books', 0);
    }

    public function test_登録の必須項目を検証する(): void
    {
        $this->postJson(self::URI, [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors([
                'user_id',
                'title',
                'author',
                'isbn',
                'published_date',
                'genres',
            ]);

        $this->assertDatabaseCount('books', 0);
    }

    #[DataProvider('invalidStoreInputs')]
    public function test_登録の不正な入力を拒否する(
        string $field,
        mixed $value,
        string $errorKey
    ): void {
        $payload = $this->validPayload();
        $payload[$field] = $value;

        $this->postJson(self::URI, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors($errorKey);

        $this->assertDatabaseCount('books', 0);
        $this->assertDatabaseCount('book_genres', 0);
    }

    public static function invalidStoreInputs(): array
    {
        return [
            '登録者IDの型不正' => ['user_id', 'invalid', 'user_id'],
            '登録者不存在' => ['user_id', 2147483647, 'user_id'],
            'タイトルの型不正' => ['title', [], 'title'],
            'タイトル上限超過' => ['title', str_repeat('あ', 256), 'title'],
            '著者上限超過' => ['author', str_repeat('あ', 256), 'author'],
            'ISBN12桁' => ['isbn', '978123456789', 'isbn'],
            'ISBN14桁' => ['isbn', '97812345678901', 'isbn'],
            'ISBN英字混入' => ['isbn', '978123456789X', 'isbn'],
            'ISBN数値型' => ['isbn', 9781234567890, 'isbn'],
            '出版日不正' => ['published_date', '2026-02-30', 'published_date'],
            '説明上限超過' => ['description', str_repeat('あ', 10001), 'description'],
            '画像URL不正' => ['image_url', 'invalid', 'image_url'],
            'ジャンル空配列' => ['genres', [], 'genres'],
            'ジャンル型不正' => ['genres', 'invalid', 'genres'],
            'ジャンルID型不正' => ['genres', ['invalid'], 'genres.0'],
            'ジャンル不存在' => ['genres', [2147483647], 'genres.0'],
        ];
    }

    public function test_登録済みの_isb_nを拒否する(): void
    {
        $payload = $this->validPayload();

        Book::factory()->create([
            'isbn' => $payload['isbn'],
        ]);

        $this->postJson(self::URI, $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.isbn.0',
                'このISBNは既に登録されています。'
            );

        $this->assertDatabaseCount('books', 1);
    }

    public function test_ジャンルの重複指定を拒否する(): void
    {
        $payload = $this->validPayload();
        $payload['genres'][] = $payload['genres'][0];

        $this->postJson(self::URI, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['genres.0', 'genres.1']);
    }
}
