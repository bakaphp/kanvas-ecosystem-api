<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\DataTransferObject;

/**
 * The readiness line for "Alanube webhooks": what the action did and whether Alanube now holds what we expect.
 */
final class AlanubeWebhookRegistration
{
    public const string SKIPPED = 'skipped';
    public const string UNCHANGED = 'unchanged';
    public const string UPDATED = 'updated';

    /**
     * @param array<string, string> $receiverUrls event => receiver URL
     * @param string[] $mismatches
     */
    public function __construct(
        public readonly string $outcome,
        public readonly bool $verified,
        public readonly array $receiverUrls,
        public readonly array $mismatches = [],
        public readonly ?string $reason = null,
    ) {
    }
}
