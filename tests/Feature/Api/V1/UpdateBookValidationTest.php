<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use PHPUnit\Framework\Attributes\DataProvider;

class UpdateBookValidationTest extends BookValidationTestCase
{
    public function test_更新時に自身の_isb_nを使用できる(): void
    {
        $book = Book::factory()->create();
        $payload = $this->validPayload();

        unset($payload['user_id']);

        $payload['isbn'] = $book->isbn;

        $this->putJson(self::URI.'/'.$book->id, $payload)
            ->assertOk()
            ->assertJsonPath('isbn', $book->isbn);

        $this->assertGuest();
    }

    public function test_更新時に他の書籍の_isb_nを拒否する(): void
    {
        $book = Book::factory()->create();
        $otherBook = Book::factory()->create();
        $payload = $this->validPayload();

        unset($payload['user_id']);

        $payload['isbn'] = $otherBook->isbn;

        $before = $this->bookSnapshot();

        $this->putJson(self::URI.'/'.$book->id, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('isbn');

        $this->assertSame($before, $this->bookSnapshot());
    }

    #[DataProvider('forbiddenUserIds')]
    public function test_更新時に登録者_i_dの指定を拒否する(mixed $value): void
    {
        $book = Book::factory()->create();
        $payload = $this->validPayload();

        $payload['isbn'] = $book->isbn;
        $payload['user_id'] = $value;

        $before = $this->bookSnapshot();

        $this->putJson(self::URI.'/'.$book->id, $payload)
            ->assertUnprocessable()
            ->assertJsonPath(
                'errors.user_id.0',
                '登録者IDは変更できません。'
            );

        $this->assertSame($before, $this->bookSnapshot());
    }

    public static function forbiddenUserIds(): array
    {
        return [
            '整数' => [1],
            'NULL' => [null],
            '空文字' => [''],
            '空配列' => [[]],
        ];
    }
}
