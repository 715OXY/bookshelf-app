<?php

namespace App\Http\Responses\Auth;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $request->session()->forget('url.intended');

        return redirect('/');
    }
}
