<?php

declare(strict_types=1);

namespace Kanvas\Exceptions;

use Baka\Exceptions\LightHouseCustomException;

class InternalServerErrorException extends LightHouseCustomException
{
    /**
     * Returns string describing a category of the error.
     *
     * Value "graphql" is reserved for errors produced by query parsing or validation, do not use it.
     *
     * @return string
     */
    public function getCategory() : string
    {
        return 'internal';
    }
}
