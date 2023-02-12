<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface AppInterface
{
    public function getId(): mixed;

    public function getUuid(): string;
}
