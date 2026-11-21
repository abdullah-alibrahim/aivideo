<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The web first-run helper performs installation checks and can write provider
 * keys. Once a verified account exists, only a studio administrator may use it.
 */
class EnsureFirstRunAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $setupComplete = User::query()->whereNotNull('email_verified_at')->exists();

        if ($setupComplete && ! $request->user()?->isStudioAdmin()) {
            abort(404);
        }

        return $next($request);
    }
}
