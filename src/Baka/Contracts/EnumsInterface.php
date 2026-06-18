<?php

declare(strict_types=1);

namespace Baka\Contracts;

/**
 * EnumsInterface.
 */
interface EnumsInterface
{
    /**
     * Get Enum case value.
     */
    public function getValue(): mixed;
}
