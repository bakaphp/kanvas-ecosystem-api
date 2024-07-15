<?php

declare(strict_types=1);

namespace Kanvas\Souk\Orders\Validations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Inventory\Regions\Models\Regions;
use Kanvas\Souk\Orders\Models\Order;

class UniqueOrderNumber implements ValidationRule
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected Regions $region
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Order::where('order_number', $value)
            ->where('region_id', $this->region->getId())
            ->fromCompany($this->company)
            ->fromApp($this->app);

        if ($query->exists()) {
            $fail("The $attribute has already been taken.");
        }
    }
}
