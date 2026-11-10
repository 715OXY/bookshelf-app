<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReviewAuthorizationTest extends TestCase
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

    public function test_他会員と書籍登録者は他人のレビューを操作できない(): void
    {
        $bookOwner = User::factory()->create();
        $reviewer = User::factory()->create();
        $otherUser = User::factory()->create();

        $book = Book::factory()->for($bookOwner)->create();
        $review = Review::factory()
            ->for($reviewer)
            ->for($book)
            ->create();

        $bookOwner->likedReviews()->attach($review->id);

        $before = $this->reviewData();

        foreach ([$otherUser, $bookOwner] as $actor) {
            $this->actingAs($actor);

            $this->get(route('reviews.edit', $review))
                ->assertForbidden()
                ->assertSessionMissing('success');

            $this->put(route('reviews.update', $review), [
                'rating' => '5',
                'comment' => '不正な更新',
            ])
                ->assertForbidden()
                ->assertSessionMissing('success');

            // 入力不備でも、本人でない場合は認可で拒否する。
            $this->put(route('reviews.update', $review), [
                'rating' => '6',
            ])
                ->assertForbidden()
                ->assertSessionMissing('success');

            $this->delete(route('reviews.destroy', $review))
                ->assertForbidden()
                ->assertSessionMissing('success');

            $this->assertEquals($before, $this->reviewData());
        }
    }

    public function test_ゲストのレビュー操作はログインへ戻りデータを変更しない(): void
    {
        $review = Review::factory()->create();
        $liker = User::factory()->create();
        $liker->likedReviews()->attach($review->id);

        $before = $this->reviewData();

        $this->post(
            route('reviews.store', ['book' => $review->book_id]),
            ['rating' => '5', 'comment' => 'ゲストの投稿']
        )
            ->assertRedirect(route('login'))
            ->assertSessionMissing('success');

        $this->get(route('reviews.edit', $review))
            ->assertRedirect(route('login'));

        $this->put(route('reviews.update', $review), [
            'rating' => '5',
            'comment' => 'ゲストの更新',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionMissing('success');

        $this->delete(route('reviews.destroy', $review))
            ->assertRedirect(route('login'))
            ->assertSessionMissing('success');

        $this->assertEquals($before, $this->reviewData());
    }

    public function test_存在しない書籍やレビューへの操作は見つからない応答となる(): void
    {
        $user = User::factory()->create();
        $book = Book::factory()->for($user)->create();
        $review = Review::factory()->for($user)->for($book)->create();

        $missingBookId = (int) Book::query()->max('id') + 1;
        $missingReviewId = (int) Review::query()->max('id') + 1;

        $before = $this->reviewData();

        $this->actingAs($user);

        $this->post(
            route('reviews.store', ['book' => $missingBookId]),
            ['rating' => '5', 'comment' => '投稿']
        )
            ->assertNotFound()
            ->assertSessionMissing('success');

        $this->get(
            route('reviews.edit', ['review' => $missingReviewId])
        )->assertNotFound();

        $this->put(
            route('reviews.update', ['review' => $missingReviewId]),
            ['rating' => '5', 'comment' => '更新']
        )
            ->assertNotFound()
            ->assertSessionMissing('success');

        $this->delete(
            route('reviews.destroy', ['review' => $missingReviewId])
        )
            ->assertNotFound()
            ->assertSessionMissing('success');

        $this->assertEquals($before, $this->reviewData());
    }

    /**
     * レビューといいねの全レコードを比較用に取得する。
     *
     * @return array<string, array>
     */
    private function reviewData(): array
    {
        return [
            'reviews' => DB::table('reviews')
                ->orderBy('id')
                ->get()
                ->toArray(),
            'review_likes' => DB::table('review_likes')
                ->orderBy('id')
                ->get()
                ->toArray(),
        ];
    }
}
