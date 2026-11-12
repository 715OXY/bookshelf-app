<?php

namespace App\Http\Controllers;

use App\Models\Review;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ReviewLikeController extends Controller
{
    /**
     * 認証ユーザーのレビューいいねを追加・解除する。
     */
    public function toggle(Request $request, Review $review): RedirectResponse
    {
        $userId = $request->user()->getAuthIdentifier();

        DB::transaction(function () use ($userId, $review): void {
            // 同じユーザーのいいね操作を順番に処理する。
            $user = User::query()
                ->whereKey($userId)
                ->lockForUpdate()
                ->firstOrFail();

            $likes = $user->likedReviews();

            if ($likes->whereKey($review->getKey())->exists()) {
                $user->likedReviews()->detach($review->getKey());

                return;
            }

            $user->likedReviews()->attach($review->getKey());
        });

        return redirect()->route('books.show', [
            'book' => $review->book_id,
        ]);
    }
}
