<?php

namespace App\Exceptions;

use Illuminate\Http\Exceptions\HttpResponseException;

final class ApiValidationException extends HttpResponseException
{
    /**
     * API入力検証用のJSON例外応答を生成する。
     *
     * @param  array<string, array<int, string>>  $errors
     */
    public function __construct(array $errors)
    {
        parent::__construct(
            response()->json([
                'message' => '入力内容に誤りがあります。',
                'errors' => $errors,
            ], 422)
        );
    }
}
