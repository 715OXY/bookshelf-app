<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use Illuminate\Http\Exceptions\HttpResponseException;
use PHPUnit\Framework\Attributes\DataProvider;

class ApiExceptionSafetyTest extends BookWriteTestCase
{
    #[DataProvider('unsafeResponseTypes')]
    public function test_専用例外以外のレスポンスを直接公開しない(
        string $type
    ): void {
        config(['app.debug' => true]);

        $payload = $this->validPayload();
        $before = $this->bookSnapshot();

        $original = Book::getEventDispatcher();
        $dispatcher = clone $original;

        Book::setEventDispatcher($dispatcher);

        $triggered = false;

        $dispatcher->listen(
            'eloquent.creating: '.Book::class,
            function () use ($type, &$triggered): void {
                $triggered = true;

                $response = match ($type) {
                    'html' => response(
                        '<html>API_UNSAFE_SECRET</html>',
                        500
                    ),
                    'json' => response()->json([
                        'message' => 'API_UNSAFE_SECRET',
                    ], 422),
                    'redirect' => redirect('/API_UNSAFE_SECRET'),
                };

                $response->headers->set(
                    'X-Internal-Secret',
                    'API_UNSAFE_SECRET'
                );

                throw new HttpResponseException($response);
            }
        );

        try {
            $this->post(self::URI, $payload)
                ->assertStatus(500)
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeaderMissing('Location')
                ->assertHeaderMissing('X-Internal-Secret')
                ->assertExactJson([
                    'message' => 'サーバー内部でエラーが発生しました。',
                ])
                ->assertDontSee('API_UNSAFE_SECRET');
        } finally {
            Book::setEventDispatcher($original);
        }

        $this->assertTrue($triggered);
        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_正規の入力検証エラーは項目別情報を返す(): void
    {
        $payload = $this->validPayload();
        $payload['title'] = '';

        $before = $this->bookSnapshot();

        $this->post(self::URI, $payload)
            ->assertStatus(422)
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonPath(
                'message',
                '入力内容に誤りがあります。'
            )
            ->assertJsonValidationErrors(['title']);

        $this->assertSame($before, $this->bookSnapshot());
    }

    public static function unsafeResponseTypes(): array
    {
        return [
            'HTML応答' => ['html'],
            'JSON応答' => ['json'],
            'リダイレクト応答' => ['redirect'],
        ];
    }
}
