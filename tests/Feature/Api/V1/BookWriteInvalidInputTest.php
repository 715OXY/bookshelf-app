<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use PHPUnit\Framework\Attributes\DataProvider;

class BookWriteInvalidInputTest extends BookWriteTestCase
{
    #[DataProvider('invalidWriteInputs')]
    public function test_不正な入力では書籍を登録しない(
        string $field,
        mixed $value,
        string $errorKey,
        string $message
    ): void {
        $payload = $this->validPayload();
        $payload[$field] = $value;

        $before = $this->bookSnapshot();

        $response = $this->post(self::URI, $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame(
            $message,
            $response->json('errors')[$errorKey][0] ?? null
        );

        $this->assertSame($before, $this->bookSnapshot());
        $this->assertGuest();
    }

    #[DataProvider('invalidWriteInputs')]
    public function test_不正な入力では書籍を更新しない(
        string $field,
        mixed $value,
        string $errorKey,
        string $message
    ): void {
        $book = Book::factory()->create();
        $payload = $this->validPayload();

        unset($payload['user_id']);

        $payload['isbn'] = $book->isbn;
        $payload[$field] = $value;

        $before = $this->bookSnapshot();

        $response = $this->put(self::URI.'/'.$book->id, $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonValidationErrors([$errorKey]);

        $this->assertSame(
            $message,
            $response->json('errors')[$errorKey][0] ?? null
        );

        $this->assertSame($before, $this->bookSnapshot());
        $this->assertGuest();
    }

    public static function invalidWriteInputs(): array
    {
        return [
            'タイトル上限超過' => [
                'title', str_repeat('書', 256), 'title',
                'タイトルは255文字以内で入力してください。',
            ],
            '著者上限超過' => [
                'author', str_repeat('著', 256), 'author',
                '著者は255文字以内で入力してください。',
            ],
            '識別番号12桁' => [
                'isbn', '978123456789', 'isbn',
                'ISBNは半角数字13桁で入力してください。',
            ],
            '識別番号14桁' => [
                'isbn', '97812345678901', 'isbn',
                'ISBNは半角数字13桁で入力してください。',
            ],
            '識別番号全角数字' => [
                'isbn', '９７８１２３４５６７８９０', 'isbn',
                'ISBNは半角数字13桁で入力してください。',
            ],
            '出版日不正' => [
                'published_date', '2026-02-30', 'published_date',
                '出版日は有効な日付形式で入力してください。',
            ],
            '説明上限超過' => [
                'description', str_repeat('説', 10001), 'description',
                '説明は10,000文字以内で入力してください。',
            ],
            '画像URL上限超過' => [
                'image_url',
                'https://example.com/'.str_repeat('a', 2029),
                'image_url',
                '画像URLは2,048文字以内で入力してください。',
            ],
            '画像URL形式不正' => [
                'image_url', 'invalid', 'image_url',
                '画像URLは有効なURL形式で入力してください。',
            ],
            'ジャンル空配列' => [
                'genres', [], 'genres',
                'ジャンルを1つ以上選択してください。',
            ],
            'ジャンル配列不正' => [
                'genres', 'invalid', 'genres',
                'ジャンルは配列で入力してください。',
            ],
            'ジャンル不存在' => [
                'genres', [2147483647], 'genres.0',
                '選択したジャンルは存在しません。',
            ],
        ];
    }

    #[DataProvider('forbiddenOwnerValues')]
    public function test_登録者を指定した場合は更新しない(
        mixed $value
    ): void {
        $book = Book::factory()->create();
        $payload = $this->validPayload();

        $payload['isbn'] = $book->isbn;
        $payload['user_id'] = $value;

        $before = $this->bookSnapshot();

        $this->putJson(self::URI.'/'.$book->id, $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonPath(
                'errors.user_id.0',
                '登録者IDは変更できません。'
            );

        $this->assertSame($before, $this->bookSnapshot());
        $this->assertGuest();
    }

    public static function forbiddenOwnerValues(): array
    {
        return [
            '整数' => [1],
            'NULL' => [null],
            '空文字' => [''],
            '空配列' => [[]],
        ];
    }
}
