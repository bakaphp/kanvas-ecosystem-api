<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Tools;

use Illuminate\Database\Eloquent\Model;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Yasumi\Yasumi;

class HolidaysMonthTool
{
    public function __construct(protected Model $entity)
    {
    }

    public function execute(array $params = []): ?array
    {
        $yasumi = Yasumi::create('USA', (int) date('Y'));
        $holidays = [];
        foreach ($yasumi->getHolidayNames() as $name) {
            $day = $yasumi->getHoliday($name);
            $holidays[] = [
                'is_holiday' => true,
                'is_a_working_day' => true,
                'type' => $day->getType(),
                'holiday_info' => $name,
                'date_checked' => $day->format('Y-m-d'),
            ];
        }
        $companyObservedHolidays = $this->entity->company->get(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value) ?? [];
        foreach ($companyObservedHolidays as $name) {
            $day = $yasumi->getHoliday($name);

            $holidays[] = [
                'is_holiday' => true,
                'is_a_working_day' => true,
                'type' => 'Custom By Company',
                'holiday_info' => $name,
                'date_checked' => $day->format('Y-m-d')
            ];
        }

        return $holidays;
    }
}
