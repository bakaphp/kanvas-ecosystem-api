<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * Carries only what Alanube answered — never the request body, which can hold a DGII certificate.
 */
class AlanubeRequestException extends ValidationException
{
    /**
     * @param string[] $messages
     */
    public function __construct(
        public readonly int $status,
        public readonly array $messages,
        public readonly ?string $alanubeCode = null,
    ) {
        parent::__construct(
            'Alanube answered HTTP ' . $status
            . ($alanubeCode !== null ? " ({$alanubeCode})" : '')
            . ': ' . (implode('; ', $messages) ?: 'no message')
        );
    }
}
