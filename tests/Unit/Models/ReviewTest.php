<?php

namespace Tests\Unit\Models;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ReviewTest extends ModelTestCase
{
    public function test_許可された属性だけを一括代入できる(): void
    {
        $review = new Review([
            'user_id' => 1,
            'book_id' => 2,
            'rating' => 4,
            'comment' => '読みやすい書籍でした。',
            'id' => 999,
        ]);

        $this->assertSame([
            'user_id',
            'book_id',
            'rating',
            'comment',
        ], $review->getFillable());

        $this->assertSame(1, $review->user_id);
        $this->assertSame(2, $review->book_id);
        $this->assertSame(4, $review->rating);
        $this->assertSame('読みやすい書籍でした。', $review->comment);
        $this->assertNull($review->id);
    }

    public function test_評価は整数型に変換される(): void
    {
        $review = new Review;

        foreach (['1', '3', '5'] as $rating) {
            $review->rating = $rating;

            $this->assertIsInt($review->rating);
            $this->assertSame((int) $rating, $review->rating);
        }
    }

    public function test_投稿者との多対一関連が定義されている(): void
    {
        $relation = (new Review)->user();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(User::class, $relation->getRelated());
        $this->assertSame('user_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getOwnerKeyName());
    }

    public function test_対象書籍との多対一関連が定義されている(): void
    {
        $relation = (new Review)->book();

        $this->assertInstanceOf(BelongsTo::class, $relation);
        $this->assertInstanceOf(Book::class, $relation->getRelated());
        $this->assertSame('book_id', $relation->getForeignKeyName());
        $this->assertSame('id', $relation->getOwnerKeyName());
    }

    public function test_いいね登録者との多対多関連が定義されている(): void
    {
        $relation = (new Review)->likedByUsers();

        $this->assertInstanceOf(BelongsToMany::class, $relation);
        $this->assertInstanceOf(User::class, $relation->getRelated());
        $this->assertSame('review_likes', $relation->getTable());
        $this->assertSame('review_id', $relation->getForeignPivotKeyName());
        $this->assertSame('user_id', $relation->getRelatedPivotKeyName());
        $this->assertContains('created_at', $relation->getPivotColumns());
        $this->assertContains('updated_at', $relation->getPivotColumns());
    }
}
