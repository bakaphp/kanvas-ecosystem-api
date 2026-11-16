<?php

declare(strict_types=1);

namespace Kanvas\Scribe\ElectronicInvoicing\Exceptions;

use Kanvas\Exceptions\ValidationException;

/**
 * The provider refused the document before it reached the authority: nothing was consumed, so the same fiscal
 * row is fixed and sent again rather than burned.
 */
class ProviderRejectedException extends ValidationException
{
    /**
     * @param array<array-key, mixed>|null $rawResponse what the provider answered, kept as the attempt's
     *        response artifact: a refusal with no message is otherwise untraceable
     */
    public function __construct(string $message, public readonly ?array $rawResponse = null)
    {
        parent::__construct($message);
    }
}
