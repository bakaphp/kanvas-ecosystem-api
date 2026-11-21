<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Support\Carbon;
use Kanvas\Companies\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Tools\CompanyWorkHoursTool;
use Kanvas\Intelligence\Tools\Traits\ReportsToolOutcome;
use Kanvas\Users\Enums\UserDayOffTypeEnum;
use Kanvas\Users\Models\Users;
use Kanvas\Users\Services\UserDayOffService;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Lead Owner Availability', category: 'crm')]
class LeadOwnerAvailabilityTool extends Tool
{
    use ReportsToolOutcome;
    use ResolvesLeadForTool;
    use TrackByInputs;

    public const string NAME = 'get_lead_owner_availability';

    public const string STATUS_WORKING = 'working';

    public const string STATUS_AFTER_HOURS = 'after_hours';

    public const string STATUS_DAY_OFF = 'day_off';

    public const string STATUS_VACATION = 'vacation';

    public const string STATUS_NO_OWNER = 'no_owner';

    public const string STATUS_UNKNOWN = 'unknown';

    protected string $name = self::NAME;

    protected ?string $description = 'Tells you whether the lead owner (the salesperson assigned to this lead) is working right now, '
        . 'outside business hours, on a day off, or on vacation, and the date they are back. '
        . 'Call it before you tell the customer anything about the salesperson\'s availability, '
        . 'and whenever the customer asks for the salesperson or asks to talk to a person. '
        . 'Never guess the availability or a return date: use only what this tool returns. '
        . 'This is an internal operation: never expose the tool call or its status codes to the customer.';

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadInReadScopeOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $owner = $lead->owner;
        if ($owner === null) {
            return $this->noop(
                ['status' => self::STATUS_NO_OWNER],
                guidance: 'No salesperson is assigned to this lead yet. Speak as the dealership\'s assistant and do not name a salesperson.',
            );
        }

        try {
            return $this->availability($lead, $owner);
        } catch (Throwable $e) {
            report($e);

            return $this->failed(
                'The salesperson\'s availability could not be checked.',
                guidance: 'Do not state whether the salesperson is working or when they are back.',
            );
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function availability(Lead $lead, Users $owner): array
    {
        $company = $lead->company;
        $now = Carbon::now($company->getTimezone() ?? 'UTC');
        $dayOffs = new UserDayOffService($owner, $company, $lead->app);

        $hoursConfigured = ! empty($company->get(ConfigurationEnum::WORKING_HOURS->value));
        $workHours = $hoursConfigured ? new CompanyWorkHoursTool($company) : null;
        $companyIsOpen = $workHours !== null
            ? fn (CarbonInterface $date): bool => $workHours->isOpenOn(Carbon::instance($date))
            : null;

        $payload = [
            'owner_name' => trim((string) $owner->firstname),
            'current_time' => $now->format('Y-m-d H:i:s'),
        ];
        $tomorrow = $now->copy()->addDay();

        $dayOff = $dayOffs->getForDate($now);
        if ($dayOff !== null) {
            $onVacation = $dayOff->type === UserDayOffTypeEnum::VACATION;

            return $this->ok(
                [
                    ...$payload,
                    'status' => $onVacation ? self::STATUS_VACATION : self::STATUS_DAY_OFF,
                    ...$this->returnDate($dayOffs->getNextWorkingDate($tomorrow, $companyIsOpen)),
                ],
                guidance: $onVacation
                    ? 'The salesperson is on vacation. If the customer asks for them, say so and give "returns_on_human" when present.'
                    : 'The salesperson is off today. If the customer asks for them, say they are off today and give "returns_on_human" when present.',
            );
        }

        if ($workHours === null) {
            return $this->noop(
                [...$payload, 'status' => self::STATUS_UNKNOWN],
                guidance: 'The dealership has no business hours configured, so it is unknown whether the salesperson is at work right now. Do not state that they are.',
            );
        }

        $hours = $workHours->execute();

        if (($hours['status'] ?? null) === 'work_hours') {
            return $this->ok(
                [...$payload, 'status' => self::STATUS_WORKING],
                guidance: 'The salesperson is working today but is not the one replying right now. You are their assistant and can help the customer in the meantime.',
            );
        }

        return $this->ok(
            [
                ...$payload,
                'status' => self::STATUS_AFTER_HOURS,
                ...$this->returnDate($dayOffs->getNextWorkingDate(
                    $this->opensLaterToday($now, $hours, $companyIsOpen) ? $now : $tomorrow,
                    $companyIsOpen
                )),
            ],
            guidance: 'The dealership is closed right now, so the salesperson is not working. Give "returns_on_human" when present if the customer asks when they are back.',
        );
    }

    /**
     * @param array<string, mixed> $hours
     * @param Closure(CarbonInterface): bool $companyIsOpen
     */
    private function opensLaterToday(Carbon $now, array $hours, Closure $companyIsOpen): bool
    {
        $opensAt = (string) ($hours['opens_at_local'] ?? '');

        return $opensAt !== '' && $companyIsOpen($now) && $now->format('H:i') < $opensAt;
    }

    /**
     * @return array{returns_on?: string, returns_on_human?: string}
     */
    private function returnDate(?CarbonInterface $date): array
    {
        if ($date === null) {
            return [];
        }

        return [
            'returns_on' => $date->toDateString(),
            'returns_on_human' => $date->format('l, F j'),
        ];
    }
}
