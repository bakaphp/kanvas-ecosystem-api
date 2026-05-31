<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use NeuronAI\Tools\PropertyType as ToolsPropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;
use Yasumi\Yasumi;

#[AgentTool(name: 'Company Is Holiday')]
class CompanyIsHolidayTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct(
    ) {
        parent::__construct(
            name: 'check_company_holiday',
            description: 'Check if today is a US federal holiday or a company-observed holiday.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: ToolsPropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;
        $today = Carbon::today();
        $companyObservedHolidays = $lead->company->get(ConfigurationEnum::WORKING_HOLIDAY_DAYS->value) ?? [];
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

        if ($lead->company->get('holiday_epiphany')) {
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
