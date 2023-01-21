<?php

declare(strict_types=1);

namespace Baka\Contracts;

/**
 * EnumsInterface.
 */
interface CompanyInterface
{
    public function getId() : mixed;
    public function getUuid() : string;
}
