<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewCsrfTest extends TestCase
{
    use RefreshDatabase;

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));

        $connection = DB::connection();

        $this->assertSame('mysql', $connection->getDriverName());
        $this->assertSame('testing', $connection->getDatabaseName());

        $result = $connection->selectOne(
            'SELECT DATABASE() AS database_name'
        );

        $this->assertSame('testing', $result->database_name);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->bind(
            VerifyCsrfToken::class,
            EnforcedReviewCsrfMiddleware::class
        );
    }

    #[DataProvider('トークンと操作の組み合わせ')]
    public function test_トークン不備を拒否し有効な場合だけレビューを変更する(
        string $method,
        ?string $token,
        bool $valid
    ): void {
        $user = User::factory()->create();
        $review = Review::factory()->for($user)->create([
            'rating' => 2,
            'comment' => '変更前',
        ]);

        $user->likedReviews()->attach($review->id);

        $before = $this->関連データ();
        $bookId = $review->book_id;

        $url = match ($method) {
            'POST' => route('reviews.store', ['book' => $bookId]),
            'PUT' => route('reviews.update', $review),
            'DELETE' => route('reviews.destroy', $review),
        };

        $payload = [
            'rating' => '5',
            'comment' => 'トークン検証後の内容',
        ];

        if ($token !== null) {
            $payload['_token'] = $token;
        }

        $response = $this->actingAs($user, 'web')
            ->withSession([
                '_token' => 'expected-review-csrf-token',
                'csrf_probe' => 'preserved',
            ])
            ->call($method, $url, $payload);

        $this->assertAuthenticatedAs($user, 'web');
        $response->assertSessionHas('csrf_probe', 'preserved');

        if (! $valid) {
            $response
                ->assertStatus(419)
                ->assertSessionMissing('success');

            $this->assertSame($before, $this->関連データ());

            return;
        }

        $message = match ($method) {
            'POST' => 'レビューを投稿しました。',
            'PUT' => 'レビューを更新しました。',
            'DELETE' => 'レビューを削除しました。',
        };

        $response
            ->assertRedirect(route('books.show', ['book' => $bookId]))
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', $message);

        if ($method === 'DELETE') {
            $this->assertDatabaseMissing('reviews', ['id' => $review->id]);
            $this->assertDatabaseMissing('review_likes', [
                'review_id' => $review->id,
            ]);

            return;
        }

        $expected = [
            'user_id' => $user->id,
            'book_id' => $bookId,
            'rating' => 5,
            'comment' => 'トークン検証後の内容',
        ];

        if ($method === 'PUT') {
            $expected['id'] = $review->id;
        }

        $this->assertDatabaseHas('reviews', $expected);
        $this->assertDatabaseCount('reviews', $method === 'POST' ? 2 : 1);
        $this->assertDatabaseHas('review_likes', [
            'user_id' => $user->id,
            'review_id' => $review->id,
        ]);
    }

    /**
     * @return array<string, array{string, string|null, bool}>
     */
    public static function トークンと操作の組み合わせ(): array
    {
        $cases = [];

        foreach (['投稿' => 'POST', '更新' => 'PUT', '削除' => 'DELETE'] as $name => $method) {
            $cases[$name.'でトークンなし'] = [$method, null, false];
            $cases[$name.'でトークン不一致'] = [$method, 'wrong-token', false];
            $cases[$name.'で有効トークン'] = [
                $method,
                'expected-review-csrf-token',
                true,
            ];
        }

        return $cases;
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function 関連データ(): array
    {
        $snapshot = [];

        foreach ([
            'users',
            'books',
            'genres',
            'reviews',
            'book_genres',
            'favorites',
            'review_likes',
        ] as $table) {
            $snapshot[$table] = DB::table($table)
                ->orderBy('id')
                ->get()
                ->map(fn (object $row): array => (array) $row)
                ->all();
        }

        return $snapshot;
    }
}

class EnforcedReviewCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
