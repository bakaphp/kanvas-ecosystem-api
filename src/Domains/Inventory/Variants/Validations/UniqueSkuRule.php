<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Validations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Inventory\Variants\Models\Variants;

class UniqueSkuRule implements ValidationRule
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?Variants $variant = null
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Variants::where('sku', $value)
            ->fromCompany($this->company)
            ->fromApp($this->app);

        if ($this->variant) {
            $query->where('id', '!=', $this->variant->getId());
        }

        if ($query->exists()) {
            $fail("The $attribute has already been taken.");
        }
    }
}
