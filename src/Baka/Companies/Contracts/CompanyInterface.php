<?php

declare(strict_types=1);

namespace Baka\Companies\Contracts;

/**
 * EnumsInterface.
 */
interface CompanyInterface
{
    public function getId() : mixed;
    public function getUuid() : string;
}
