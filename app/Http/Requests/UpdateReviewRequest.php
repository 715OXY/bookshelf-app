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
     * レビュー編集時はコメントを必須とする。
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['comment'] = [
            'bail', 'required', 'string', 'max:10000',
        ];

        return $rules;
    }

    /**
     * 編集時の個別エラーメッセージを定義する。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return array_replace(parent::messages(), [
            'rating.between' => '評価は1〜5の整数で入力してください。',
            'comment.required' => 'コメントは必須です。',
        ]);
    }

    /**
     * 入力不備時は対象レビューの編集フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('reviews.edit', ['review' => $this->route('review')]);
    }
}
