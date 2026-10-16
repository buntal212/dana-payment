<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireInternalHttps
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isSecure()) {
            return response()->json(['message' => 'HTTPS diperlukan untuk layanan internal.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
