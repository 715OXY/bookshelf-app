<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class StoreBookRequest extends ApiFormRequest
{
    /**
     * 書籍登録APIの入力ルール。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'user_id' => [
                'bail',
                'required',
                'integer',
                Rule::exists('users', 'id'),
            ],
            'title' => ['bail', 'required', 'string', 'max:255'],
            'author' => ['bail', 'required', 'string', 'max:255'],
            'isbn' => [
                'bail',
                'required',
                'string',
                'regex:/\A[0-9]{13}\z/',
                Rule::unique('books', 'isbn'),
            ],
            'published_date' => [
                'bail',
                'required',
                'string',
                'date_format:Y-m-d',
            ],
            'description' => [
                'bail',
                'nullable',
                'string',
                'max:10000',
            ],
            'image_url' => [
                'bail',
                'nullable',
                'string',
                'max:2048',
                'url',
            ],
            'genres' => ['bail', 'required', 'array', 'min:1'],
            'genres.*' => [
                'bail',
                'required',
                'integer',
                'distinct',
                Rule::exists('genres', 'id'),
            ],
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
            'user_id.required' => '登録者IDを入力してください。',
            'user_id.integer' => '登録者IDは整数で入力してください。',
            'user_id.exists' => '指定された登録者は存在しません。',

            'title.required' => 'タイトルは必須です。',
            'title.string' => 'タイトルは文字列で入力してください。',
            'title.max' => 'タイトルは255文字以内で入力してください。',

            'author.required' => '著者は必須です。',
            'author.string' => '著者は文字列で入力してください。',
            'author.max' => '著者は255文字以内で入力してください。',

            'isbn.required' => 'ISBNは必須です。',
            'isbn.string' => 'ISBNは文字列で入力してください。',
            'isbn.regex' => 'ISBNは半角数字13桁で入力してください。',
            'isbn.unique' => 'そのISBNは既に使用されています。',

            'published_date.required' => '出版日は必須です。',
            'published_date.string' => '出版日は文字列で入力してください。',
            'published_date.date_format' => '出版日は有効な日付形式で入力してください。',

            'description.string' => '説明は文字列で入力してください。',
            'description.max' => '説明は10,000文字以内で入力してください。',

            'image_url.string' => '画像URLは文字列で入力してください。',
            'image_url.max' => '画像URLは2,048文字以内で入力してください。',
            'image_url.url' => '画像URLは有効なURL形式で入力してください。',

            'genres.required' => 'ジャンルを1つ以上選択してください。',
            'genres.array' => 'ジャンルは配列で入力してください。',
            'genres.min' => 'ジャンルを1つ以上選択してください。',

            'genres.*.required' => 'ジャンルを選択してください。',
            'genres.*.integer' => 'ジャンルの選択値が正しくありません。',
            'genres.*.distinct' => '同じジャンルを重複して選択しないでください。',
            'genres.*.exists' => '選択したジャンルは存在しません。',
        ];
    }
}
