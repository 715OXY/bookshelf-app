<?php

namespace Tests\Feature\Api\V1;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use PHPUnit\Framework\Attributes\DataProvider;

class BookApiOwnerInputTest extends BookWriteTestCase
{
    public function test_登録者指定なしでトークンの会員名義で登録する(): void
    {
        $owner = User::factory()->create();
        $payload = $this->validPayload();
        unset($payload['description'], $payload['image_url']);

        $issued = $owner->createToken('issue-68-test', ['*'], now()->addHour());
        $this->withToken($issued->plainTextToken);

        $response = $this->post(self::URI, $payload)
            ->assertCreated()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.description', null)
            ->assertJsonPath('data.image_url', null);

        $book = Book::query()->sole();

        $this->assertSame($owner->id, $book->user_id);
        $this->assertDatabaseHas('books', [
            'id' => $response->json('data.id'),
            'user_id' => $owner->id,
            'isbn' => $payload['isbn'],
            'published_date' => $payload['published_date'],
        ]);
        $this->assertEqualsCanonicalizing(
            $payload['genres'],
            $book->genres()->pluck('genres.id')->all()
        );
    }

    #[DataProvider('forbiddenOwnerInputs')]
    public function test_登録者指定を拒否して業務データを保持する(
        string $method,
        mixed $value
    ): void {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $other->id,
        ]);
        $book->favoritedByUsers()->sync([$other->id]);
        $review->likedByUsers()->sync([$owner->id]);

        $payload = $this->validPayload();
        $payload['user_id'] = match ($value) {
            'self' => $owner->id,
            'other' => $other->id,
            default => $value,
        };

        if ($method === 'PUT') {
            $payload['isbn'] = $book->isbn;
        }

        $issued = $owner->createToken('issue-68-test', ['*'], now()->addHour());
        $this->withToken($issued->plainTextToken);

        $before = $this->bookSnapshot();

        $response = $method === 'POST'
            ? $this->post(self::URI, $payload)
            : $this->put(self::URI.'/'.$book->id, $payload);

        $response
            ->assertUnprocessable()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertExactJson([
                'message' => '入力内容に誤りがあります。',
                'errors' => [
                    'user_id' => [
                        $method === 'POST'
                            ? '登録者IDは指定できません。'
                            : '登録者IDは変更できません。',
                    ],
                ],
            ]);

        $this->assertSame($before, $this->bookSnapshot());
    }

    public function test_本人の書籍更新で所有者と関連データを保持する(): void
    {
        $owner = User::factory()->create();
        $reader = User::factory()->create();
        $book = Book::factory()->create(['user_id' => $owner->id]);
        $review = Review::factory()->create([
            'book_id' => $book->id,
            'user_id' => $reader->id,
        ]);
        $book->favoritedByUsers()->sync([$reader->id]);
        $review->likedByUsers()->sync([$owner->id]);

        $payload = $this->validPayload();
        $payload['isbn'] = $book->isbn;

        $issued = $owner->createToken('issue-68-test', ['*'], now()->addHour());
        $this->withToken($issued->plainTextToken);

        $before = $this->bookSnapshot();

        $this->put(self::URI.'/'.$book->id, $payload)
            ->assertOk()
            ->assertHeader('Content-Type', 'application/json')
            ->assertHeaderMissing('Location')
            ->assertJsonPath('data.title', $payload['title'])
            ->assertJsonPath('data.isbn', $book->isbn);

        $book->refresh();

        $this->assertSame($owner->id, $book->user_id);
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
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function forbiddenOwnerInputs(): array
    {
        $cases = [];

        foreach (['POST', 'PUT'] as $method) {
            foreach ([
                '本人ID' => 'self',
                '他人ID' => 'other',
                'NULL' => null,
                '空文字' => '',
                '空配列' => [],
            ] as $label => $value) {
                $cases[$method.'_'.$label] = [$method, $value];
            }
        }

        return $cases;
    }
}
