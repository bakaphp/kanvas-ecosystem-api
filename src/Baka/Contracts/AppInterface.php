<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface AppInterface extends CustomFieldInterface
{
    public function getId(): mixed;

    public function getUuid(): string;
}
