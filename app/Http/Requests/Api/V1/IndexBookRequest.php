<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class IndexBookRequest extends ApiFormRequest
{
    /**
     * 書籍一覧APIの入力ルール。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'keyword' => [
                'bail',
                'nullable',
                'string',
                'max:255',
            ],
            'genre_id' => [
                'bail',
                'nullable',
                'integer',
                Rule::exists('genres', 'id'),
            ],
            'page' => [
                'bail',
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:2147483647',
            ],
            'per_page' => [
                'bail',
                'sometimes',
                'required',
                'integer',
                'min:1',
                'max:100',
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
            'keyword.string' => 'キーワードは文字列で入力してください。',
            'keyword.max' => 'キーワードは255文字以下で入力してください。',

            'genre_id.integer' => 'ジャンルIDは整数で入力してください。',
            'genre_id.exists' => '指定されたジャンルは存在しません。',

            'page.required' => 'ページ番号を入力してください。',
            'page.integer' => 'ページ番号は整数で入力してください。',
            'page.min' => 'ページ番号は1以上で入力してください。',
            'page.max' => 'ページ番号が上限を超えています。',

            'per_page.required' => 'ページ件数を入力してください。',
            'per_page.integer' => 'ページ件数は整数で入力してください。',
            'per_page.min' => 'ページ件数は1以上で入力してください。',
            'per_page.max' => 'ページ件数は100以下で入力してください。',
        ];
    }
}
