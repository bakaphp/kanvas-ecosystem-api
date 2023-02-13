<?php

declare(strict_types=1);

namespace Kanvas\CustomFields\Contracts;

interface CustomFieldModelInterface
{
    public function saveCustomFields(): bool;

    public function deleteAllCustomFields(): bool;
}
