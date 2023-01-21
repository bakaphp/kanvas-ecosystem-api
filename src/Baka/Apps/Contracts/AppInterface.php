<?php

declare(strict_types=1);

namespace Baka\Apps\Contracts;

/**
 * EnumsInterface.
 */
interface AppInterface
{
    public function getId() : mixed;
    public function getUuid() : string;
}
