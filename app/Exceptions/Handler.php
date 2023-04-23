<?php

namespace App\Exceptions;

use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Http\JsonResponse;
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
     *
     * @return void
     */
    public function register()
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
            return response()->json([
                'message' => 'A server error has occurred. We are looking into it',
            ], 503);
        }

        return parent::render($request, $exception);
    }

    /**
     * Send the exception to the error log.
     */
    public function report(Throwable $exception): void
    {
        parent::report($exception);
    }
}
