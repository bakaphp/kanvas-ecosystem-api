<?php

declare(strict_types=1);

namespace Kanvas\Contracts;

/**
 * EnumsInterface
 */
interface EnumsInterface
{
    /**
     * Get Enum case value
     *
     * @return mixed
     */
    public function getValue(): mixed;
}
