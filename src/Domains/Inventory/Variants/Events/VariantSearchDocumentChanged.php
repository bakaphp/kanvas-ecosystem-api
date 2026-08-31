<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Kanvas\Inventory\Variants\Models\Variants;

final class VariantSearchDocumentChanged
{
    use Dispatchable;

    public function __construct(public readonly int $variantId, public readonly int $appId, public readonly int $companyId)
    {
    }

    public static function dispatchFor(Variants $variant): void
    {
        self::dispatch((int) $variant->getId(), (int) $variant->apps_id, (int) $variant->companies_id);
    }
}
