<?php

namespace App\Http\Requests;

use App\Models\Review;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreReviewRequest extends FormRequest
{
    /**
     * 認証済み会員の投稿を許可する。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Review::class) ?? false;
    }

    /**
     * レビュー投稿の入力ルール。
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return [
            'rating' => ['bail', 'required', 'integer', 'between:1,5'],
            'comment' => ['bail', 'nullable', 'string', 'max:10000'],
        ];
    }

    /**
     * 入力エラーの日本語メッセージ。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'rating.required' => '評価を選択してください。',
            'rating.integer' => '評価は整数で指定してください。',
            'rating.between' => '評価は1〜5で選択してください。',
            'comment.string' => 'コメントは文字列で入力してください。',
            'comment.max' => 'コメントは10,000文字以下で入力してください。',
        ];
    }

    /**
     * 入力エラーと、提供Bladeで表示できる旧入力を返す。
     */
    protected function failedValidation(Validator $validator): void
    {
        // JSONを要求された場合は標準の検証エラー処理を使用する。
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        $rating = $this->input('rating');
        $comment = $this->input('comment');

        $oldInput = [
            'rating' => is_string($rating) || is_int($rating)
                ? $rating
                : '',
            'comment' => is_string($comment) ? $comment : '',
        ];

        $response = redirect()
            ->to($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput($oldInput);

        throw new HttpResponseException($response);
    }

    /**
     * 入力不備時は対象書籍の詳細へ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('books.show', ['book' => $this->route('book')]);
    }
}
