<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Validation\Rule;

class UpdateBookRequest extends StoreBookRequest
{
    /**
     * 書籍更新APIの入力ルール。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        $rules = parent::rules();

        $rules['user_id'] = ['missing'];

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
     * 更新時の入力エラーの日本語メッセージ。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        $messages = parent::messages();

        $messages['user_id.missing'] = '登録者IDは変更できません。';

        return $messages;
    }
}
