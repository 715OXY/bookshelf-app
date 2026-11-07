<?php

namespace App\Http\Requests;

use App\Models\Book;
use Illuminate\Validation\Rule;

class UpdateBookRequest extends StoreBookRequest
{
    /**
     * 書籍の作成者の更新を許可する。
     */
    public function authorize(): bool
    {
        $book = $this->route('book');

        return $book instanceof Book
            && ($this->user()?->can('update', $book) ?? false);
    }

    /**
     * ISBNの一意性判定から更新対象自身を除外する。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['isbn'] = [
            'bail',
            'required',
            'string',
            'regex:/\A[0-9]{13}\z/',
            Rule::unique('books', 'isbn')->ignore($this->route('book')),
        ];

        return $rules;
    }

    /**
     * 登録用と同じ日本語メッセージを使用する。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return parent::messages();
    }

    /**
     * 入力不備時は対象書籍の編集フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('books.edit', ['book' => $this->route('book')]);
    }
}
