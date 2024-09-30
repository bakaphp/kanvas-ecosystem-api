<?php

namespace App\Exceptions;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
use Kanvas\Auth\Exceptions\AuthenticationException;
use Nuwave\Lighthouse\Exceptions\AuthenticationException as ExceptionsAuthenticationException;
use Sentry\Laravel\Integration;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        // Add exceptions you don't want to report
    ];

    /**
     * A list of the inputs that are never flashed for validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            Integration::captureUnhandledException($e);
        });
    }

    /**
     * Render an exception into an HTTP response.
     *
     * @param mixed $request
     */
    public function render($request, Throwable $exception): JsonResponse
    {
        if (app()->isProduction()) {
            $response = $this->prepareErrorResponse($exception);

            if ($response) {
                return $response;
            }

            return response()->json([
                'message' => 'A server error has occurred. We are looking into it',
            ], 503);
        }

        return parent::render($request, $exception);
    }

    /**
     * Prepare a structured error response based on the exception type.
     */
    protected function prepareErrorResponse(Throwable $exception): ?JsonResponse
    {
        // Use match expression to handle different exception types
        return match (true) {
            $exception instanceof AuthorizationException => $this->buildErrorResponse($exception, 403, $exception->getMessage()),
            $exception instanceof AuthenticationException => $this->buildErrorResponse($exception, 403, $exception->getMessage()),
            $exception instanceof ExceptionsAuthenticationException => $this->buildErrorResponse($exception, 403, $exception->getMessage()),
            // Add more exceptions here as needed
            default => null,
        };
    }

    /**
     * Build the error response in the desired structure.
     */
    protected function buildErrorResponse(Throwable $exception, int $status, string $message): JsonResponse
    {
        return response()->json([
            'errors' => [
                [
                    'message' => $message,
                    'extensions' => [
                        'reason' => null, // You can populate this if needed
                    ],
                ],
            ],
        ], $status);
    }

    /**
     * Send the exception to the error log.
     */
    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }
}
