<?php

declare(strict_types=1);

namespace Kanvas\HumanResources\Meetings\Transcripts\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * The transcript cannot start with the provider it resolved to. `errorCode` is what the failed
 * transcript row stores, with the provider and model that were asked for.
 */
class TranscriptProviderException extends ValidationException
{
    public const string NOT_CONFIGURED = 'provider_not_configured';
    public const string CANNOT_HEAR_AUDIO = 'provider_cannot_hear_audio';

    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly string $provider,
        public readonly string $model,
    ) {
        parent::__construct($message);
    }
}
