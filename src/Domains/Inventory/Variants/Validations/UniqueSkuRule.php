<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Variants\Validations;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Inventory\Variants\Models\Variants;

class UniqueSkuRule implements ValidationRule
{
    public function __construct(
        protected ?Variants $variant = null
    ) {

    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = Variants::where('sku', $value)
            ->fromCompany($this->variant->company)
            ->fromApp($this->variant->app);

        if ($this->variant) {
            $query->where('id', '!=', $this->variant->getId());
        }

        if ($query->exists()) {
            $fail("The $attribute has already been taken.");
        }
    }
}
