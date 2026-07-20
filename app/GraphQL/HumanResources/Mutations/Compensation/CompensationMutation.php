<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Compensation;

use App\GraphQL\Concerns\ResolvesActingContext;
use Kanvas\HumanResources\Compensation\Actions\RecordCompensationAction;
use Kanvas\HumanResources\Compensation\DataTransferObject\EmployeeCompensation as EmployeeCompensationData;
use Kanvas\HumanResources\Compensation\Models\EmployeeCompensation;
use Kanvas\HumanResources\Compensation\Models\PayBand;
use Kanvas\HumanResources\Employees\Models\Employee;

class CompensationMutation
{
    use ResolvesActingContext;

    public function record(mixed $rootValue, array $request): EmployeeCompensation
    {
        $context = $this->actingContext();
        $input = $request['input'];

        /** @var Employee $employee */
        $employee = Employee::getByIdFromCompanyApp((int) $input['employee_id'], $context->company, $context->app);

        /** @var PayBand|null $payBand */
        $payBand = isset($input['pay_band_id'])
            ? PayBand::getByIdFromCompanyApp((int) $input['pay_band_id'], $context->company, $context->app)
            : null;

        return new RecordCompensationAction(
            new EmployeeCompensationData(
                app: $context->app,
                company: $context->company,
                user: $context->user,
                employee: $employee,
                amount: (float) $input['amount'],
                effectiveFrom: $this->normalizeDate($input['effective_from']),
                currency: $input['currency'] ?? 'USD',
                payFrequency: $input['pay_frequency'] ?? 'annual',
                payBand: $payBand,
                changeReason: $input['change_reason'] ?? null,
            ),
        )->execute();
    }
}
