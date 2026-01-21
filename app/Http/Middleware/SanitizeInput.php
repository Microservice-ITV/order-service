<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SanitizeInput
{
    /**
     * Handle incoming request and sanitize input to prevent XSS
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Skip sanitization for file uploads
        if ($request->hasFile('file') || $request->hasFile('files')) {
            return $next($request);
        }

        // Sanitize request data recursively
        $sanitized = $this->sanitizeData($request->all());
        $request->replace($sanitized);

        return $next($request);
    }

    /**
     * Recursively sanitize all input data
     */
    private function sanitizeData(array $data): array
    {
        return array_map(function ($value) {
            if (is_array($value)) {
                return $this->sanitizeData($value);
            }

            if (is_string($value)) {
                // Use htmlspecialchars for XSS prevention
                // Strip tags for additional protection
                return htmlspecialchars(
                    strip_tags($value),
                    ENT_QUOTES,
                    'UTF-8'
                );
            }

            return $value;
        }, $data);
    }
}