<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Exceptions;

use RuntimeException;

/**
 * A hard constraint the caller sent but that can't be honoured — an unknown
 * field, an unconvertible unit, min above max. Dropping it silently would remove
 * the limit before the database recheck ever saw it, so it comes back to the
 * caller with enough to correct its own call: the agent retries in the same turn.
 */
final class InvalidDiscoveryInputException extends RuntimeException
{
    /**
     * @param list<string> $allowed
     */
    public function __construct(
        public readonly string $field,
        public readonly string $reason,
        public readonly array $allowed = [],
    ) {
        parent::__construct(sprintf('Invalid discovery filter "%s": %s', $field, $reason));
    }

    /**
     * @return array{error: string, field: string, reason: string, allowed: list<string>}
     */
    public function toArray(): array
    {
        return [
            'error' => 'invalid_filter',
            'field' => $this->field,
            'reason' => $this->reason,
            'allowed' => $this->allowed,
        ];
    }
}
