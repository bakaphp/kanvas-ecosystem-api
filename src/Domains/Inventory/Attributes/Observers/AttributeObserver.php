<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Attributes\Models\Attributes;

class AttributeObserver
{
    public function deleting(Attributes $attribute): void
    {
        if ($attribute->hasDependencies()) {
            throw new ValidationException('Can\'t delete, Attribute has products or variants associated');
        }
    }
}