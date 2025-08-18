<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Validations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Companies\Enums\B2BSettingsEnums;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Regions\Models\Regions as ModelsRegions;
use Kanvas\Souk\Orders\Models\Order;

class UniqueOrderNumber implements ValidationRule
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions|ModelsRegions $region
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Order::where('order_number', $value)
            ->fromApp($this->app);

        // Check if B2B mode is enabled (app-wise numbering only)
        $isB2BMode = $this->app->get(B2BSettingsEnums::B2B_APP_WISE_ORDER_NUMBERING->getValue()) === '1';

        if (! $isB2BMode) {
            // Default mode: check uniqueness within company and region
            $query->where('region_id', $this->region->getId())
                ->fromCompany($this->company);
        }

        if ($query->exists()) {
            $fail("The $attribute has already been taken.");
        }
    }
}
