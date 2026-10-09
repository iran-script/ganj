<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\RequestContext;
use App\Http\Middleware\SecureHeaders;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append([RequestContext::class, SecureHeaders::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (QueryException $exception): ?bool {
            if (request()->routeIs('identity.*', 'organizations.*')) {
                logger()->error(request()->routeIs('organizations.*') ? 'organizations.persistence_failed' : 'identity.persistence_failed', ['request_id' => request()->attributes->get('request_id'), 'sqlstate' => $exception->errorInfo[0] ?? null]);

                return false;
            }

            return null;
        });
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
        $exceptions->render(function (ApiException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'details' => $e->details ?: (object) []]], $e->status);
            }
        });
        $exceptions->render(function (Throwable $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                if ($e instanceof ValidationException) {
                    return response()->json(['error' => ['code' => 'VALIDATION_FAILED', 'message' => 'The request contains invalid fields.', 'details' => ['fields' => $e->errors()]]], 422);
                }
                if ($e instanceof AuthenticationException) {
                    return response()->json(['error' => ['code' => 'AUTHENTICATION_REQUIRED', 'message' => 'Authentication is required.', 'details' => (object) []]], 401);
                }
                if ($e instanceof AuthorizationException && $e->status() === 404) {
                    return response()->json(['error' => ['code' => 'RESOURCE_NOT_FOUND', 'message' => 'The requested resource was not found.', 'details' => (object) []]], 404);
                }
                if ($e instanceof AuthorizationException || ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 403)) {
                    return response()->json(['error' => ['code' => 'AUTHORIZATION_DENIED', 'message' => 'This action is not permitted.', 'details' => (object) []]], 403);
                }
                if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 429) {
                    $retry = (int) ($e->getHeaders()['Retry-After'] ?? 60);

                    return response()->json(['error' => ['code' => 'RATE_LIMITED', 'message' => 'Too many requests.', 'details' => ['retry_after_seconds' => $retry]]], 429, $e->getHeaders());
                }
                report($e);
                $status = $request->route() === null ? 404 : ($e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500);
                $code = $status === 404 ? 'NOT_FOUND' : 'INTERNAL_ERROR';
                $message = $status === 404 ? 'The requested resource was not found.' : 'An unexpected error occurred.';

                return response()->json(['error' => ['code' => $code, 'message' => $message, 'details' => []]], $status);
            }
        });
    })->create();
