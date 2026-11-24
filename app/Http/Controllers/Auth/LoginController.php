<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

class LoginController extends Controller
{
    /**
     * 検証済みのログイン情報をFortifyへ渡し、認証結果を返す。
     */
    public function store(
        LoginRequest $request,
        AuthenticatedSessionController $controller
    ): mixed {
        $request->replace($request->validated());

        return $controller->store($request);
    }
}
