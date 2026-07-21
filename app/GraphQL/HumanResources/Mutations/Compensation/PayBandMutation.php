<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Compensation;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Compensation\Actions\CreatePayBandAction;
use Kanvas\HumanResources\Compensation\Actions\UpdatePayBandAction;
use Kanvas\HumanResources\Compensation\DataTransferObject\PayBand as PayBandData;
use Kanvas\HumanResources\Compensation\Models\PayBand;
use Kanvas\HumanResources\Positions\Models\Position;

class PayBandMutation
{
    use ResolvesActingContext;

    public function create(mixed $rootValue, array $request): PayBand
    {
        $context = $this->actingContext();
        $input = $request['input'];

        /** @var Position|null $position */
        $position = isset($input['position_id'])
            ? Position::getByIdFromCompanyApp((int) $input['position_id'], $context->company, $context->app)
            : null;

        return new CreatePayBandAction(
            new PayBandData(
                app: $context->app,
                company: $context->company,
                user: $context->user,
                minAmount: (float) $input['min_amount'],
                maxAmount: (float) $input['max_amount'],
                effectiveFrom: $this->normalizeDate($input['effective_from']),
                position: $position,
                name: $input['name'] ?? null,
                level: $input['level'] ?? null,
                currency: $input['currency'] ?? 'USD',
                payFrequency: $input['pay_frequency'] ?? 'annual',
                midAmount: isset($input['mid_amount']) ? (float) $input['mid_amount'] : null,
            ),
        )->execute();
    }

    public function update(mixed $rootValue, array $request): PayBand
    {
        $context = $this->actingContext();
        $input = $request['input'];

        /** @var PayBand $band */
        $band = PayBand::getByIdFromCompanyApp((int) $request['id'], $context->company, $context->app);

        $position = isset($input['position_id'])
            ? Position::getByIdFromCompanyApp((int) $input['position_id'], $context->company, $context->app)
            : $band->position;

        return new UpdatePayBandAction(
            $band,
            new PayBandData(
                app: $context->app,
                company: $context->company,
                user: $context->user,
                minAmount: isset($input['min_amount']) ? (float) $input['min_amount'] : $band->min_amount,
                maxAmount: isset($input['max_amount']) ? (float) $input['max_amount'] : $band->max_amount,
                effectiveFrom: isset($input['effective_from']) ? $this->normalizeDate($input['effective_from']) : $band->effective_from->toDateString(),
                position: $position,
                name: $input['name'] ?? $band->name,
                level: $input['level'] ?? $band->level,
                currency: $input['currency'] ?? $band->currency,
                payFrequency: $input['pay_frequency'] ?? $band->pay_frequency,
                midAmount: isset($input['mid_amount']) ? (float) $input['mid_amount'] : $band->mid_amount,
            ),
        )->execute();
    }
}
