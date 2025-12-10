<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\RegisterResponse as RegisterResponseContract;

class RegisterResponse implements RegisterResponseContract
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

