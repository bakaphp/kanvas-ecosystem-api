<?php

declare(strict_types=1);

namespace Baka\Companies\Contracts;

/**
 * EnumsInterface.
 */
interface CompanyInterface
{
    public function getId() : int;
    public function getUuid() : string;
}
