<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class StudioAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('studio.security.enabled')) {
            return $next($request);
        }

        $username = (string) config('studio.security.username');
        $password = (string) config('studio.security.password');
        $provided = $request->getUser();
        $providedPassword = $request->getPassword();

        if ($username !== '' && $password !== ''
            && hash_equals($username, (string) $provided)
            && hash_equals($password, (string) $providedPassword)) {
            return $next($request);
        }

        return response('Studio authentication required.', 401, [
            'WWW-Authenticate' => 'Basic realm="AI Reel Studio"',
            'Cache-Control' => 'no-store',
        ]);
    }
}
