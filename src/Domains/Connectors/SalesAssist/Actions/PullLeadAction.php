<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Kanvas\Connectors\Elead\Actions\PullLeadAction as ActionsPullLeadAction;
use Kanvas\Connectors\Elead\Enums\CustomFieldEnum as EnumsCustomFieldEnum;
use Kanvas\Connectors\VinSolution\Actions\PullLeadAction as VinSolutionActionsPullLeadAction;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead;

class PullLeadAction
{
    public function __construct(
        private Lead $lead
    ) {
    }

    public function execute(): array
    {
        $isElead = $this->lead->company->get(CustomFieldEnum::COMPANY->value) !== null;
        $isVinSolutions = $this->lead->company->get(EnumsCustomFieldEnum::COMPANY->value) !== null;

        //$people = People::getByCustomFieldBuilder(CustomFieldEnum::PERSON_ID, $peopleId, )

        if ($isElead) {
            return new ActionsPullLeadAction(
                $this->lead->app,
                $this->lead->company,
                $this->lead->user
            )->execute([], $this->lead);
        } elseif ($isVinSolutions) {
            return new VinSolutionActionsPullLeadAction(
                $this->lead->app,
                $this->lead->company,
                $this->lead->user
            )->execute(
                lead: $this->lead
            );
        }
    }
}
