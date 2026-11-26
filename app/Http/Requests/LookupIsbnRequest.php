<?php

namespace App\Http\Requests;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;

class LookupIsbnRequest extends FormRequest
{
    /**
     * ISBN検索には書籍登録権限を適用する。保存時の所有者認可とは分ける。
     */
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    /**
     * パスISBNだけを検証対象とし、同名クエリによる上書きを防ぐ。
     *
     * @return array<string, mixed>
     */
    public function validationData(): array
    {
        return ['isbn' => $this->route('isbn')];
    }

    /**
     * ASCII数字13桁を要求する。接頭辞・チェックディジット・DB重複検証は追加しない。
     *
     * @return array<string, array<string>>
     */
    public function rules(): array
    {
        return ['isbn' => ['bail', 'required', 'string', 'regex:/\A[0-9]{13}\z/']];
    }

    /**
     * 専用JSONエラーに使用する入力エラーの文言を返す。
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'isbn.required' => 'ISBNは半角数字13桁で入力してください。',
            'isbn.string' => 'ISBNは半角数字13桁で入力してください。',
            'isbn.regex' => 'ISBNは半角数字13桁で入力してください。',
        ];
    }
}
