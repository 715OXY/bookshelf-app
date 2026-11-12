<?php

namespace App\Http\Requests;

use App\Models\Genre;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreGenreRequest extends FormRequest
{
    /**
     * 認証済み会員のジャンル登録を許可する。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Genre::class) ?? false;
    }

    /**
     * ジャンル登録の入力ルール。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
                Rule::unique('genres', 'name'),
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
            'name.required' => 'ジャンル名を入力してください。',
            'name.string' => 'ジャンル名は文字列で入力してください。',
            'name.max' => 'ジャンル名は255文字以下で入力してください。',
            'name.unique' => 'このジャンル名は既に登録されています。',
        ];
    }

    /**
     * 入力不備時は登録フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('genres.create');
    }
}
