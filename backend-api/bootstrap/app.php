<?php

use App\Custom\ApiResponse;
use App\Exceptions\PayOSReconciliationException;
use App\Exceptions\InvoiceFinancialEditException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Exceptions\InvalidSignatureException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (InvalidSignatureException $exception, Request $request) {
            if ($request->is('api/email/verify/*')) {
                return redirect()->away(rtrim(config('app.frontend_url'), '/').'/email-verified?status=invalid');
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(function (ValidationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                'The given data was invalid.',
                $exception->errors(),
                422
            );
        });

        $exceptions->render(function (AuthenticationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Unauthenticated.', null, 401);
        });

        $exceptions->render(function (AuthorizationException|AccessDeniedHttpException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Forbidden.', null, 403);
        });

        $exceptions->render(function (ModelNotFoundException|NotFoundHttpException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error('Not Found.', null, 404);
        });

        $exceptions->render(function (PayOSReconciliationException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), null, $exception->status());
        });

        $exceptions->render(function (InvoiceFinancialEditException $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error($exception->getMessage(), null, 422);
        });

        $exceptions->render(function (Throwable $exception, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            return ApiResponse::error(
                app()->hasDebugModeEnabled()
                    ? $exception->getMessage()
                    : 'Server Error.',
                null,
                500
            );
        });
    })->create();
