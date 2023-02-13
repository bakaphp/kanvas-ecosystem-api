<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface CompanyInterface
{
    public function getId(): mixed;

    public function getUuid(): string;
}
