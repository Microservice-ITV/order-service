<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal Order Service routes called by Wallet Service (x-service-key).
 */
final class VerifyWalletInternalKey
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.wallet.api_key', '');
        if ($expected === '') {
            abort(503, 'Wallet internal API not configured');
        }

        $provided = (string) $request->header('x-service-key', '');
        if ($provided === '' || ! hash_equals($expected, $provided)) {
            abort(401, 'Invalid service key');
        }

        return $next($request);
    }
}
