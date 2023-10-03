<?php

declare(strict_types=1);

namespace Baka\Contracts;

use Kanvas\CustomFields\Models\AppsCustomFields;

interface CustomFieldInterface
{
    public function get(string $name): mixed;

    public function del(string $name): bool;

    public function set(string $name, mixed $value): AppsCustomFields;

    public function getAll(): array;
}
