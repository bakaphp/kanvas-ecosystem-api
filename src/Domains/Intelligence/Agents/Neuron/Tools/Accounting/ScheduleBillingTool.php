<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBillingScheduleForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCustomerForTool;
use Kanvas\Scribe\BillingSchedules\Actions\CreateBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\Actions\UpdateBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\Enums\BillingCadenceEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/** Creates, updates, pauses or resumes a billing schedule. */
#[AgentTool(name: 'Schedule Billing', category: 'accounting')]
class ScheduleBillingTool extends Tool
{
    use ResolvesBillingScheduleForTool;
    use ResolvesCustomerForTool;
    use TrackByInputs;

    private const array ACTIONS = ['create', 'update', 'pause', 'resume'];

    protected string $name = 'schedule_billing';

    protected ?string $description = 'Sets up billing that repeats (a monthly retainer, hosting, support) or happens once '
        . 'on a date. Each period the schedule creates a DRAFT invoice and sends it for approval; it never issues one '
        . 'by itself. action "create" needs customer_name, lines and cadence; "update" changes a schedule '
        . '(billing_schedule_id) for periods not billed yet; "pause" and "resume" stop and restart it. Only on the '
        . 'user\'s explicit request. A signed SOW schedules its own payments, so do not schedule those again.';

    /**
     * @return array<int, ToolPropertyInterface>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'action',
                type: PropertyType::STRING,
                description: 'One of: create, update, pause, resume.',
                required: true,
            ),
            new ToolProperty(
                name: 'billing_schedule_id',
                type: PropertyType::INTEGER,
                description: 'Required for update, pause and resume (list_billing_schedules returns it).',
                required: false,
            ),
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Required for create. Never guess; ask which customer when it is not clear.',
                required: false,
            ),
            new ArrayProperty(
                name: 'lines',
                description: 'What each invoice bills. Required for create; on update it replaces the lines.',
                required: false,
                items: new ObjectProperty(
                    name: 'line',
                    description: 'One invoice line.',
                    properties: [
                        new ToolProperty(
                            name: 'description',
                            type: PropertyType::STRING,
                            description: 'e.g. "Monthly support retainer".',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'unit_price',
                            type: PropertyType::NUMBER,
                            description: 'Price for one unit, before tax.',
                            required: true
                        ),
                        new ToolProperty(
                            name: 'quantity',
                            type: PropertyType::NUMBER,
                            description: 'Defaults to 1.',
                            required: false
                        ),
                        new ToolProperty(
                            name: 'tax_amount',
                            type: PropertyType::NUMBER,
                            description: 'Tax as an amount. Defaults to 0.',
                            required: false
                        ),
                    ],
                ),
            ),
            new ToolProperty(
                name: 'cadence',
                type: PropertyType::STRING,
                description: 'One of: monthly, quarterly, yearly, once. Required for create.',
                required: false,
            ),
            new ToolProperty(
                name: 'start_date',
                type: PropertyType::STRING,
                description: 'First period, YYYY-MM-DD. Defaults to today.',
                required: false,
            ),
            new ToolProperty(
                name: 'end_date',
                type: PropertyType::STRING,
                description: 'Last period, YYYY-MM-DD. Omit to bill until stopped.',
                required: false,
            ),
            new ToolProperty(
                name: 'anchor_day',
                type: PropertyType::INTEGER,
                description: 'Day of the month to bill on (1-31; short months use their last day). Defaults to the start date\'s day.',
                required: false,
            ),
            new ToolProperty(
                name: 'currency',
                type: PropertyType::STRING,
                description: 'Currency code. Defaults to USD.',
                required: false,
            ),
            new ToolProperty(
                name: 'net_terms_days',
                type: PropertyType::INTEGER,
                description: 'Days after each invoice date until it is due. Defaults to 0.',
                required: false,
            ),
            new ToolProperty(
                name: 'name',
                type: PropertyType::STRING,
                description: 'Short label, e.g. "Acme hosting".',
                required: false,
            ),
        ];
    }

    /**
     * @param array<int, mixed>|null $lines
     *
     * @return array<string, mixed>
     */
    public function __invoke(
        string $action,
        ?int $billing_schedule_id = null,
        ?string $customer_name = null,
        ?array $lines = null,
        ?string $cadence = null,
        ?string $start_date = null,
        ?string $end_date = null,
        ?int $anchor_day = null,
        ?string $currency = null,
        ?int $net_terms_days = null,
        ?string $name = null,
    ): array {
        if (! $this->hasTenantContext()) {
            return ['saved' => false, ...$this->tenantContextMissingError('billing schedule')];
        }

        $action = strtolower(trim($action));

        if (! in_array($action, self::ACTIONS, true)) {
            return [
                'saved' => false,
                'reason' => 'invalid_action',
                'message' => 'action must be one of: ' . implode(', ', self::ACTIONS) . '.',
            ];
        }

        if ($cadence !== null && BillingCadenceEnum::tryFrom(strtolower(trim($cadence))) === null) {
            return [
                'saved' => false,
                'reason' => 'invalid_cadence',
                'message' => 'cadence must be one of: monthly, quarterly, yearly, once.',
            ];
        }

        $input = array_filter([
            'lines' => $lines,
            'cadence' => $cadence !== null ? strtolower(trim($cadence)) : null,
            'start_date' => Str::trimToNull($start_date),
            'end_date' => Str::trimToNull($end_date),
            'anchor_day' => $anchor_day,
            'currency' => Str::trimToNull($currency),
            'net_terms_days' => $net_terms_days,
            'name' => Str::trimToNull($name),
        ], static fn (mixed $value): bool => $value !== null);

        try {
            return $action === 'create'
                ? $this->create($customer_name, $input)
                : $this->change($action, $billing_schedule_id, $input);
        } catch (ValidationException $e) {
            return ['saved' => false, 'reason' => 'invalid_schedule', 'message' => $e->getMessage()];
        } catch (Throwable $e) {
            report($e);

            return [
                'saved' => false,
                'reason' => 'save_failed',
                'message' => 'The billing schedule was NOT saved: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function create(?string $customerName, array $input): array
    {
        if (Str::trimToNull($customerName) === null) {
            return [
                'saved' => false,
                'reason' => 'customer_name_required',
                'message' => 'A customer_name is required to create a billing schedule — never pick one yourself.',
            ];
        }

        if (! isset($input['cadence'])) {
            return ['saved' => false, 'reason' => 'cadence_required', 'message' => 'Pass a cadence: monthly, quarterly, yearly or once.'];
        }

        $customer = $this->resolveCustomerOrError((string) $customerName);

        if (is_array($customer)) {
            return ['saved' => false, ...$customer];
        }

        $schedule = new CreateBillingScheduleAction(
            data: BillingScheduleData::from($this->app, $this->company, ['customer' => $customer, ...$input]),
            user: $this->contextUser(),
        )->execute();

        return [
            'saved' => true,
            'action' => 'create',
            ...$this->presentBillingSchedule($schedule->refresh()),
            'message' => 'Billing schedule created. On each date it creates a DRAFT invoice and sends it for approval.',
        ];
    }

    /**
     * @param array<string, mixed> $input
     *
     * @return array<string, mixed>
     */
    private function change(string $action, ?int $scheduleId, array $input): array
    {
        if ($scheduleId === null) {
            return [
                'saved' => false,
                'reason' => 'billing_schedule_id_required',
                'message' => "action {$action} needs billing_schedule_id; list_billing_schedules returns it.",
            ];
        }

        $schedule = $this->resolveBillingSchedule($scheduleId);

        if (is_array($schedule)) {
            return ['saved' => false, ...$schedule];
        }

        $input = match ($action) {
            'pause' => ['status' => BillingScheduleStatusEnum::PAUSED->value],
            'resume' => ['status' => BillingScheduleStatusEnum::ACTIVE->value],
            default => $input,
        };

        $missed = $action === 'resume'
            && $schedule->next_run_at !== null
            && $schedule->next_run_at->lt(Carbon::today());

        $updated = new UpdateBillingScheduleAction(
            $schedule,
            BillingScheduleData::forUpdate($schedule, $input),
        )->execute();

        return [
            'saved' => true,
            'action' => $action,
            ...$this->presentBillingSchedule($updated),
            'message' => $missed
                ? 'Resumed. Periods missed while it was paused are billed on the next daily run, one invoice each.'
                : 'Billing schedule updated.',
        ];
    }
}
