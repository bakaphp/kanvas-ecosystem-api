<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Intelligence\Contracts\ContextToolInterface;
use Override;
use Yasumi\Yasumi;

class CompanyIsHolidayTool implements ContextToolInterface
{
    public function __construct(
        protected Model $entity
    ) {
    }

    #[Override]
    public function execute(array $params = []): array
    {
        $today = Carbon::today();
        $companyObservedHolidays = $this->entity->company->get(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value) ?? [];
        // Example: ['Memorial Day', 'Independence Day', 'Labor Day', 'Thanksgiving', 'Christmas', 'New Year\'s Day']

        // Get US federal holidays for current year
        $usHolidays = Yasumi::create('USA', $today->year);

        // Check if today is a US federal holiday
        $todayImmutable = $today->toDateTimeImmutable();
        $isFederalHoliday = $usHolidays->isHoliday($todayImmutable);

        // Get the federal holiday name by iterating through holidays
        $federalHolidayName = null;
        foreach ($usHolidays as $holiday) {
            if ($holiday->format('Y-m-d') === $today->format('Y-m-d')) {
                $federalHolidayName = $holiday->getName();

                break;
            }
        }

        if ($today->format('m-d') === '12-24') {
            $federalHolidayName = 'Christmas Eve';
        }

        if ($this->entity->company->get('holiday_epiphany')) {
            if ($today->format('m-d') === '01-06') {
                $federalHolidayName = 'Epiphany';
            }
        }

        $isCompanyObservedHoliday = in_array($federalHolidayName, $companyObservedHolidays, true);

        return [
            'is_holiday' => $federalHolidayName !== null,
            'is_a_working_day' => $isCompanyObservedHoliday,
            'holiday_info' => $federalHolidayName ? [
                'federal_holiday' => $federalHolidayName,
                'company_observes' => $isCompanyObservedHoliday,
            ] : null,
            'date_checked' => $today->toDateString(),
        ];

        /*     // Fallback if no holiday name found (shouldn't happen if isHoliday returned true)
            if ($federalHolidayName === null) {
                return [
                    'is_holiday' => false,
                    'is_a_working_day' => true,
                    'holiday_info' => null,
                    'date_checked' => $today->toDateString(),
                ];
            }

            if (! $isFederalHoliday) {
                return [
                    'is_holiday' => false,
                    'is_a_working_day' => true,
                    'holiday_info' => null,
                    'date_checked' => $today->toDateString(),
                ];
            } */
    }
}
