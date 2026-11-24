<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class BookTokenAuthenticationTest extends BookWriteTestCase
{
    #[DataProvider('authenticationCases')]
    public function test_書き込みには有効な_bearerトークンを要求する(
        string $method,
        string $mode
    ): void {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $reader->id,
        ]);

        $book->favoritedByUsers()->sync([$reader->id]);
        $review->likedByUsers()->sync([$owner->id]);

        if (in_array($mode, ['web', 'web_invalid'], true)) {
            $this->actingAs($owner, 'web');
        }

        if (in_array($mode, ['valid', 'invalid', 'revoked', 'expired', 'web_invalid'], true)) {
            $issued = $owner->createToken(
                'issue-67-test',
                ['*'],
                $mode === 'expired' ? now()->subMinute() : now()->addHour()
            );

            $token = in_array($mode, ['invalid', 'web_invalid'], true)
                ? $issued->accessToken->id.'|'.str_repeat('0', 40)
                : $issued->plainTextToken;

            if ($mode === 'revoked') {
                $issued->accessToken->delete();
            }

            $this->withHeader('Authorization', 'Bearer '.$token);
        }

        $before = $this->bookSnapshot();
        $uri = $method === 'POST'
            ? self::URI
            : self::URI.'/'.$book->id;

        // Acceptヘッダーを指定せず、JSON応答になることを確認する。
        $response = match ($method) {
            'POST' => $this->post($uri),
            'PUT' => $this->put($uri),
            'DELETE' => $this->delete($uri),
        };

        if ($mode !== 'valid') {
            $response
                ->assertUnauthorized()
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeaderMissing('Location')
                ->assertExactJson(['message' => '認証が必要です。']);

            $this->assertSame($before, $this->bookSnapshot());

            if (in_array($mode, ['web', 'web_invalid'], true)) {
                $this->assertAuthenticatedAs($owner, 'web');
            }

            return;
        }

        if ($method !== 'DELETE') {
            $response
                ->assertUnprocessable()
                ->assertHeader('Content-Type', 'application/json')
                ->assertHeaderMissing('Location')
                ->assertJsonValidationErrors(['title']);

            $this->assertSame($before, $this->bookSnapshot());

            return;
        }

        $response->assertNoContent();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('favorites', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('review_likes', ['review_id' => $review->id]);
        $this->assertDatabaseHas('users', ['id' => $owner->id]);
        $this->assertDatabaseHas('users', ['id' => $reader->id]);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function authenticationCases(): array
    {
        $cases = [];

        foreach (['POST', 'PUT', 'DELETE'] as $method) {
            foreach ([
                'none',
                'invalid',
                'revoked',
                'expired',
                'web',
                'web_invalid',
                'valid',
            ] as $mode) {
                $cases[$method.'_'.$mode] = [$method, $mode];
            }
        }

        return $cases;
    }
}
