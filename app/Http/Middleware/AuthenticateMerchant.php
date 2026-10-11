<?php

namespace App\Http\Middleware;

use App\Services\MerchantApiKeyService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateMerchant
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, ?string $ability = null): Response
    {
        $apiKey = $request->bearerToken();

        if ($apiKey === null || $apiKey === '') {
            return response()->json(['message' => 'Autentikasi merchant diperlukan.'], Response::HTTP_UNAUTHORIZED);
        }

        $authenticatedKey = app(MerchantApiKeyService::class)->authenticate($apiKey, $ability);

        if ($authenticatedKey === null) {
            return response()->json(['message' => 'Kredensial merchant tidak valid.'], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('merchant', $authenticatedKey->merchant);
        $request->attributes->set('merchant_api_key', $authenticatedKey);

        return $next($request);
    }
}
