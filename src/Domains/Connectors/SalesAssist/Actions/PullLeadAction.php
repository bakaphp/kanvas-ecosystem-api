<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Connectors\DealerSocket\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\DealerSocket\Enums\CustomFieldEnum;
use Kanvas\Connectors\Elead\Actions\PullLeadAction as EleadPullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EleadCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as VinSolutionPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum as VinSolutionCustomFieldEnum;
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
            default => [],
        };
    }

    private function detectConnector(): string
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

        return 'unknown';
    }

    private function executeElead(): array
    {
        return (new EleadPullLeadAction(
            $this->lead->app,
            $this->lead->company,
            $this->lead->user
        ))->execute([], $this->lead);
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
}
