<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Neuron\Tools;

use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Enums\MechanicAvailabilityEnum;
use Kanvas\Connectors\Movipass\Repositories\MechanicsRepository;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\HasKanvasContext;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'List Mechanics', category: 'commerce')]
class ListMechanicsTool extends Tool
{
    use HasKanvasContext;

    public function __construct()
    {
        parent::__construct(
            name: 'movipass_list_mechanics',
            description: 'The roadside-assistance mechanic/tow-truck roster for this company: name, contact, '
                . 'availability, service type and roles. Use for "who is available right now", "how many mechanics '
                . 'do we have", "list the tow drivers", or to resolve a mechanic NAME into the id that '
                . 'movipass_mechanic_orders needs. Availability values are "activo" and "no_disponible"; a mechanic '
                . 'with no availability set has never reported one and is returned as null.',
        );
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'availability',
                type: PropertyType::STRING,
                description: 'Restrict to mechanics reporting this availability.',
                required: false,
                enum: array_column(MechanicAvailabilityEnum::cases(), 'value'),
            ),
            new ToolProperty(name: 'service_type', type: PropertyType::STRING, description: 'Restrict to a service type (as configured for the tenant). Omit for all.', required: false),
            new ToolProperty(name: 'search', type: PropertyType::STRING, description: 'Filter by (partial) name or email. Omit for the whole roster.', required: false),
            new ToolProperty(name: 'limit', type: PropertyType::INTEGER, description: 'Max mechanics to return. Default 25, max 100.', required: false),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(
        ?string $availability = null,
        ?string $service_type = null,
        ?string $search = null,
        ?int $limit = null,
    ): array {
        $search = $search !== null ? trim($search) : null;

        $mechanics = MechanicsRepository::query(
            app: $this->app,
            companyId: $this->company->getId(),
            availability: $availability,
            serviceType: $service_type,
        )
            // Qualified with `users.` — the roster query joins users_associated_apps, which carries
            // its own firstname/lastname/email columns.
            ->when($search !== null && $search !== '', fn ($q) => $q->where(
                fn ($inner) => $inner->where('users.firstname', 'like', '%' . $search . '%')
                    ->orWhere('users.lastname', 'like', '%' . $search . '%')
                    ->orWhere('users.email', 'like', '%' . $search . '%')
            ))
            ->with('roles')
            ->limit(max(1, min(100, $limit ?? 25)))
            ->get();

        return [
            'count' => $mechanics->count(),
            'mechanics' => $mechanics->map(fn (Users $mechanic): array => [
                'id' => $mechanic->getId(),
                'name' => trim($mechanic->firstname . ' ' . $mechanic->lastname),
                'email' => $mechanic->email,
                'phone' => $mechanic->phone_number ?? $mechanic->cell_phone_number,
                'availability' => $mechanic->get(CustomFieldEnum::MECHANIC_AVAILABILITY->value),
                'service_type' => $mechanic->get(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value),
                'roles' => $mechanic->roles->pluck('name')->all(),
            ])->all(),
        ];
    }
}
