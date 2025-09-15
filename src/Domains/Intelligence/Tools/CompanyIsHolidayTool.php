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
        $isFederalHoliday = $usHolidays->isHoliday($today->toDateTimeImmutable());

        if (! $isFederalHoliday) {
            return [
                'is_holiday' => false,
                'is_a_working_day' => true,
                'holiday_info' => null,
                'date_checked' => $today->toDateString(),
            ];
        }

        // Get the federal holiday name
        $federalHolidayName = $usHolidays->getHoliday($today->toDateTimeImmutable())->getName();

        // Check if this federal holiday is in the company's observed holidays list
        $isCompanyObservedHoliday = in_array($federalHolidayName, $companyObservedHolidays, true);

        return [
            'is_holiday' => $isCompanyObservedHoliday,
            'is_a_working_day' => ! $isCompanyObservedHoliday,
            'holiday_info' => [
                'federal_holiday' => $federalHolidayName,
                'company_observes' => $isCompanyObservedHoliday,
            ],
            'date_checked' => $today->toDateString(),
        ];
    }
}
