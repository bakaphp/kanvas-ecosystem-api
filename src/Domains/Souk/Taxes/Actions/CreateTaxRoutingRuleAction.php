<?php

declare(strict_types=1);

namespace Kanvas\Souk\Taxes\Actions;

use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class CreateTaxRoutingRuleAction
{
    public function __construct(
        private readonly Apps $app,
        private readonly array $data,
    ) {
    }

    public function execute(): TaxRoutingRule
    {
        return DB::connection('commerce')->transaction(function () {
            $rule = new TaxRoutingRule();
            $rule->apps_id = $this->app->getId();
            $rule->name = $this->data['name'];
            $rule->provider = $this->data['provider'];
            $rule->condition_country = $this->data['condition_country'] ?? null;
            $rule->condition_state = $this->data['condition_state'] ?? null;
            $rule->category_codes = $this->data['category_codes'] ?? null;
            $rule->priority = $this->data['priority'] ?? 0;
            $rule->is_active = $this->data['is_active'] ?? true;
            $rule->saveOrFail();

            return $rule;
        });
    }
}
