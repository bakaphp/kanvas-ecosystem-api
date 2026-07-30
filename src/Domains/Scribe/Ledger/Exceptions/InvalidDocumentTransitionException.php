<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Ledger\Exceptions;

use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;
use Override;
use RuntimeException;

class InvalidDocumentTransitionException extends RuntimeException implements ClientAware, ProvidesExtensions
{
    #[Override]
    public function isClientSafe(): bool
    {
        return true;
    }

    public function getCategory(): string
    {
        return 'external';
    }

    #[Override]
    public function getExtensions(): array
    {
        return [
            'category' => 'invalid_document_transition',
        ];
    }
}
