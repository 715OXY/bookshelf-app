<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Laravel\Fortify\Http\Requests\LoginRequest as FortifyLoginRequest;

class LoginRequest extends FortifyLoginRequest
{
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

    protected function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(
            redirect()
                ->route('login')
                ->withErrors($validator)
                ->withInput($this->only('email'))
        );
    }
}
