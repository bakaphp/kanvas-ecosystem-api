<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Exceptions;

use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Expected business skip: logged, never sent to Sentry. The Handler calls report() before the
 * reportable callbacks (Sentry capture) and stops there unless it returns false. Info level keeps it
 * below the stack's sentry_logs channel (warning+).
 */
final class FirstMessageDisabledException extends RuntimeException
{
    public function report(): void
    {
        Log::info($this->getMessage(), ['exception' => self::class]);
    }
}
