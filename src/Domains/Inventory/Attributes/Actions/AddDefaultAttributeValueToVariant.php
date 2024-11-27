<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Attributes\Actions;

use Kanvas\Enums\AppEnums;
use Kanvas\Inventory\Attributes\Models\Attributes;
use Kanvas\Inventory\Variants\Models\Variants as ModelsVariants;

class AddDefaultAttributeValueToVariant
{
    public function __construct(
        public ModelsVariants $variant
    ) {
    }

    public function execute()
    {
        $variantAttribute = $this->variant->attributes()->pluck('id')->toArray();
        $attributes = Attributes::where('apps_id', $this->variant->apps_id)
                        ->where('companies_id', AppEnums::GLOBAL_COMPANY_ID->getValue())
                        ->where('users_id', AppEnums::GLOBAL_USER_ID->getValue())
                        ->whereNotIn('id', $variantAttribute)
                        ->get();
        foreach ($attributes as $attribute) {
            $this->variant->attributes()->attach($attribute->id, ['value' => $attribute->default_value]);
        }
    }
}
