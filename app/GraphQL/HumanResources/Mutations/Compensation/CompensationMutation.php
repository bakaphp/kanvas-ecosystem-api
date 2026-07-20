<?php

declare(strict_types=1);

namespace App\GraphQL\HumanResources\Mutations\Compensation;

use DateTimeInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\HumanResources\Compensation\Actions\RecordCompensationAction;
use Kanvas\HumanResources\Compensation\DataTransferObject\EmployeeCompensation as EmployeeCompensationData;
use Kanvas\HumanResources\Compensation\Models\EmployeeCompensation;
use Kanvas\HumanResources\Compensation\Models\PayBand;
use Kanvas\HumanResources\Employees\Models\Employee;

class CompensationMutation
{
    public function record(mixed $rootValue, array $request): EmployeeCompensation
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();
        $input = $request['input'];

        /** @var Employee $employee */
        $employee = Employee::getByIdFromCompanyApp((int) $input['employee_id'], $company, $app);

        /** @var PayBand|null $payBand */
        $payBand = isset($input['pay_band_id'])
            ? PayBand::getByIdFromCompanyApp((int) $input['pay_band_id'], $company, $app)
            : null;

        return new RecordCompensationAction(
            new EmployeeCompensationData(
                app: $app,
                company: $company,
                user: $user,
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

    private function normalizeDate(mixed $value): string
    {
        return $value instanceof DateTimeInterface ? $value->format('Y-m-d') : (string) $value;
    }
}
