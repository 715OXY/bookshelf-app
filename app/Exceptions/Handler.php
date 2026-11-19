<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;
use Throwable;

class Handler extends ExceptionHandler
{
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
            //
        });

        $this->renderable(function (BookRegistrationFailedException $e): Response {
            return response($e->getMessage(), 500, [
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        });
    }
}
