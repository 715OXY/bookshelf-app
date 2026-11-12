<?php

namespace Tests\Feature;

use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewLikeToggleTest extends TestCase
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

    public function test_追加解除再追加で登録状態といいね数が切り替わる(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();

        $this->actingAs($user, 'web');

        foreach ([1, 0, 1] as $expectedCount) {
            $this->post(route('reviews.like', $review))
                ->assertRedirect(route('books.show', [
                    'book' => $review->book_id,
                ]))
                ->assertSessionHasNoErrors()
                ->assertSessionMissing('success');

            $this->assertDatabaseCount('review_likes', $expectedCount);
            $this->assertSame(
                $expectedCount,
                $review->likedByUsers()->count()
            );
        }

        $this->assertDatabaseHas('review_likes', [
            'user_id' => $user->id,
            'review_id' => $review->id,
        ]);
    }

    public function test_自分のレビューにもいいねを追加解除できる(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create(['user_id' => $user->id]);

        $this->actingAs($user, 'web');

        foreach ([true, false] as $registered) {
            $this->post(route('reviews.like', $review))
                ->assertRedirect(route('books.show', [
                    'book' => $review->book_id,
                ]));

            $target = [
                'user_id' => $user->id,
                'review_id' => $review->id,
            ];

            if ($registered) {
                $this->assertDatabaseHas('review_likes', $target);
            } else {
                $this->assertDatabaseMissing('review_likes', $target);
            }
        }
    }

    public function test_送信されたidを使わず他の関連を保持する(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $review = Review::factory()->create([
            'user_id' => $otherUser->id,
        ]);
        $otherReview = Review::factory()->create();

        $otherUser->likedReviews()->attach($review->id);
        $user->likedReviews()->attach($otherReview->id);

        $this->actingAs($user, 'web');

        foreach ([true, false] as $registered) {
            $this->post(route('reviews.like', $review), [
                'user_id' => $otherUser->id,
                'review_id' => $otherReview->id,
                'book_id' => $otherReview->book_id,
            ])->assertRedirect(route('books.show', [
                'book' => $review->book_id,
            ]));

            $target = [
                'user_id' => $user->id,
                'review_id' => $review->id,
            ];

            if ($registered) {
                $this->assertDatabaseHas('review_likes', $target);
            } else {
                $this->assertDatabaseMissing('review_likes', $target);
            }

            $this->assertDatabaseHas('review_likes', [
                'user_id' => $otherUser->id,
                'review_id' => $review->id,
            ]);
            $this->assertDatabaseHas('review_likes', [
                'user_id' => $user->id,
                'review_id' => $otherReview->id,
            ]);
            $this->assertDatabaseCount('review_likes', $registered ? 3 : 2);
            $this->assertSame(
                $registered ? 2 : 1,
                $review->likedByUsers()->count()
            );
        }
    }

    public function test_ゲストの要求ではいいねを変更しない(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        $user->likedReviews()->attach($review->id);

        $this->post(route('reviews.like', $review))
            ->assertRedirect(route('login'));

        $this->assertDatabaseCount('review_likes', 1);
        $this->assertDatabaseHas('review_likes', [
            'user_id' => $user->id,
            'review_id' => $review->id,
        ]);
    }

    public function test_削除されたレビューへの要求は404となり他のいいねを保持する(): void
    {
        $user = User::factory()->create();
        $review = Review::factory()->create();
        $reviewId = $review->id;
        $review->delete();

        $otherReview = Review::factory()->create();
        $user->likedReviews()->attach($otherReview->id);

        $this->actingAs($user, 'web')
            ->post('/reviews/'.$reviewId.'/like')
            ->assertNotFound();

        $this->assertDatabaseCount('review_likes', 1);
        $this->assertDatabaseHas('review_likes', [
            'user_id' => $user->id,
            'review_id' => $otherReview->id,
        ]);
    }
}
