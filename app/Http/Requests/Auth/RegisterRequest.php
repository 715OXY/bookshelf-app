<?php

namespace App\Http\Requests\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class RegisterRequest extends FormRequest
{
    /**
     * 未認証ユーザーによる会員登録を許可する。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 会員名とメールアドレスを登録前に正規化する。
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        $email = $this->input('email');

        if (is_string($name)) {
            $this->merge([
                'name' => preg_replace(
                    '/^[\s\p{Z}]+|[\s\p{Z}]+$/u',
                    '',
                    $name
                ),
            ]);
        }

        if (is_string($email)) {
            $this->merge([
                'email' => mb_strtolower(trim($email), 'UTF-8'),
            ]);
        }
    }

    /**
     * 会員登録入力のバリデーションルールを返す。
     */
    public function rules(): array
    {
        return [
            'name' => [
                'bail',
                'required',
                'string',
                'max:255',
            ],
            'email' => [
                'bail',
                'required',
                'string',
                'max:255',
                'email',
                Rule::unique('users', 'email'),
            ],
            'password' => [
                'bail',
                'required',
                'string',
                'min:8',
                'max:64',
                'regex:/\A[\x21-\x7E]+\z/',
                'confirmed',
            ],
            'password_confirmation' => [
                'bail',
                'required',
                'string',
                'max:64',
            ],
        ];
    }

    /**
     * 会員登録入力に対する日本語のエラーメッセージを返す。
     */
    public function messages(): array
    {
        return [
            'name.required' => '名前を入力してください。',
            'name.string' => '名前は文字列で入力してください。',
            'name.max' => '名前は255文字以下で入力してください。',

            'email.required' => 'メールアドレスを入力してください。',
            'email.string' => 'メールアドレスは文字列で入力してください。',
            'email.max' => 'メールアドレスは255文字以下で入力してください。',
            'email.email' => 'メールアドレスは有効なメールアドレス形式で入力してください。',
            'email.unique' => 'このメールアドレスは既に登録されています。',

            'password.required' => 'パスワードを入力してください。',
            'password.string' => 'パスワードは文字列で入力してください。',
            'password.min' => 'パスワードは8文字以上で入力してください。',
            'password.max' => 'パスワードは64文字以下で入力してください。',
            'password.regex' => 'パスワードは空白を含まない半角英数字・記号で入力してください。',
            'password.confirmed' => 'パスワードと確認用の入力が一致しません。',

            'password_confirmation.required' => '確認用パスワードを入力してください。',
            'password_confirmation.string' => '確認用パスワードは文字列で入力してください。',
            'password_confirmation.max' => '確認用パスワードは64文字以下で入力してください。',
        ];
    }

    /**
     * 会員登録の検証失敗時に、名前とメールアドレスのみ保持して登録画面へ戻す。
     *
     * @throws HttpResponseException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            redirect()
                ->route('register')
                ->withErrors($validator)
                ->withInput([
                    'name' => is_string($this->input('name'))
                        ? $this->input('name')
                        : '',
                    'email' => is_string($this->input('email'))
                        ? $this->input('email')
                        : '',
                ])
        );
    }
}
