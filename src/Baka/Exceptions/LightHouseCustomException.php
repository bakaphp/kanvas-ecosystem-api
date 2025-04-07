<?php

declare(strict_types=1);

namespace Baka\Exceptions;

use Exception;
use GraphQL\Error\ClientAware;
use GraphQL\Error\ProvidesExtensions;

class LightHouseCustomException extends Exception implements ClientAware, ProvidesExtensions
{
    protected int|string|null $reason = null;

    public function __construct(string $message, string|int|null $reason = null)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    /**
     * Returns true when exception message is safe to be displayed to a client.
     */
    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * Returns string describing a category of the error.
     *
     * Value "graphql" is reserved for errors produced by query parsing or validation, do not use it.
     */
    public function getCategory(): string
    {
        return 'external';
    }

    /**
     * Return the content that is put in the "extensions" part
     * of the returned error.
     */
    public function getExtensions(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
