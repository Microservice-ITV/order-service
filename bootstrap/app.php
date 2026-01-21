<?php

use App\Http\Middleware\SanitizeInput;
use App\Http\Middleware\RateLimitAPI;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware aliases for easier reference in routes
        $middleware->alias([
            'auth'              => \App\Http\Middleware\Authenticate::class,
            'sanitize.input'    => SanitizeInput::class,
            'rate.limit'        => RateLimitAPI::class,
        ]);
        
        // Global middleware (applied to all requests)
        $middleware->append(SanitizeInput::class);
    })
    ->withExceptions(function ($exceptions) {
        // Authentication (401)
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthenticated',
            ], Response::HTTP_UNAUTHORIZED);
        });

        // Authorization (403)
        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to perform this action',
            ], Response::HTTP_FORBIDDEN);
        });

        // Rate Limiting (429)
        $exceptions->render(function (TooManyRequestsHttpException $e, $request) {
            return response()->json([
                'success' => false,
                'message' => 'Too many requests. Please try again later.',
            ], Response::HTTP_TOO_MANY_REQUESTS);
        });
    })->create();
