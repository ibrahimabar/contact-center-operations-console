<?php

use App\Exceptions\ApiException;
use App\Http\Middleware\FailOpenThrottle;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Lets the SPA authenticate via an HttpOnly session cookie (CSRF-
        // protected) instead of a bearer token — see DECISIONS.md "Auth".
        $middleware->statefulApi();

        // Every use of the `throttle` alias (including throttleApi() below)
        // now fails open on a Redis outage instead of 500ing — see
        // DECISIONS.md "Redis usage" and FailOpenThrottle.
        $middleware->alias(['throttle' => FailOpenThrottle::class]);

        // General safety net on every /api/* route; specific endpoints
        // (login, interaction creation) layer a stricter named limiter on
        // top — see DECISIONS.md "Redis usage".
        $middleware->throttleApi();
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // Every branch below produces the same envelope shape:
        // { "message": string, "code": string, "errors"?: object, ...extra }
        // so the frontend has exactly one response shape to parse for every
        // failure mode — see DECISIONS.md "API conventions & error format".
        $exceptions->render(function (ApiException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = response()->json(array_merge([
                'message' => $e->getMessage(),
                'code' => $e->errorCode(),
            ], $e->context()), $e->statusCode());

            if (method_exists($e, 'retryAfterSeconds')) {
                $response->header('Retry-After', (string) $e->retryAfterSeconds());
            }

            return $response;
        });

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'validation_failed',
                'errors' => $e->errors(),
            ], $e->status);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'Authentication is required to access this resource.',
                'code' => 'unauthenticated',
            ], Response::HTTP_UNAUTHORIZED);
        });

        // Laravel's own Handler::prepareException() converts every
        // AuthorizationException (from $this->authorize()/Gate/Policy
        // denials) into this Symfony type before rendering — registering
        // against AuthorizationException directly here would never match.
        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'You are not authorized to perform this action.',
                'code' => 'forbidden',
            ], Response::HTTP_FORBIDDEN);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The requested resource could not be found.',
                'code' => 'not_found',
            ], Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => 'The requested resource could not be found.',
                'code' => 'not_found',
            ], Response::HTTP_NOT_FOUND);
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            $response = response()->json([
                'message' => 'Too many requests. Please slow down and try again shortly.',
                'code' => 'too_many_requests',
            ], Response::HTTP_TOO_MANY_REQUESTS);

            if ($e->getHeaders()['Retry-After'] ?? null) {
                $response->header('Retry-After', $e->getHeaders()['Retry-After']);
            }

            return $response;
        });

        // Last-resort fallback for any other HTTP-level exception reaching
        // an api/* route, so no error response can ever fall back to
        // Laravel's HTML error page for a JSON client.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if (! $request->is('api/*')) {
                return null;
            }

            return response()->json([
                'message' => $e->getMessage() ?: 'An unexpected error occurred.',
                'code' => 'http_error',
            ], $e->getStatusCode());
        });
    })->create();
