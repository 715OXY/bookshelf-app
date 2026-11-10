<?php

namespace App\Policies;

use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /**
     * 認証済み会員にレビュー投稿を許可する。
     */
    public function create(User $user): bool
    {
        return true;
    }

    /**
     * 投稿者本人にレビュー更新を許可する。
     */
    public function update(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }

    /**
     * 投稿者本人にレビュー削除を許可する。
     */
    public function delete(User $user, Review $review): bool
    {
        return $user->id === $review->user_id;
    }
}
