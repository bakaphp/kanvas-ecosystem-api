<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;

/**
 * What a provider said about one send. Acceptance is all a send call can ever report — whether the
 * message arrived comes later, as an event.
 */
final readonly class TransportResult
{
    /**
     * @param list<string> $warnings
     * @param bool $recipientOptedOut the provider refused because the recipient unsubscribed with
     *                                it directly, which we may not have heard about any other way
     */
    private function __construct(
        public DeliveryStatusEnum $status,
        public ?string $externalId = null,
        public ?string $errorCode = null,
        public ?string $error = null,
        public array $warnings = [],
        public bool $recipientOptedOut = false,
        public ?string $providerStatus = null
    ) {
    }

    /**
     * @param list<string> $warnings
     */
    public static function accepted(string $externalId, array $warnings = []): self
    {
        return new self(DeliveryStatusEnum::ACCEPTED, $externalId, warnings: $warnings);
    }

    public static function failed(string $errorCode, ?string $error = null, bool $recipientOptedOut = false): self
    {
        return new self(
            DeliveryStatusEnum::FAILED,
            errorCode: $errorCode,
            error: $error,
            recipientOptedOut: $recipientOptedOut
        );
    }

    /**
     * For a lookup of a message already sent, where the provider may report any later state.
     */
    public static function observed(
        DeliveryStatusEnum $status,
        string $providerStatus,
        string $externalId,
        ?string $errorCode = null,
        ?string $error = null
    ): self {
        return new self(
            $status,
            $externalId,
            $errorCode,
            $error,
            providerStatus: $providerStatus
        );
    }
}
