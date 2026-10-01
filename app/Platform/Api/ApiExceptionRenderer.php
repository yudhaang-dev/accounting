<?php

namespace App\Platform\Api;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Maps exceptions on API routes to the error shapes of API-TR-11 and API-TR-12.
 * Validation keeps Laravel's `{ message, errors }` (422). Everything else carries a stable `code`.
 * Responses never include `data`, and internal details only appear when APP_DEBUG is on.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::isApi($request)) {
                return null;
            }

            return self::render($e);
        });
    }

    public static function isApi(Request $request): bool
    {
        return $request->is('api/*') || $request->expectsJson();
    }

    public static function render(Throwable $e): ?JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::response($e->errorCode, $e->getMessage(), $e->errors),
            $e instanceof ValidationException => response()->json([
                'message' => $e->getMessage(),
                'errors' => $e->errors(),
            ], 422),
            $e instanceof AuthenticationException => self::response(ErrorCode::Unauthenticated),
            $e instanceof AuthorizationException,
            $e instanceof AccessDeniedHttpException => self::response(ErrorCode::Forbidden),
            $e instanceof ModelNotFoundException,
            $e instanceof NotFoundHttpException => self::response(ErrorCode::NotFound),
            $e instanceof TokenMismatchException,
            $e instanceof HttpExceptionInterface && $e->getStatusCode() === 419 => self::response(ErrorCode::CsrfTokenMismatch),
            $e instanceof TooManyRequestsHttpException => self::response(ErrorCode::TooManyRequests, headers: $e->getHeaders()),
            $e instanceof HttpExceptionInterface && $e->getStatusCode() < 500 => null,
            default => self::serverError($e),
        };
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, string>  $headers
     */
    private static function response(ErrorCode $code, ?string $message = null, array $errors = [], array $headers = []): JsonResponse
    {
        $body = ['message' => $message ?? $code->message(), 'code' => $code->value];

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        return response()->json($body, $code->status(), $headers);
    }

    private static function serverError(Throwable $e): JsonResponse
    {
        $response = self::response(ErrorCode::ServerError);

        if (config('app.debug')) {
            $response->setData([...(array) $response->getData(true), 'exception' => $e::class, 'detail' => $e->getMessage()]);
        }

        return $response;
    }
}
