<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Twilio\Services;

use InvalidArgumentException;
use Kanvas\Guild\Leads\Exceptions\LeadMissingContactException;
use Kanvas\Guild\Leads\Exceptions\LeadOptedOutException;
use Throwable;
use Twilio\Exceptions\RestException;

final class MessageErrorClassifier
{
    private const array NON_RETRYABLE_CODES = [
        21211,
        21603,
        21610,
        21660,
        30019,
        30034,
    ];

    public static function classify(Throwable $exception): array
    {
        $code = $exception instanceof RestException ? $exception->getCode() : null;
        $localValidationFailure = $exception instanceof LeadMissingContactException
            || $exception instanceof InvalidArgumentException;
        $localOptOut = $exception instanceof LeadOptedOutException;

        return [
            'twilio_error_code' => $code,
            'classification' => match (true) {
                $localOptOut => 'opted_out',
                $localValidationFailure => 'validation_failed',
                $code === 21610 => 'opted_out',
                in_array($code, [21603, 21660], true) => 'configuration_error',
                $code === 30034 => 'registration_hold',
                in_array($code, [21211, 30019], true) => 'validation_failed',
                default => 'send_failed',
            },
            'retryable' => ! $localOptOut
                && ! $localValidationFailure
                && ! in_array($code, self::NON_RETRYABLE_CODES, true),
        ];
    }
}
