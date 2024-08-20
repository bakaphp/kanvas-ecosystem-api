<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Support\Validations;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Kanvas\Inventory\Models\BaseModel;

class UniqueSlugRule implements ValidationRule
{
    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected ?BaseModel $model = null
    ) {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $query = $this->model::where('slug', $value)
            ->fromCompany($this->company)
            ->fromApp($this->app);

        if ($this->model) {
            $query->where('id', '!=', $this->model->getId());
        }

        if ($query->exists()) {
            $fail("The $attribute has already been taken.");
        }
    }
}
