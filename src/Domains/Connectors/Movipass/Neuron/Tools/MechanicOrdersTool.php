<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Kanvas\Connectors\Movipass\Repositories\MechanicOrdersRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Souk\Orders\Models\Order;
use NeuronAI\Tools\HasRunKey;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;

#[AgentTool(name: 'Mechanic Orders', category: 'commerce')]
class MechanicOrdersTool extends Tool implements HasRunKey
{
    use HasKanvasContext;
    use TrackByInputs;

    public function __construct()
    {
        parent::__construct(
            name: 'movipass_mechanic_orders',
            description: 'Roadside-assistance cases attached to a mechanic — either the ones assigned to them or '
                . 'the ones they were notified about. Use for "what is mechanic X working on", "how many cases did '
                . 'this tow driver take", "which cases were offered to him". Resolve the mechanic id with '
                . 'movipass_list_mechanics first. Omit mechanic_id to list every roadside case in the range.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(name: 'mechanic_id', type: PropertyType::INTEGER, description: 'The mechanic user id (see movipass_list_mechanics). Omit for every mechanic.', required: false),
            new ToolProperty(
                name: 'mechanic_filter',
                type: PropertyType::STRING,
                description: 'How the mechanic is linked to the case: ASSIGNED (default, the case is theirs) or NOTIFIED (they were offered it). Ignored without mechanic_id.',
                required: false,
                enum: ['ASSIGNED', 'NOTIFIED'],
            ),
            new ToolProperty(name: 'provider_company_id', type: PropertyType::INTEGER, description: 'Optional provider (tow/mechanic) company id. Omit for every provider.', required: false),
            new ToolProperty(name: 'since', type: PropertyType::STRING, description: 'Lower-bound case date, ISO YYYY-MM-DD. Omit for all-time.', required: false),
            new ToolProperty(name: 'until', type: PropertyType::STRING, description: 'Upper-bound case date, ISO YYYY-MM-DD. Omit for open-ended.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max cases to return. Default 25, max 100.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?int $mechanic_id = null,
        ?string $mechanic_filter = null,
        ?int $provider_company_id = null,
        ?string $since = null,
        ?string $until = null,
        ?int $limit = null,
    ): array {
        $query = MechanicOrdersRepository::query(
            app: $this->app,
            mechanicId: $mechanic_id,
            mechanicFilter: $mechanic_filter !== null ? strtoupper($mechanic_filter) : null,
            providerCompanyId: $provider_company_id,
        )
            ->fromCompany($this->company)
            ->when($since !== null && $since !== '', fn ($q) => $q->whereDate('orders.created_at', '>=', $since))
            ->when($until !== null && $until !== '', fn ($q) => $q->whereDate('orders.created_at', '<=', $until));

        $total = (clone $query)->count();
        $orders = $query
            ->with('people')
            ->orderByDesc('orders.created_at')
            ->limit(max(1, min(100, $limit ?? 25)))
            ->get();

        return [
            'total_cases' => $total,
            'returned' => $orders->count(),
            'cases' => $orders->map(function (Order $order): array {
                $people = $order->people;

                return [
                    'order_number' => $order->order_number,
                    'status' => $order->status,
                    'fulfillment_status' => $order->fulfillment_status,
                    'payment_status' => $order->payment_status,
                    'customer' => $people !== null
                        ? trim($people->firstname . ' ' . $people->lastname)
                        : ($order->user_email ?? ''),
                    'total' => (float) $order->total_gross_amount,
                    'created_at' => $order->created_at?->toDateTimeString(),
                ];
            })->all(),
        ];
    }
}
