<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOnboardingComplete
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user()?->fresh();
        abort_unless($user && ! $user->isBlocked(), 403);

        if (! $user->pd_consent_at || ! $user->email_verified_at) {
            // Onboarding must remain accessible, including its Livewire updates.
            if (! $request->routeIs('filament.app.pages.onboarding')) {
                return redirect('/cabinet/onboarding');
            }
        }

        return $next($request);
    }
}
