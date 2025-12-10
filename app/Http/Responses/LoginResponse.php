<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse
    {
        $user = $request->user();

        $intended = $user?->isAdmin()
            ? route('dashboard')
            : route('client.home');

        return redirect()->intended($intended);
    }
}

