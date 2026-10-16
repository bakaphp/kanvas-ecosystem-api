<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Observers;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Inventory\Attributes\Jobs\ReconcileTypesenseAttributeSchemaJob;
use Kanvas\Inventory\Attributes\Models\Attributes;

class AttributeObserver
{
    public function saved(Attributes $attribute): void
    {
        //$attribute->clearLightHouseCache(withKanvasConfiguration: false);
        $attribute->variantAttributes->first()?->variant?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);
        $attribute->productsAttributes->first()?->product?->clearLightHouseCache(withKanvasConfiguration: false, cleanGlobalKey: true);

        if ($attribute->isDirty('is_filtrable')) {
            ReconcileTypesenseAttributeSchemaJob::dispatch($attribute->app);
        }
    }

    public function deleting(Attributes $attribute): void
    {
        if ($attribute->hasDependencies()) {
            throw new ValidationException('Can\'t delete, Attribute has associated items');
        }
    }
}
