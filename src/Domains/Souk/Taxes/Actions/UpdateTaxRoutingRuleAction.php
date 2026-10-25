<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class UpdateTaxRoutingRuleAction
{
    public function __construct(
        private readonly TaxRoutingRule $rule,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxRoutingRule
    {
        return DB::connection('commerce')->transaction(function () {
            if (array_key_exists('name', $this->data)) {
                $this->rule->name = $this->data['name'];
            }
            if (array_key_exists('provider', $this->data)) {
                $this->rule->provider = $this->data['provider'];
            }
            if (array_key_exists('condition_country', $this->data)) {
                $this->rule->condition_country = $this->data['condition_country'];
            }
            if (array_key_exists('condition_state', $this->data)) {
                $this->rule->condition_state = $this->data['condition_state'];
            }
            if (array_key_exists('category_codes', $this->data)) {
                $this->rule->category_codes = $this->data['category_codes'];
            }
            if (array_key_exists('priority', $this->data)) {
                $this->rule->priority = $this->data['priority'];
            }
            if (array_key_exists('is_active', $this->data)) {
                $this->rule->is_active = $this->data['is_active'];
            }

            $this->rule->saveOrFail();

            return $this->rule;
        });
    }
}
