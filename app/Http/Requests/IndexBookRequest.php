<?php

namespace App\Http\Requests;

use App\Models\Book;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class IndexBookRequest extends FormRequest
{
    /**
     * ゲストを含め、一覧の閲覧を許可する。
     */
    public function authorize(): bool
    {
        return Gate::allows('viewAny', Book::class);
    }

    /**
     * Web一覧の検索条件。ページ補正はControllerで扱う。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'keyword' => ['bail', 'nullable', 'string', 'max:255'],
            'genre' => ['bail', 'nullable', 'integer', 'min:1', Rule::exists('genres', 'id')],
            'sort' => ['bail', 'nullable', 'string', Rule::in(['latest', 'newest', 'oldest', 'title', 'rating'])],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'keyword.string' => 'キーワードは文字列で入力してください。',
            'keyword.max' => 'キーワードは255文字以内で入力してください。',
            'genre.integer' => 'ジャンルの選択値が正しくありません。',
            'genre.min' => 'ジャンルの選択値が正しくありません。',
            'genre.exists' => '選択したジャンルは存在しません。',
            'sort.string' => '並び順の指定が正しくありません。',
            'sort.in' => '並び順の指定が正しくありません。',
        ];
    }

    /**
     * URLへ保持する入力表記は変えず、内部の正式なソート名を返す。
     */
    public function sortName(): string
    {
        return match ($this->validated('sort')) {
            'oldest' => 'oldest',
            'title' => 'title',
            'rating' => 'rating',
            default => 'latest',
        };
    }

    /**
     * 不正なGET条件は描画せず解除し、提供Bladeの既存通知欄へ案内する。
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        throw new HttpResponseException(
            redirect()->route('books.index')
                ->withErrors($validator, $this->errorBag)
                ->with('error', implode(' ', $validator->errors()->all())
                    .' 検索条件を解除し、全書籍を表示しています。')
        );
    }
}
