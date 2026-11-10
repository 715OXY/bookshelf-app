<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreReviewRequest;
use App\Http\Requests\UpdateReviewRequest;
use App\Models\Book;
use App\Models\Review;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

class ReviewController extends Controller
{
    /**
     * 認証済み会員が書籍にレビューを投稿する。
     */
    public function store(
        StoreReviewRequest $request,
        Book $book
    ): RedirectResponse {
        $attributes = $request->safe()->only(['rating', 'comment']);
        $attributes['user_id'] = $request->user()->id;
        $attributes['book_id'] = $book->id;

        Review::create($attributes);

        return redirect()
            ->route('books.show', $book)
            ->with('success', 'レビューを投稿しました。');
    }

    /**
     * 投稿者本人にレビュー編集フォームを表示する。
     */
    public function edit(Review $review): View
    {
        $this->authorize('update', $review);

        $review->load('book');

        return view('reviews.edit', compact('review'));
    }

    /**
     * 投稿者本人が評価とコメントを更新する。
     */
    public function update(
        UpdateReviewRequest $request,
        Review $review
    ): RedirectResponse {
        $attributes = $request->safe()->only(['rating', 'comment']);

        $review->update($attributes);

        return redirect()
            ->route('books.show', ['book' => $review->book_id])
            ->with('success', 'レビューを更新しました。');
    }

    /**
     * 投稿者本人がレビューを削除する。
     */
    public function destroy(Review $review): RedirectResponse
    {
        $this->authorize('delete', $review);

        $bookId = $review->book_id;

        DB::transaction(function () use ($review): void {
            $review->delete();
        });

        return redirect()
            ->route('books.show', ['book' => $bookId])
            ->with('success', 'レビューを削除しました。');
    }
}
