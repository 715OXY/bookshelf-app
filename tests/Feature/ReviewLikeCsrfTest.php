<?php

namespace Tests\Feature;

use App\Http\Middleware\VerifyCsrfToken;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReviewLikeCsrfTest extends TestCase
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
            EnforcedReviewLikeCsrfMiddleware::class
        );
    }

    #[DataProvider('トークンと操作の組み合わせ')]
    public function test_有効なトークンの場合だけいいねを変更する(
        bool $registered,
        ?string $token,
        bool $valid
    ): void {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $review = Review::factory()->create();

        $otherUser->likedReviews()->attach($review->id);

        if ($registered) {
            $user->likedReviews()->attach($review->id);
        }

        $before = DB::table('review_likes')
            ->orderBy('id')->get()->toArray();

        $payload = [];

        if ($token !== null) {
            $payload['_token'] = $token;
        }

        $response = $this->actingAs($user, 'web')
            ->withSession([
                '_token' => 'expected-review-like-csrf-token',
                'csrf_probe' => 'preserved',
            ])
            ->post(route('reviews.like', $review), $payload);

        $this->assertAuthenticatedAs($user, 'web');
        $response->assertSessionHas('csrf_probe', 'preserved');

        if (! $valid) {
            $response
                ->assertStatus(419)
                ->assertHeaderMissing('Location')
                ->assertSessionMissing('success');

            $this->assertEquals(
                $before,
                DB::table('review_likes')
                    ->orderBy('id')->get()->toArray()
            );

            return;
        }

        $response
            ->assertRedirect(route('books.show', [
                'book' => $review->book_id,
            ]))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('success');

        $target = [
            'user_id' => $user->id,
            'review_id' => $review->id,
        ];

        if ($registered) {
            $this->assertDatabaseMissing('review_likes', $target);
        } else {
            $this->assertDatabaseHas('review_likes', $target);
        }

        $this->assertDatabaseHas('review_likes', [
            'user_id' => $otherUser->id,
            'review_id' => $review->id,
        ]);

        $expectedCount = $registered ? 1 : 2;

        $this->assertDatabaseCount('review_likes', $expectedCount);
        $this->assertSame(
            $expectedCount,
            $review->likedByUsers()->count()
        );
    }

    /**
     * @return array<string, array{bool, string|null, bool}>
     */
    public static function トークンと操作の組み合わせ(): array
    {
        $cases = [];

        foreach (['追加' => false, '解除' => true] as $name => $registered) {
            $cases[$name.'でトークンなし'] = [$registered, null, false];
            $cases[$name.'でトークン不一致'] = [
                $registered,
                'wrong-token',
                false,
            ];
            $cases[$name.'で有効トークン'] = [
                $registered,
                'expected-review-like-csrf-token',
                true,
            ];
        }

        return $cases;
    }
}

class EnforcedReviewLikeCsrfMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}
