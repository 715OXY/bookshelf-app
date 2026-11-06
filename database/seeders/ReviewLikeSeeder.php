<?php

namespace Database\Seeders;

use App\Models\Review;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class ReviewLikeSeeder extends Seeder
{
    public function run(): void
    {
        $users = User::orderBy('id')->get();
        $reviews = Review::orderBy('id')->get();

        if ($users->count() !== 5 || $reviews->isEmpty()) {
            throw new RuntimeException(
                '指定の5ユーザーとレビューを投入してから実行してください。'
            );
        }

        foreach ($reviews as $reviewIndex => $review) {
            $likeCount = $reviewIndex % 4;

            $userIds = $users
                ->reject(
                    fn(User $user): bool => $user->id === $review->user_id
                )
                ->take($likeCount)
                ->pluck('id')
                ->all();

            $review->likedByUsers()->syncWithoutDetaching($userIds);
        }
    }
}
