<?php

/**
 * Planning
 *
 * - Middleware for sanitizing incoming request data.
 * - Applies:
 *   - Key sanitization using `FILTER_SANITIZE_STRING`.
 *   - Value sanitization using `strip_tags` for string values.
 * - Ensures protection against XSS, malicious inputs, and invalid request data.
 * - Applied early in request lifecycle before hitting controllers.
 */

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    public function handle(Request $request, Closure $next)
    {
        $data = $request->all();

        foreach ($data as $key => $value) {
            $cleanKey = filter_var($key, FILTER_SANITIZE_STRING);
            $cleanValue = is_string($value) ? strip_tags($value) : $value;
            $data[$cleanKey] = $cleanValue;
        }

        // Replace request data with sanitized data
        $request->replace($data);

        return $next($request);
    }
}