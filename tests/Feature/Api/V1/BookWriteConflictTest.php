<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use Illuminate\Support\Facades\DB;

class BookWriteConflictTest extends BookWriteTestCase
{
    public function test_登録直前の識別番号重複を検出する(): void
    {
        $payload = $this->validPayload();
        $before = $this->bookSnapshot();

        $this->withBookEvent('creating', function (Book $book): void {
            DB::table('books')->insert($book->getAttributes());
        }, function () use ($payload): void {
            $this->post(self::URI, $payload)
                ->assertStatus(422)
                ->assertExactJson([
                    'message' => '入力内容に誤りがあります。',
                    'errors' => [
                        'isbn' => ['このISBNは既に登録されています。'],
                    ],
                ]);
        });

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_更新直前の識別番号重複を検出する(): void
    {
        $book = Book::factory()->create();
        $otherBook = Book::factory()->create();
        $payload = $this->validPayload();
        unset($payload['user_id']);

        $before = $this->bookSnapshot();

        $this->withBookEvent('updating', function (Book $target) use ($otherBook): void {
            DB::table('books')
                ->where('id', $otherBook->id)
                ->update(['isbn' => $target->isbn]);
        }, function () use ($book, $payload): void {
            $this->put(self::URI.'/'.$book->id, $payload)
                ->assertStatus(422)
                ->assertExactJson([
                    'message' => '入力内容に誤りがあります。',
                    'errors' => [
                        'isbn' => ['このISBNは既に登録されています。'],
                    ],
                ]);
        });

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_保存直前のジャンル消失を検出する(): void
    {
        $payload = $this->validPayload();
        $before = $this->bookSnapshot();

        $this->withBookEvent('creating', function () use ($payload): void {
            DB::table('genres')
                ->where('id', $payload['genres'][0])
                ->delete();
        }, function () use ($payload): void {
            $this->post(self::URI, $payload)
                ->assertStatus(422)
                ->assertExactJson([
                    'message' => '入力内容に誤りがあります。',
                    'errors' => [
                        'genres' => ['選択したジャンルは存在しません。選び直してください。'],
                    ],
                ]);
        });

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_保存直前の登録者消失を検出する(): void
    {
        $payload = $this->validPayload();
        $before = $this->bookSnapshot();

        $this->withBookEvent('creating', function () use ($payload): void {
            DB::table('users')
                ->where('id', $payload['user_id'])
                ->delete();
        }, function () use ($payload): void {
            $this->post(self::URI, $payload)
                ->assertStatus(422)
                ->assertExactJson([
                    'message' => '入力内容に誤りがあります。',
                    'errors' => [
                        'user_id' => ['指定された登録者は存在しません。'],
                    ],
                ]);
        });

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_分類対象外のデータベース例外は内部情報を公開しない(): void
    {
        config(['app.debug' => true]);

        $payload = $this->validPayload();
        $before = $this->bookSnapshot();

        $this->withBookEvent('creating', function (): void {
            DB::table('nonexistent_api_test_table')->get();
        }, function () use ($payload): void {
            $this->post(self::URI, $payload)
                ->assertStatus(500)
                ->assertExactJson([
                    'message' => 'サーバー内部でエラーが発生しました。',
                ]);
        });

        $this->assertSame($before, $this->bookSnapshot());
    }

    /**
     * モデルイベントを利用して保存直前の障害を再現する。
     */
    private function withBookEvent(
        string $event,
        callable $listener,
        callable $request
    ): void {
        $original = Book::getEventDispatcher();
        $dispatcher = clone $original;

        Book::setEventDispatcher($dispatcher);

        $triggered = false;

        $dispatcher->listen(
            'eloquent.'.$event.': '.Book::class,
            function (Book $book) use ($listener, &$triggered): void {
                $triggered = true;
                $listener($book);
            }
        );

        try {
            $request();
        } finally {
            Book::setEventDispatcher($original);
        }

        $this->assertTrue($triggered);
    }
}
