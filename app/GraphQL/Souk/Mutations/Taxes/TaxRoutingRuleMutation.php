<?php

declare(strict_types=1);

namespace App\GraphQL\Souk\Mutations\Taxes;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\Souk\Taxes\Actions\CreateTaxRoutingRuleAction;
use Kanvas\Souk\Taxes\Actions\UpdateTaxRoutingRuleAction;
use Kanvas\Souk\Taxes\Models\TaxRoutingRule;

class TaxRoutingRuleMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): TaxRoutingRule
    {
        $ctx = $this->actingContext();

        return new CreateTaxRoutingRuleAction(
            $ctx->app,
            $request['input'],
        )->execute();
    }

    public function update(mixed $rootValue, array $request): TaxRoutingRule
    {
        $ctx = $this->actingContext();

        /** @var TaxRoutingRule $rule */
        $rule = TaxRoutingRule::getById((int) $request['id'], $ctx->app);

        return new UpdateTaxRoutingRuleAction(
            $rule,
            $request['input'],
        )->execute();
    }

    public function delete(mixed $rootValue, array $request): bool
    {
        $ctx = $this->actingContext();

        /** @var TaxRoutingRule $rule */
        $rule = TaxRoutingRule::getById((int) $request['id'], $ctx->app);
        $rule->delete();

        return true;
    }
}
