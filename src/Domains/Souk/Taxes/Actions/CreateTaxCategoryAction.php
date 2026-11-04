<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Taxes\Models\TaxCategory;

class CreateTaxCategoryAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxCategory
    {
        $code = TaxCategory::normalizeCode((string) $this->data['code']);

        if ($code === '' || strlen($code) > TaxCategory::CODE_MAX_LENGTH) {
            throw new ValidationException('Tax category code must have between 1 and ' . TaxCategory::CODE_MAX_LENGTH . ' characters');
        }

        if (TaxCategory::findByCode($this->app, $code) !== null) {
            throw new ValidationException("Tax category {$code} already exists");
        }

        $category = new TaxCategory();
        $category->apps_id = $this->app->getId();
        $category->code = $code;
        $category->name = $this->data['name'];
        $category->description = $this->data['description'] ?? null;
        $category->saveOrFail();

        return $category;
    }
}
