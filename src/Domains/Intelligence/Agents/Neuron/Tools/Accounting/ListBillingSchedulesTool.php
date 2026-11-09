<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\Accounting;

use Baka\Support\Str;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesBillingScheduleForTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesCustomerForTool;
use Kanvas\Scribe\BillingSchedules\Enums\BillingScheduleStatusEnum;
use Kanvas\Scribe\BillingSchedules\Models\BillingSchedule;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/** Lists the company's billing schedules (recurring retainers and signed-SOW payments). */
#[AgentTool(name: 'List Billing Schedules', category: 'accounting')]
class ListBillingSchedulesTool extends Tool
{
    use ResolvesBillingScheduleForTool;
    use ResolvesCustomerForTool;

    protected string $name = 'list_billing_schedules';

    protected ?string $description = 'Lists billing schedules: recurring retainers and the payment lines of signed SOWs, '
        . 'each with its customer, cadence, amount per period and next invoice date. Use it for "what do we bill '
        . 'every month", "when is Acme invoiced next", or to find the billing_schedule_id schedule_billing needs.';

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'status',
                type: PropertyType::STRING,
                description: 'One of: active, paused, finished. Omit for active and paused.',
                required: false,
            ),
            new ToolProperty(
                name: 'customer_name',
                type: PropertyType::STRING,
                description: 'Only this customer\'s schedules.',
                required: false,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(?string $status = null, ?string $customer_name = null): array
    {
        if (! $this->hasTenantContext()) {
            return ['found' => false, ...$this->tenantContextMissingError('billing schedule')];
        }

        $statusEnum = BillingScheduleStatusEnum::tryFrom(strtolower((string) Str::trimToNull($status)));

        $query = BillingSchedule::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->with('customer')
            ->orderBy('next_run_at')
            ->limit(100);

        $statusEnum !== null
            ? $query->where('status', $statusEnum->value)
            : $query->where('status', '!=', BillingScheduleStatusEnum::FINISHED->value);

        if (Str::trimToNull($customer_name) !== null) {
            $customer = $this->resolveCustomerOrError((string) $customer_name);

            if (is_array($customer)) {
                return ['found' => false, ...$customer];
            }

            $query->where('customer_organization_id', $customer->getId());
        }

        $schedules = $query->get()->map(fn (BillingSchedule $schedule): array => $this->presentBillingSchedule($schedule))->all();

        return [
            'found' => $schedules !== [],
            'count' => count($schedules),
            'billing_schedules' => $schedules,
            'message' => $schedules === []
                ? 'No billing schedules match. Retrying with the same filters will not change that.'
                : null,
        ];
    }
}
