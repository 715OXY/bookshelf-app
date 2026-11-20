<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use PHPUnit\Framework\Attributes\DataProvider;

class BookWriteInputTypeTest extends BookWriteTestCase
{
    #[DataProvider('invalidInputCases')]
    public function test_不正な入力型と識別子を拒否して既存データを保持する(
        string $method,
        string $field,
        mixed $value,
        array $errors
    ): void {
        $payload = $this->validPayload();
        $book = Book::factory()->create();
        $url = self::URI;

        if ($method === 'PUT') {
            $url .= '/'.$book->id;

            unset($payload['user_id']);

            $payload['isbn'] = $book->isbn;
        }

        if ($field === 'duplicate_genres') {
            $payload['genres'] = [
                $payload['genres'][0],
                $payload['genres'][0],
            ];
        } else {
            $payload[$field] = $value;
        }

        $before = $this->bookSnapshot();

        $this->assertGuest('web');

        $this->call($method, $url, $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertExactJson([
                'message' => '入力内容に誤りがあります。',
                'errors' => $errors,
            ]);

        $this->assertGuest('web');
        $this->assertSame($before, $this->bookSnapshot());
    }

    public static function invalidInputCases(): array
    {
        $common = [
            'タイトルが配列' => [
                'title', ['invalid'],
                ['title' => ['タイトルは文字列で入力してください。']],
            ],
            '著者が配列' => [
                'author', ['invalid'],
                ['author' => ['著者は文字列で入力してください。']],
            ],
            'ISBNが数値型' => [
                'isbn', 9781234567890,
                ['isbn' => ['ISBNは文字列で入力してください。']],
            ],
            '出版日が配列' => [
                'published_date', ['invalid'],
                ['published_date' => ['出版日は文字列で入力してください。']],
            ],
            '説明が配列' => [
                'description', ['invalid'],
                ['description' => ['説明は文字列で入力してください。']],
            ],
            '画像URLが配列' => [
                'image_url', ['invalid'],
                ['image_url' => ['画像URLは文字列で入力してください。']],
            ],
            'ジャンルIDが未入力' => [
                'genres', [null],
                ['genres.0' => ['ジャンルを選択してください。']],
            ],
            'ジャンルIDが整数でない' => [
                'genres', ['invalid'],
                ['genres.0' => ['ジャンルの選択値が正しくありません。']],
            ],
            'ジャンルIDが重複' => [
                'duplicate_genres', null,
                [
                    'genres.0' => ['同じジャンルを重複して選択しないでください。'],
                    'genres.1' => ['同じジャンルを重複して選択しないでください。'],
                ],
            ],
        ];

        $cases = [
            'POST 登録者IDの型不正' => [
                'POST', 'user_id', 'invalid',
                ['user_id' => ['登録者IDは整数で入力してください。']],
            ],
            'POST 登録者IDの不存在' => [
                'POST', 'user_id', 2147483647,
                ['user_id' => ['指定された登録者は存在しません。']],
            ],
        ];

        foreach (['POST', 'PUT'] as $method) {
            foreach ($common as $label => $case) {
                $cases[$method.' '.$label] = [
                    $method,
                    ...$case,
                ];
            }
        }

        return $cases;
    }
}
