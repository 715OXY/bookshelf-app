<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use RuntimeException;

class ApiExceptionTest extends BookWriteTestCase
{
    public function test_存在しない書籍の詳細取得はエラーを返す(): void
    {
        $this->get(self::URI.'/0')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '指定された書籍は存在しません。',
            ]);
    }

    public function test_存在しない書籍の更新はエラーを返す(): void
    {
        $payload = $this->validPayload();
        unset($payload['user_id']);

        $before = $this->bookSnapshot();

        $this->put(self::URI.'/0', $payload)
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '指定された書籍は存在しません。',
            ]);

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_存在しない書籍の削除はエラーを返す(): void
    {
        $before = $this->bookSnapshot();

        $this->delete(self::URI.'/0')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '指定された書籍は存在しません。',
            ]);

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_未定義のルートは日本語エラーを返す(): void
    {
        $this->get('/api/v1/nonexistent')
            ->assertStatus(404)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '指定されたAPIは存在しません。',
            ]);
    }

    public function test_未許可のメソッドは許可メソッドの情報を保持する(): void
    {
        $book = Book::factory()->create();
        $before = $this->bookSnapshot();

        $response = $this->patch(self::URI.'/'.$book->id)
            ->assertStatus(405)
            ->assertHeader('Content-Type', 'application/json')
            ->assertExactJson([
                'message' => '許可されていないHTTPメソッドです。',
            ]);

        $allowed = array_map(
            'trim',
            explode(',', (string) $response->headers->get('Allow'))
        );

        $this->assertContains('GET', $allowed);
        $this->assertContains('HEAD', $allowed);
        $this->assertContains('PUT', $allowed);
        $this->assertContains('DELETE', $allowed);
        $this->assertNotContains('PATCH', $allowed);

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_入力不備は項目別のエラーを返す(): void
    {
        $payload = $this->validPayload();
        $payload['title'] = '';

        $before = $this->bookSnapshot();

        $this->post(self::URI, $payload)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertJsonPath('message', '入力内容に誤りがあります。')
            ->assertJsonValidationErrors(['title']);

        $this->assertSame($before, $this->bookSnapshot());
    }

    /**
     * @dataProvider debugModes
     */
    public function test_予期しない内部例外の詳細を公開しない(bool $debug): void
    {
        config(['app.debug' => $debug]);

        $payload = $this->validPayload();
        $before = $this->bookSnapshot();

        $original = Book::getEventDispatcher();
        $dispatcher = clone $original;
        Book::setEventDispatcher($dispatcher);

        $triggered = false;

        $dispatcher->listen(
            'eloquent.creating: '.Book::class,
            function () use (&$triggered): void {
                $triggered = true;

                throw new RuntimeException('API_TEST_INTERNAL_SECRET');
            }
        );

        try {
            $this->post(self::URI, $payload)
                ->assertStatus(500)
                ->assertHeader('Content-Type', 'application/json')
                ->assertExactJson([
                    'message' => 'サーバー内部でエラーが発生しました。',
                ])
                ->assertDontSee('API_TEST_INTERNAL_SECRET');
        } finally {
            Book::setEventDispatcher($original);
        }

        $this->assertTrue($triggered);
        $this->assertSame($before, $this->bookSnapshot());
    }

    public static function debugModes(): array
    {
        return [
            'デバッグ有効' => [true],
            'デバッグ無効' => [false],
        ];
    }
}
