<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

class RateLimitAPI
{
    /**
     * Rate limit API requests to prevent abuse
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Get authenticated user ID, fall back to IP address
        $user = auth('api')->user();
        $authId = $user ? $user->getAuthIdentifier() : null;
        
        $key = $authId ? "api:{$authId}" : "api:ip:{$request->ip()}";

        // Allow 100 requests per minute per user
        if (RateLimiter::tooManyAttempts($key, 100)) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
            ], 429);
        }

        RateLimiter::hit($key, 60); // 60 seconds

        return $next($request)
            ->header('X-RateLimit-Limit', '100')
            ->header('X-RateLimit-Remaining', RateLimiter::remaining($key, 100));
    }
}
