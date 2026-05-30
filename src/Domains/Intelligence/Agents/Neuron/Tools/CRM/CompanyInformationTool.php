<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Yasumi\Yasumi;

#[AgentTool(name: 'Company Information')]
class CompanyInformationTool extends Tool
{
    public function __construct()
    {
        parent::__construct(
            name: 'get_company_information',
            description: 'Get the full company profile (name, contact details, address, timezone, language, photo) '
                . 'together with the work schedule: weekly hours, working days, observed holidays, '
                . 'current open/closed status, next open time, and the holiday calendar for the current year '
                . '(federal holidays + whether the company stays open on each).',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'companies_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the company to retrieve information for.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $companies_id): array
    {
        $company = Companies::getById($companies_id);

        $workSchedule = new CompanyWorkHoursTool()->__invoke($companies_id);
        $observedHolidays = $company->get(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value) ?? [];

        $tz = $company->timezone ?? 'UTC';
        $today = Carbon::today($tz);
        $holidaysCalendar = $this->buildHolidaysCalendar(
            $today,
            $observedHolidays,
            (bool) $company->get('holiday_epiphany')
        );
        $todayHoliday = $this->getTodayHoliday($today, $holidaysCalendar);

        return [
            'id' => $company->id,
            'uuid' => $company->uuid,
            'name' => $company->name,
            'email' => $company->email,
            'phone' => $company->phone,
            'website' => $company->website,
            'address' => $company->address,
            'address_2' => $company->address_2,
            'city' => $company->city,
            'state' => $company->state,
            'country' => $company->country,
            'zip' => $company->zip,
            'zipcode' => $company->zipcode,
            'country_code' => $company->country_code,
            'timezone' => $company->timezone,
            'language' => $company->language,
            'is_active' => (bool) $company->is_active,
            'photo' => $company->getPhoto()?->url,
            'work_schedule' => [
                'status' => $workSchedule['status'],
                'weekday' => $workSchedule['weekday'],
                'opens_at_local' => $workSchedule['opens_at_local'],
                'closes_at_local' => $workSchedule['closes_at_local'],
                'next_open_iso' => $workSchedule['next_open_iso'],
                'next_open_human' => $workSchedule['next_open_human'],
                'current_time' => $workSchedule['current_time'],
                'company_timezone' => $workSchedule['company_timezone'],
                'weekly_hours' => $company->get(ConfigurationEnum::WORKING_HOURS->value) ?? null,
                'working_days' => $company->get(ConfigurationEnum::WORKING_DAYS->value) ?? [],
                'observed_holidays' => $observedHolidays,
            ],
            'holidays' => [
                'today' => $todayHoliday,
                'calendar' => $holidaysCalendar,
            ],
        ];
    }

    protected function buildHolidaysCalendar(Carbon $reference, array $observedHolidays, bool $observesEpiphany): array
    {
        $usHolidays = Yasumi::create('USA', $reference->year);
        $calendar = [];

        foreach ($usHolidays as $holiday) {
            $name = $holiday->getName();
            $calendar[] = [
                'date' => $holiday->format('Y-m-d'),
                'name' => $name,
                'company_observes_as_working_day' => in_array($name, $observedHolidays, true),
            ];
        }

        $calendar[] = [
            'date' => sprintf('%d-12-24', $reference->year),
            'name' => 'Christmas Eve',
            'company_observes_as_working_day' => in_array('Christmas Eve', $observedHolidays, true),
        ];

        if ($observesEpiphany) {
            $calendar[] = [
                'date' => sprintf('%d-01-06', $reference->year),
                'name' => 'Epiphany',
                'company_observes_as_working_day' => in_array('Epiphany', $observedHolidays, true),
            ];
        }

        usort($calendar, fn (array $a, array $b) => strcmp($a['date'], $b['date']));

        return $calendar;
    }

    protected function getTodayHoliday(Carbon $today, array $calendar): array
    {
        $todayDate = $today->toDateString();

        foreach ($calendar as $entry) {
            if ($entry['date'] === $todayDate) {
                return [
                    'is_holiday' => true,
                    'name' => $entry['name'],
                    'company_open' => $entry['company_observes_as_working_day'],
                    'date' => $todayDate,
                ];
            }
        }

        return [
            'is_holiday' => false,
            'name' => null,
            'company_open' => null,
            'date' => $todayDate,
        ];
    }
}
