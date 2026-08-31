<?php

use App\Http\Middleware\SanitizeInput;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Ensure CORS headers are sent for all API routes (e.g. frontend at :3000 → :8001)
        $middleware->prepend(\Illuminate\Http\Middleware\HandleCors::class);

        $middleware->alias([
            'auth' => \App\Http\Middleware\Authenticate::class,
            'webhook.signature' => \App\Http\Middleware\VerifyWebhookSignature::class,
            'wallet.internal' => \App\Http\Middleware\VerifyWalletInternalKey::class,
        ]);
        
        // XSS Protection Middleware
        $middleware->append(SanitizeInput::class);
    })
    ->withExceptions(function ($exceptions) {
        // Authentication (401)
        $exceptions->render(function (AuthenticationException $e, $request) {
            return response()->json([
                'message' => 'Unauthenticated'
            ], Response::HTTP_UNAUTHORIZED);
        });

        // Authorization (403)
        $exceptions->render(function (AccessDeniedHttpException $e, $request) {
            return response()->json([
                'message' => 'You are not allowed to perform this action'
            ], Response::HTTP_FORBIDDEN);
        });
    })->create();