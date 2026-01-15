<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

class AddCommentToDealAction
{
    protected LeadService $leadService;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->leadService = new LeadService($this->lead->app, $this->lead->company);
    }

    /**
     * Execute the action to add or update comments on the deal.
     */
    public function execute(string $comment): array
    {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        // If deal doesn't exist, create it first
        if (! $dealId) {
            $pushLeadAction = new PushLeadAction($this->lead);
            $pushLeadAction->execute();
            $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        }

        // Get full deal data with all required fields
        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);

        // Add deal identifier for update
        $dealData['identifiers'][] = ['type' => 'CrmId', 'value' => $dealId];

        // Add comment
        $dealData['comments'] = $comment;

        return $this->leadService->upsertDeal($dealData);
    }
}
