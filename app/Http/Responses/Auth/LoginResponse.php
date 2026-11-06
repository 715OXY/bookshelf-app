<?php

namespace App\Http\Responses\Auth;

use App\Http\Middleware\Authenticate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $intended = $request->session()->pull('url.intended');

        return redirect()->to($this->destination($intended));
    }

    private function destination(mixed $intended): string
    {
        if (! is_string($intended)) {
            return '/';
        }

        if (
            str_contains($intended, '\\')
            || preg_match('/[\x00-\x20\x7F]/', $intended)
        ) {
            return '/';
        }

        $target = parse_url($intended);
        $app = parse_url(config('app.url'));

        if (! is_array($target) || ! is_array($app)) {
            return '/';
        }

        if (
            ! $this->sameOrigin($target, $app)
            || isset($target['user'])
            || isset($target['pass'])
            || isset($target['fragment'])
        ) {
            return '/';
        }

        $path = $target['path'] ?? '/';

        if ($path === '/api' || str_starts_with($path, '/api/')) {
            return '/';
        }

        try {
            $route = Route::getRoutes()->match(
                Request::create($intended, 'GET')
            );
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return '/';
        }

        if (
            in_array($route->getName(), [
                'login',
                'register',
                'logout',
            ], true)
        ) {
            return '/';
        }

        $authMiddleware = [
            'auth',
            'auth:web',
            Authenticate::class,
            Authenticate::class.':web',
        ];

        foreach ($route->gatherMiddleware() as $middleware) {
            if (in_array($middleware, $authMiddleware, true)) {
                return $intended;
            }
        }

        return '/';
    }

    /**
     * @param  array<string, mixed>  $target
     * @param  array<string, mixed>  $app
     */
    private function sameOrigin(array $target, array $app): bool
    {
        $targetScheme = strtolower($target['scheme'] ?? '');
        $appScheme = strtolower($app['scheme'] ?? '');

        if (
            ! in_array($targetScheme, ['http', 'https'], true)
            || $targetScheme !== $appScheme
        ) {
            return false;
        }

        $targetHost = strtolower($target['host'] ?? '');
        $appHost = strtolower($app['host'] ?? '');

        if ($targetHost === '' || $targetHost !== $appHost) {
            return false;
        }

        $defaultPort = $targetScheme === 'https' ? 443 : 80;

        return ($target['port'] ?? $defaultPort)
            === ($app['port'] ?? $defaultPort);
    }
}
