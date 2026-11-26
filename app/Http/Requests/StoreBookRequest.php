<?php

namespace App\Http\Requests;

use App\Models\Book;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreBookRequest extends FormRequest
{
    /**
     * 認証済み会員の登録を許可する。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    /**
     * 書籍登録の入力ルール。
     *
     * @return array<string, array<mixed>>
     */
    public function rules(): array
    {
        return [
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
                'nullable',
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

    /**
     * 入力不備時は登録フォームへ戻す。
     */
    protected function getRedirectUrl(): string
    {
        return route('books.create');
    }

    /**
     * 入力エラーと、提供Bladeで表示できる旧入力を返す。
     */
    protected function failedValidation(Validator $validator): void
    {
        if ($this->expectsJson()) {
            parent::failedValidation($validator);
        }

        $oldInput = collect([
            'title',
            'author',
            'isbn',
            'published_date',
            'description',
            'image_url',
        ])->mapWithKeys(function (string $field): array {
            $value = $this->input($field);

            return [$field => is_string($value) ? $value : ''];
        })->all();

        $genres = $this->input('genres');

        $oldInput['genres'] = is_array($genres)
            ? array_values(array_filter(
                $genres,
                fn ($value): bool => is_string($value) || is_int($value)
            ))
            : [];

        $response = redirect()
            ->to($this->getRedirectUrl())
            ->withErrors($validator, $this->errorBag)
            ->withInput($oldInput);

        throw new HttpResponseException($response);
    }
}
