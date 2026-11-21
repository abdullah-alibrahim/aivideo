<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureStudioAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isStudioAdmin(), 403);

        return $next($request);
    }
}
