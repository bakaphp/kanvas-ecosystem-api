<?php

declare(strict_types=1);

namespace Baka\Contracts;

interface AppInterface extends HashTableInterface
{
    public function getId(): mixed;

    public function getUuid(): string;

    public function getAppCompany(): CompanyInterface;
}
