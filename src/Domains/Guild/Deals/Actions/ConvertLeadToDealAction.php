<?php

declare(strict_types=1);

namespace Kanvas\Guild\Deals\Actions;

use Kanvas\Guild\Deals\DataTransferObject\Deal as DealData;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Guild\Leads\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

class ConvertLeadToDealAction
{
    public function __construct(
        protected readonly Lead $lead,
        protected readonly ?string $title = null,
        protected readonly ?string $description = null,
        protected readonly bool $runWorkflow = true,
    ) {
    }

    public function execute(): Deal
    {
        $lead = $this->lead;

        $data = new DealData(
            app: $lead->app,
            company: $lead->company,
            user: $lead->user,
            title: $this->title ?? (string) $lead->title,
            description: $this->description ?? $lead->description,
            branch: $lead->branch,
            lead: $lead,
            people: $lead->people,
            organization: $lead->organization_id > 0 ? $lead->organization : null,
            owner: $lead->owner,
        );

        $deal = new CreateDealAction(
            $data,
            $this->runWorkflow
        )->execute();

        $lead->set(ConfigurationEnum::CONVERTED_TO_DEAL_ID->value, $deal->getId());
        $lead->close();

        return $deal;
    }
}
