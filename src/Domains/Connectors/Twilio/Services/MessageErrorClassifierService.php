<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Services;

use Twilio\Exceptions\RestException;

final class MessageErrorClassifierService
{
    /**
     * What a refusal from the Messages API means, by Twilio's error code.
     */
    public static function classify(RestException $exception): string
    {
        return match ($exception->getCode()) {
            21610 => 'opted_out',
            21603, 21660 => 'configuration_error',
            30034 => 'registration_hold',
            21211, 30019 => 'validation_failed',
            default => 'send_failed',
        };
    }
}
