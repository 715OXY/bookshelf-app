<?php

namespace App\Http\Requests;

use App\Models\Genre;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
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
            'name.required' => 'ジャンル名は必須です。',
            'name.string' => 'ジャンル名は文字列で入力してください。',
            'name.max' => 'ジャンル名は255文字以内で入力してください。',
            'name.unique' => 'そのジャンル名は既に使用されています。',
        ];
    }

    /**
     * 入力不備時は登録フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('genres.create');
    }

    /**
     * 入力エラーと、提供Bladeで表示できる旧入力を返す。
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        $name = $this->input('name');

        $oldInput = [
            'name' => is_string($name) ? $name : '',
        ];

        $response = redirect()
            ->to($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput($oldInput);

        throw new HttpResponseException($response);
    }
}
