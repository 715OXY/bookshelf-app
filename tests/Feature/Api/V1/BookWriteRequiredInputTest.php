<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use PHPUnit\Framework\Attributes\DataProvider;

class BookWriteRequiredInputTest extends BookWriteTestCase
{
    #[DataProvider('requiredInputCases')]
    public function test_必須項目の未送信を拒否して既存データを保持する(
        string $method,
        string $field,
        string $message
    ): void {
        $payload = $this->validPayload();

        $book = Book::factory()->create();

        $url = self::URI;

        if ($method === 'PUT') {
            $url .= '/'.$book->id;

            unset($payload['user_id']);

            $payload['isbn'] = $book->isbn;
        }

        unset($payload[$field]);

        $before = $this->bookSnapshot();

        $this->assertGuest('web');

        $this->call($method, $url, $payload)
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertExactJson([
                'message' => '入力内容に誤りがあります。',
                'errors' => [
                    $field => [$message],
                ],
            ]);

        $this->assertGuest('web');

        $this->assertSame(
            $before,
            $this->bookSnapshot()
        );
    }

    public static function requiredInputCases(): array
    {
        $messages = [
            'title' => 'タイトルは必須です。',
            'author' => '著者は必須です。',
            'isbn' => 'ISBNは必須です。',
            'published_date' => '出版日は必須です。',
            'genres' => 'ジャンルを1つ以上選択してください。',
        ];

        $cases = [
            'POST user_id 未送信' => [
                'POST',
                'user_id',
                '登録者IDを入力してください。',
            ],
        ];

        foreach (['POST', 'PUT'] as $method) {
            foreach ($messages as $field => $message) {
                $cases[$method.' '.$field.' 未送信'] = [
                    $method,
                    $field,
                    $message,
                ];
            }
        }

        return $cases;
    }
}
