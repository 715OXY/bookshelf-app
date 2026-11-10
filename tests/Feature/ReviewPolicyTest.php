<?php

namespace Tests\Feature;

use App\Models\Book;
use App\Models\Review;
use App\Models\User;
use App\Policies\ReviewPolicy;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class ReviewPolicyTest extends TestCase
{
    public function test_レビューに対応する_policyが解決される(): void
    {
        $this->assertInstanceOf(
            ReviewPolicy::class,
            Gate::getPolicyFor(Review::class)
        );
    }

    public function test_認証済み会員はレビューを投稿できる(): void
    {
        $user = (new User)->forceFill(['id' => 1]);

        $this->assertTrue(
            Gate::forUser($user)->allows('create', Review::class)
        );
    }

    public function test_ゲストは投稿と更新と削除を許可されない(): void
    {
        $review = (new Review)->forceFill([
            'id' => 10,
            'user_id' => 1,
            'book_id' => 100,
        ]);

        $gate = Gate::forUser(null);

        $this->assertFalse($gate->allows('create', Review::class));
        $this->assertFalse($gate->allows('update', $review));
        $this->assertFalse($gate->allows('delete', $review));
    }

    public function test_投稿者本人はレビューを更新と削除できる(): void
    {
        $user = (new User)->forceFill(['id' => 1]);

        $review = (new Review)->forceFill([
            'id' => 10,
            'user_id' => 1,
            'book_id' => 100,
        ]);

        $gate = Gate::forUser($user);

        $this->assertTrue($gate->allows('update', $review));
        $this->assertTrue($gate->allows('delete', $review));
    }

    public function test_他会員はレビューを更新と削除できない(): void
    {
        $otherUser = (new User)->forceFill(['id' => 2]);

        $review = (new Review)->forceFill([
            'id' => 10,
            'user_id' => 1,
            'book_id' => 100,
        ]);

        $gate = Gate::forUser($otherUser);

        $this->assertFalse($gate->allows('update', $review));
        $this->assertFalse($gate->allows('delete', $review));
    }

    public function test_書籍登録者でも他人のレビューは更新と削除できない(): void
    {
        $bookOwner = (new User)->forceFill(['id' => 2]);

        $book = (new Book)->forceFill([
            'id' => 100,
            'user_id' => 2,
        ]);

        $review = (new Review)->forceFill([
            'id' => 10,
            'user_id' => 1,
            'book_id' => 100,
        ]);

        $review->setRelation('book', $book);

        $gate = Gate::forUser($bookOwner);

        $this->assertFalse($gate->allows('update', $review));
        $this->assertFalse($gate->allows('delete', $review));
    }
}
