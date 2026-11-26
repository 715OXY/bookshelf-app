<?php

namespace App\Http\Requests;

use App\Models\Book;
use Illuminate\Foundation\Http\FormRequest;

class LookupIsbnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('create', Book::class) ?? false;
    }

    /** @return array<string, mixed> */
    public function validationData(): array
    {
        return ['isbn' => $this->route('isbn')];
    }

    /** @return array<string, array<string>> */
    public function rules(): array
    {
        return ['isbn' => ['bail', 'required', 'string', 'regex:/\A[0-9]{13}\z/']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'isbn.required' => 'ISBNは半角数字13桁で入力してください。',
            'isbn.string' => 'ISBNは半角数字13桁で入力してください。',
            'isbn.regex' => 'ISBNは半角数字13桁で入力してください。',
        ];
    }
}
