<?php

namespace App\Http\Responses;

use Illuminate\Http\RedirectResponse;
use Laravel\Fortify\Contracts\PasswordResetResponse as PasswordResetResponseContract;

class PasswordResetResponse implements PasswordResetResponseContract
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

