<?php

namespace App\Http\Requests;

use App\Models\Review;

class UpdateReviewRequest extends StoreReviewRequest
{
    /**
     * レビュー投稿者本人の更新を許可する。
     */
    public function authorize(): bool
    {
        $review = $this->route('review');

        return $review instanceof Review
            && ($this->user()?->can('update', $review) ?? false);
    }

    /**
     * 投稿用と同じ日本語メッセージを使用する。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages();
    }

    /**
     * 入力不備時は対象レビューの編集フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('reviews.edit', ['review' => $this->route('review')]);
    }
}
