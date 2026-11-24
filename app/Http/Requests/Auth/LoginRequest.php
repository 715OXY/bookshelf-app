<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

class LoginRequest extends FortifyLoginRequest
{
    /**
     * メールアドレスを正規化し、永続ログインを無効にする。
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge([
                'email' => mb_strtolower(trim($email), 'UTF-8'),
            ]);
        }

        $this->merge([
            'remember' => false,
        ]);
    }

    /**
     * ログイン入力のバリデーションルールを返す。
     */
    public function rules(): array
    {
        return [
            'email' => [
                'bail',
                'required',
                'string',
                'max:255',
                'email',
            ],
            'password' => [
                'bail',
                'required',
                'string',
            ],
        ];
    }

    /**
     * ログイン入力に対する日本語のエラーメッセージを返す。
     */
    public function messages(): array
    {
        return [
            'email.required' => 'メールアドレスを入力してください。',
            'email.string' => 'メールアドレスは文字列で入力してください。',
            'email.max' => 'メールアドレスは255文字以下で入力してください。',
            'email.email' => 'メールアドレスは有効なメールアドレス形式で入力してください。',

            'password.required' => 'パスワードを入力してください。',
            'password.string' => 'パスワードは文字列で入力してください。',
        ];
    }

    /**
     * ログイン入力の検証失敗時に、メールアドレスのみ保持してログイン画面へ戻す。
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()
                ->route('login')
                ->withErrors($validator)
                ->withInput([
                    'email' => is_string($this->input('email'))
                        ? $this->input('email')
                        : '',
                ])
        );
    }
}
