<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Taxes\Models\TaxCategory;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class UpdateTaxRoutingRuleAction
{
    private const array FIELDS = [
        'name',
        'provider',
        'condition_country',
        'condition_state',
        'category_codes',
        'rate',
        'tax_shipping',
        'priority',
        'is_active',
    ];

    public function __construct(
        private readonly TaxRoutingRule $rule,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxRoutingRule
    {
        if (array_key_exists('category_codes', $this->data)) {
            TaxCategory::assertCodesExist($this->rule->app, $this->data['category_codes']);
        }

        return DB::connection('commerce')->transaction(function () {
            $this->rule->fill(Arr::only($this->data, self::FIELDS));
            $this->rule->saveOrFail();

            return $this->rule;
        });
    }
}
