<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyWebhookSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        $secret = config('webhooks.secret');
        if ($secret === '' || $secret === null) {
            return $next($request);
        }

        $signature = $request->header('X-Webhook-Signature');
        if (!$signature || !str_starts_with($signature, 'sha256=')) {
            return response()->json(['error' => 'Missing or invalid webhook signature'], 401);
        }

        $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

        if (!hash_equals($expected, $signature)) {
            return response()->json(['error' => 'Invalid webhook signature'], 401);
        }

        return $next($request);
    }
}
