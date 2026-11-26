<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class Handler extends ExceptionHandler
{
    /** @var array<int, class-string<Throwable>> */
    protected $dontReport = [IsbnLookupException::class];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * 公開APIと既存Webの例外応答を分離する。
     */
    public function render($request, Throwable $e): SymfonyResponse
    {
        if ($request->routeIs('books.isbn')) {
            [$status, $message] = match (true) {
                $e instanceof IsbnLookupException => [$e->status, $e->getMessage()],
                $e instanceof AuthenticationException => [401, 'ログインし直してからISBN検索を利用してください。'],
                $e instanceof AuthorizationException => [403, 'ISBN検索を利用する権限がありません。'],
                $e instanceof ValidationException => [422, 'ISBNは半角数字13桁で入力してください。'],
                default => [500, '書籍情報の取得に失敗しました。'],
            };

            return response()->json(['error' => $message], $status);
        }

        if ($request->is('api/v1', 'api/v1/*')) {
            return app(ApiExceptionRenderer::class)->render($e);
        }

        return parent::render($request, $e);
    }

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (request()->routeIs('books.isbn')) {
                // 外部URLやキーを含み得る例外本文・トレースは記録しない。
                Log::error('ISBN検索処理で障害が発生しました。', [
                    'exception_type' => get_class($e),
                ]);

                return false;
            }

            return null;
        });

        $this->renderable(function (BookRegistrationFailedException $e): Response {
            return response($e->getMessage(), 500, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        });
    }
}
