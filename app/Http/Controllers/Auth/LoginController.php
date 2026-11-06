<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use Laravel\Fortify\Http\Controllers\AuthenticatedSessionController;

class LoginController extends Controller
{
    public function store(
        LoginRequest $request,
        AuthenticatedSessionController $controller
    ): mixed {
        $request->replace($request->validated());

        return $controller->store($request);
    }
}
