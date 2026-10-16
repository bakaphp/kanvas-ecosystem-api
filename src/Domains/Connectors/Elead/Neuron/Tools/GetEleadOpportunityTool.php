<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Neuron\Tools;

use Kanvas\Connectors\Elead\Neuron\Tools\Concerns\ReadsEleadForLead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\GuardsRepeatCalls;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use NeuronAI\Tools\TrackByInputs;
use Override;
use Throwable;

#[AgentTool(name: 'Get eLead Opportunity', category: 'elead')]
class GetEleadOpportunityTool extends Tool
{
    use GuardsRepeatCalls;
    use ReadsEleadForLead;
    use TrackByInputs;

    private const int MAX_VEHICLES = 5;

    protected string $name = 'get_elead_opportunity';

    protected ?string $description = 'Read the lead\'s opportunity as eLead (the dealer CRM) holds it right now: status and '
        . 'sub-status, up type, source, the sales team and who is primary on it, vehicles of interest, trade-ins, '
        . 'and whether the customer is in the showroom. Use it when asked what eLead says about a lead, or when '
        . 'Kanvas and eLead seem to disagree; refresh_lead_from_elead then brings Kanvas in line. For '
        . 'appointments use get_elead_appointments.';

    public function __construct()
    {
        $this->initRepeatGuard();
    }

    /**
     * @return array<int, ToolProperty>
     */
    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead whose eLead opportunity to read.',
                required: true,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function __invoke(int $lead_id): array
    {
        return $this->oncePerTurn(['lead_id' => $lead_id], fn (): array => $this->read($lead_id));
    }

    /**
     * @return array<string, mixed>
     */
    private function read(int $leadId): array
    {
        $refusal = $this->customerSurfaceRefusal('The eLead opportunity');
        if ($refusal !== null) {
            return $refusal;
        }

        $lead = $this->resolveEleadLeadOrError($leadId);
        if (is_array($lead)) {
            return $lead;
        }

        try {
            $opportunity = $this->fetchOpportunity($lead);
        } catch (Throwable $e) {
            return $this->eleadFailure($e, $lead);
        }

        $timezone = $this->timezone($lead);

        return $this->ok([
            'lead_id' => $lead->getId(),
            'elead_opportunity_id' => $opportunity->id,
            'elead_customer_id' => $opportunity->customerId,
            'status' => $opportunity->status,
            'sub_status' => $opportunity->subStatus,
            'is_active' => $opportunity->isActive(),
            'up_type' => $opportunity->upType,
            'in_showroom' => $opportunity->inShowRoom(),
            'source' => $opportunity->source,
            'sub_source' => $opportunity->subSource,
            'date_in' => $this->localize($opportunity->dateIn, $timezone),
            'sales_team' => array_map($this->presentSalesPerson(...), $opportunity->salesTeam),
            'sought_vehicles' => array_slice($opportunity->soughtVehicles, 0, self::MAX_VEHICLES),
            'trade_ins' => array_slice($opportunity->tradeIns, 0, self::MAX_VEHICLES),
        ]);
    }

    /**
     * @param array<string, mixed> $member
     * @return array<string, mixed>
     */
    private function presentSalesPerson(array $member): array
    {
        $name = trim(($member['firstName'] ?? '') . ' ' . ($member['lastName'] ?? ''));

        return [
            'elead_user_id' => $member['id'] ?? null,
            'name' => $name !== '' ? $name : ($member['name'] ?? null),
            'position' => $member['positionName'] ?? $member['positionCode'] ?? null,
            'is_primary' => (bool) ($member['isPrimary'] ?? false),
        ];
    }
}
