<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Arr;
use Kanvas\Souk\Taxes\Models\TaxCategory;

class UpdateTaxCategoryAction
{
    private const array FIELDS = [
        'name',
        'description',
    ];

    public function __construct(
        private readonly TaxCategory $category,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxCategory
    {
        $this->category->fill(Arr::only($this->data, self::FIELDS));
        $this->category->saveOrFail();

        return $this->category;
    }
}
