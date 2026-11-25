<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class BookApiAuthorizationTest extends BookWriteTestCase
{
    #[DataProvider('authorizationCases')]
    public function test_認証と対象解決と認可を入力検証より優先する(
        string $method,
        string $actor,
        bool $exists,
        bool $invalid
    ): void {
        config(['app.debug' => true]);

        $owner = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $unrelated = Book::factory()->create(['user_id' => $other->id]);
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $other->id,
        ]);
        $book->favoritedByUsers()->sync([$other->id]);
        $review->likedByUsers()->sync([$owner->id]);

        $payload = $this->validPayload();
        $payload['isbn'] = $book->isbn;

        if ($invalid) {
            $payload['title'] = '';
        }

        if ($actor !== 'guest') {
            $user = $actor === 'owner' ? $owner : $other;
            $issued = $user->createToken(
                'issue-69-authorization-test',
                ['*'],
                now()->addHour()
            );
            $this->withToken($issued->plainTextToken);
        }

        $uri = self::URI.'/'.($exists ? $book->id : '2147483647');
        $before = $this->bookSnapshot();

        // Acceptヘッダーを指定せず、正式APIへリクエストする。
        $response = $method === 'PUT'
            ? $this->put($uri, $payload)
            : $this->delete($uri);

        $status = match (true) {
            $actor === 'guest' => 401,
            ! $exists => 404,
            $actor === 'other' => 403,
            $invalid => 422,
            $method === 'DELETE' => 204,
            default => 200,
        };

        $response->assertStatus($status)->assertHeaderMissing('Location');

        if ($status >= 400) {
            $response->assertHeader('Content-Type', 'application/json');

            if ($status === 422) {
                $response->assertExactJson([
                    'message' => '入力内容に誤りがあります。',
                    'errors' => [
                        'title' => ['タイトルは必須です。'],
                    ],
                ]);
            } else {
                $response->assertExactJson([
                    'message' => match ($status) {
                        401 => '認証が必要です。',
                        403 => 'この操作は許可されていません。',
                        404 => '指定された書籍は存在しません。',
                    },
                ]);
            }

            $this->assertSame($before, $this->bookSnapshot());

            return;
        }

        if ($method === 'PUT') {
            $response
                ->assertHeader('Content-Type', 'application/json')
                ->assertJsonPath('data.id', $book->id)
                ->assertJsonPath('data.title', $payload['title']);

            $this->assertDatabaseHas('books', [
                'id' => $book->id,
                'user_id' => $owner->id,
                ...collect($payload)->except('genres')->all(),
            ]);
            $this->assertEqualsCanonicalizing(
                $payload['genres'],
                $book->genres()->pluck('genres.id')->all()
            );

            $after = $this->bookSnapshot();
            foreach (['users', 'genres', 'reviews', 'favorites', 'review_likes'] as $table) {
                $this->assertSame($before[$table], $after[$table]);
            }

            return;
        }

        $response->assertNoContent();

        $this->assertDatabaseMissing('books', ['id' => $book->id]);
        $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
        $this->assertDatabaseMissing('book_genres', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('favorites', ['book_id' => $book->id]);
        $this->assertDatabaseMissing('review_likes', ['review_id' => $review->id]);

        $after = $this->bookSnapshot();
        foreach (['users', 'genres'] as $table) {
            $this->assertSame($before[$table], $after[$table]);
        }

        $beforeOtherBook = array_values(array_filter(
            $before['books'],
            fn (array $row): bool => $row['id'] === $unrelated->id
        ));
        $this->assertSame($beforeOtherBook, $after['books']);
        $this->assertDatabaseHas('book_genres', ['book_id' => $unrelated->id]);
    }

    /**
     * @return array<string, array{string, string, bool, bool}>
     */
    public static function authorizationCases(): array
    {
        $cases = [];

        foreach (['PUT', 'DELETE'] as $method) {
            foreach (['guest', 'owner', 'other'] as $actor) {
                foreach ([true, false] as $exists) {
                    foreach ($method === 'PUT' ? [false, true] : [false] as $invalid) {
                        $label = implode('_', [
                            $method,
                            $actor,
                            $exists ? 'existing' : 'missing',
                            $invalid ? 'invalid' : 'valid',
                        ]);
                        $cases[$label] = [$method, $actor, $exists, $invalid];
                    }
                }
            }
        }

        return $cases;
    }
}
