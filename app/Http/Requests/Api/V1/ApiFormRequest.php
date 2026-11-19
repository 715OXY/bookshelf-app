<?php

namespace App\Http\Requests\Api\V1;

use App\Exceptions\ApiValidationException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

abstract class ApiFormRequest extends FormRequest
{
    /**
     * 基本APIの未認証利用を許可する。
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * 入力不備時はJSON形式のエラーを返す。
     *
     * @throws ApiValidationException
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new ApiValidationException(
            $validator->errors()->toArray()
        );
    }
}
