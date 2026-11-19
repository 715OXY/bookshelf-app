<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ApiExceptionRenderer
{
    /**
     * APIの例外を安全なレスポンスへ変換する。
     */
    public function render(Throwable $e): Response
    {
        if ($e instanceof ApiValidationException) {
            return $e->getResponse();
        }

        if ($e instanceof ValidationException) {
            return response()->json([
                'message' => '入力内容に誤りがあります。',
                'errors' => $e->errors(),
            ], 422);
        }

        if ($e instanceof QueryException) {
            $errors = app(BookConstraintViolation::class)->errors($e);

            if ($errors !== null) {
                return response()->json([
                    'message' => '入力内容に誤りがあります。',
                    'errors' => $errors,
                ], 422);
            }
        }

        if ($e instanceof ModelNotFoundException) {
            return $this->error(
                '指定された書籍は存在しません。',
                404
            );
        }

        if ($e instanceof AuthenticationException) {
            return $this->error('認証が必要です。', 401);
        }

        if ($e instanceof AuthorizationException) {
            return $this->error(
                'この操作は許可されていません。',
                $e->hasStatus() ? $e->status() : 403
            );
        }

        if ($e instanceof HttpExceptionInterface) {
            return $this->error(
                $this->messageForStatus($e->getStatusCode()),
                $e->getStatusCode(),
                $e->getHeaders()
            );
        }

        return $this->error(
            'サーバー内部でエラーが発生しました。',
            500
        );
    }

    /**
     * HTTPステータスに対応する公開用メッセージを取得する。
     */
    private function messageForStatus(int $status): string
    {
        return match ($status) {
            400 => 'リクエストの形式が正しくありません。',
            401 => '認証が必要です。',
            403 => 'この操作は許可されていません。',
            404 => '指定されたAPIは存在しません。',
            405 => '許可されていないHTTPメソッドです。',
            413 => 'リクエストのサイズが上限を超えました。',
            415 => 'サポートされていないメディア形式です。',
            422 => '入力内容に誤りがあります。',
            429 => 'リクエスト回数が上限を超えました。',
            500 => 'サーバー内部でエラーが発生しました。',
            503 => '現在サービスを利用できません。',
            default => $status >= 500
                ? 'サーバー内部でエラーが発生しました。'
                : 'リクエストを処理できませんでした。',
        };
    }

    /**
     * 共通JSONエラー応答を生成する。
     *
     * @param  array<string, mixed>  $headers
     */
    private function error(
        string $message,
        int $status,
        array $headers = []
    ): JsonResponse {
        return response()->json([
            'message' => $message,
        ], $status, $headers);
    }
}
