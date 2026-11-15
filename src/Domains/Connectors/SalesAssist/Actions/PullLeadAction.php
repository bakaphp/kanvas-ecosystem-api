<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Connectors\DealerSocket\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\DriveCentric\Actions\PullLeadAction as DriveCentricActionsPullLeadAction;
use Kanvas\Connectors\DriveCentric\Enums\ConfigurationEnum;
use Kanvas\Connectors\Elead\Actions\PullLeadAction as EleadPullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as VinSolutionPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
use Kanvas\Guild\Leads\DataTransferObject\LeadCandidate;
use Kanvas\Guild\Leads\Models\Lead;

class PullLeadAction
{
    public function __construct(
        private Lead $lead
    ) {
    }

    public function execute(): array
    {
        $connector = $this->detectConnector();

        return match ($connector) {
            'elead' => $this->executeElead(),
            'vinsolution' => $this->executeVinSolution(),
            'dealersocket' => $this->executeDealerSocket(),
            'drivecentric' => $this->executeDriveCentric(),
            default => [],
        };
    }

    public function detectConnector(): string
    {
        if ($this->lead->company->get(EleadCustomFieldEnum::COMPANY->value) !== null) {
            return 'elead';
        }

        if ($this->lead->company->get(VinSolutionCustomFieldEnum::COMPANY->value) !== null) {
            return 'vinsolution';
        }

        if ($this->lead->company->get(CustomFieldEnum::DEALER_SOCKET_CREDENTIAL->value) !== null) {
            return 'dealersocket';
        }

        if ($this->lead->company->get(ConfigurationEnum::STORE_ID->value) !== null) {
            return 'drivecentric';
        }

        return 'unknown';
    }

    /**
     * The eLead pull only syncs the given lead (status, owner, appointments) on its `entity_id`
     * branch; with an empty request it falls into a customer search with no terms and touches nothing.
     * Leads imported from eLead carry only the opportunity id, so those sync on it directly.
     */
    private function executeElead(): array
    {
        $pull = new EleadPullLeadAction(
            $this->lead->app,
            $this->lead->company,
            $this->lead->user
        );
        $eleadLeadId = $this->lead->get(EleadCustomFieldEnum::LEAD_ID->value);
        $opportunityId = $this->lead->get(EleadCustomFieldEnum::OPPORTUNITY_ID->value);

        if (blank($eleadLeadId) && filled($opportunityId)) {
            $pull->pullKnownLead($this->lead, (string) $opportunityId);

            return [
                new LeadCandidate($this->lead)->toArray(),
            ];
        }

        return $pull->execute(['entity_id' => $eleadLeadId], $this->lead);
    }

    private function executeVinSolution(): array
    {
        return (new VinSolutionPullLeadAction(
            $this->lead->app,
            $this->lead->company,
            $this->lead->user
        ))->execute(lead: $this->lead);
    }

    private function executeDealerSocket(): array
    {
        //code...
        return new ActionsPullLeadAction(
            $this->lead->app,
            $this->lead->company,
            $this->lead->user
        )->execute(lead: $this->lead);
    }

    private function executeDriveCentric(): array
    {
        return new DriveCentricActionsPullLeadAction(
            $this->lead->app,
            $this->lead->company,
            $this->lead->user
        )->execute($this->lead)
        ->toArray();
    }
}
