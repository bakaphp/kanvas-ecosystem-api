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
use Kanvas\Scribe\BillingSchedules\Actions\RunBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\Actions\UpdateBillingScheduleAction;
use Kanvas\Scribe\BillingSchedules\DataTransferObject\BillingSchedule as BillingScheduleData;
use Kanvas\Scribe\BillingSchedules\Enums\BillingCadenceEnum;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use Kanvas\Scribe\Invoices\Models\Invoice;
use NeuronAI\Tools\ArrayProperty;
use NeuronAI\Tools\ObjectProperty;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\ToolPropertyInterface;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

/**
 * Creates, updates, pauses, resumes or bills a billing schedule.
 *
 * Billing runs through the schedule, never around it. A milestone a person wants billed before its date (handover
 * came early, the customer wants to prepay) is `bill_now`: the schedule's own run makes the invoice, linked to the
 * quote with the quote's lines, and marks the period billed so the nightly run cannot bill it again. The agent once
 * had no such action, moved the milestone's date to today instead and promised a background run that only happens
 * once a day; a hand-built create_ar_invoice for the same amount would have billed the customer twice.
 */
#[AgentTool(name: 'Schedule Billing', category: 'accounting')]
class ScheduleBillingTool extends Tool
{
    use ResolvesBillingScheduleForTool;
    use ResolvesCustomerForTool;
    use TrackByInputs;

    private const array ACTIONS = ['create', 'update', 'pause', 'resume', 'bill_now'];

    protected string $name = 'schedule_billing';

    protected ?string $description = 'Sets up billing that repeats (a monthly retainer, hosting, support) or happens once '
        . 'on a date. Each period the schedule creates a DRAFT invoice and sends it for approval; it never issues one '
        . 'by itself. action "create" needs customer_name, lines and cadence; "update" changes a schedule '
        . '(billing_schedule_id) for periods not billed yet; "pause" and "resume" stop and restart it; "bill_now" '
        . 'bills the schedule\'s next period today, before its date, and hands back the draft invoice: use it when a '
        . 'person asks for a SOW milestone or a scheduled payment ahead of schedule, after telling them its planned '
        . 'date. Schedules otherwise bill on their date in a nightly run. Only on the user\'s explicit request. A '
        . 'signed SOW schedules its own payments, so do not schedule those again, and never rebuild one of its '
        . 'payments with create_ar_invoice.';

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
                description: 'One of: create, update, pause, resume, bill_now.',
                required: true,
            ),
            new ToolProperty(
                name: 'billing_schedule_id',
                type: PropertyType::INTEGER,
                description: 'Required for update, pause, resume and bill_now (list_billing_schedules returns it).',
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

        if ($action === 'bill_now') {
            return $this->billNow($schedule);
        }

        $updated = new UpdateBillingScheduleAction(
            $schedule,
            BillingScheduleData::forUpdate($schedule, $input),
        )->execute();

        if (! $updated->isDue(Carbon::today())) {
            return [
                'saved' => true,
                'action' => $action,
                ...$this->presentBillingSchedule($updated),
                'message' => 'Billing schedule updated.',
            ];
        }

        // A change that lands a period on or before today bills it now; left to the nightly run, the person is told
        // the invoice is coming and nothing happens for hours. One period: a schedule that fell behind while paused
        // catches up on the nightly run, one invoice each, so a resume never floods the customer in one turn.
        $invoice = $this->runOnce($updated);
        $updated->refresh();
        $morePeriodsDue = $updated->isDue(Carbon::today());

        return [
            'saved' => true,
            'action' => $action,
            ...$this->presentBillingSchedule($updated),
            ...$this->presentInvoice($invoice),
            'message' => match (true) {
                $invoice === null => 'Billing schedule updated. Its due period is already billed or awaiting submission; find_invoice shows it.',
                $morePeriodsDue => 'Billing schedule updated and its first due period billed: the DRAFT invoice is with the approver. '
                    . 'The other periods it missed are billed on the nightly run, one invoice each.',
                default => 'Billing schedule updated and its due period billed: the DRAFT invoice is with the approver.',
            },
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function billNow(BillingSchedule $schedule): array
    {
        if ($schedule->status !== BillingScheduleStatusEnum::ACTIVE || $schedule->next_run_at === null) {
            return [
                'saved' => false,
                'reason' => 'nothing_to_bill',
                ...$this->presentBillingSchedule($schedule),
                'message' => "Billing schedule {$schedule->getId()} is {$schedule->status->value} with no period left to bill. "
                    . 'A paused schedule is resumed first; a finished one has billed every period.',
            ];
        }

        // A recurring schedule billed early would shift every later period onto today's day; its periods stay on
        // the anchor day, and a person who wants it on another day changes the anchor day with update.
        if ($schedule->cadence !== BillingCadenceEnum::ONCE) {
            return [
                'saved' => false,
                'reason' => 'recurring_schedule',
                ...$this->presentBillingSchedule($schedule),
                'message' => "Billing schedule {$schedule->getId()} is {$schedule->cadence->value}: it bills on its day, "
                    . "next on {$schedule->next_run_at->toDateString()}. bill_now is for a one-time payment (a SOW "
                    . 'milestone). To bill a recurring schedule on another day, change its anchor_day or start_date '
                    . 'with update; a change that lands a period today bills it at once.',
            ];
        }

        $plannedFor = $schedule->next_run_at->toDateString();
        $early = $schedule->next_run_at->gt(Carbon::today());

        if ($early) {
            new UpdateBillingScheduleAction(
                $schedule,
                BillingScheduleData::forUpdate($schedule, ['start_date' => Carbon::today()->toDateString()]),
            )->execute();
        }

        $invoice = $this->runOnce($schedule->refresh());

        return [
            'saved' => true,
            'action' => 'bill_now',
            ...$this->presentBillingSchedule($schedule->refresh()),
            ...$this->presentInvoice($invoice),
            'planned_for' => $plannedFor,
            'billed_early' => $early,
            'message' => $invoice !== null
                ? sprintf(
                    'Billed the period planned for %s today: DRAFT invoice %d is with the approver and is numbered and '
                    . 'issued when they approve it. Say so; do not claim it was issued.%s',
                    $plannedFor,
                    $invoice->getId(),
                    $early ? ' Tell the person it was billed ahead of its planned date.' : ''
                )
                : 'This period is already billed or its draft is awaiting submission, so nothing new was created; '
                . 'find_invoice shows it.',
        ];
    }

    private function runOnce(BillingSchedule $schedule): ?Invoice
    {
        return new RunBillingScheduleAction($schedule, Carbon::today())->execute();
    }

    /**
     * @return array<string, mixed>
     */
    private function presentInvoice(?Invoice $invoice): array
    {
        if ($invoice === null) {
            return [];
        }

        return [
            'invoice_id' => $invoice->getId(),
            'invoice_status' => $invoice->document_status->value,
            'pending_approval' => $invoice->pendingApproval() !== null,
            'total' => (float) $invoice->total_native,
            'currency' => $invoice->currency,
            'quote_id' => $invoice->quote_id,
        ];
    }
}
