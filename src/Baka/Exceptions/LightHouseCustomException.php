<?php

declare(strict_types=1);

namespace Baka\Exceptions;

use Exception;
use Nuwave\Lighthouse\Exceptions\RendersErrorsExtensions;

class LightHouseCustomException extends Exception implements RendersErrorsExtensions
{
    /**
     * @var @string
     */
    protected $reason;

    public function __construct(string $message, ?string $reason = null)
    {
        parent::__construct($message);

        $this->reason = $reason;
    }

    /**
     * Returns true when exception message is safe to be displayed to a client.
     *
     * @return bool
     */
    public function isClientSafe(): bool
    {
        return true;
    }

    /**
     * Returns string describing a category of the error.
     *
     * Value "graphql" is reserved for errors produced by query parsing or validation, do not use it.
     *
     * @return string
     */
    public function getCategory(): string
    {
        return 'external';
    }

    /**
     * Return the content that is put in the "extensions" part
     * of the returned error.
     *
     * @return array
     */
    public function extensionsContent(): array
    {
        return [
            'reason' => $this->reason,
        ];
    }
}
