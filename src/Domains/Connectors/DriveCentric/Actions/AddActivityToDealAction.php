<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

class AddActivityToDealAction
{
    protected LeadService $leadService;

    public function __construct(
        protected LeadModel $lead
    ) {
        $this->leadService = new LeadService($this->lead->app, $this->lead->company);
    }

    /**
     * Execute the action to add an activity to the deal.
     */
    public function execute(
        string $title,
        ?string $content = null,
        ?string $when = null,
        ?array $user = null,
        ?array $files = null
    ): array {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        // If deal doesn't exist, create it first
        if (! $dealId) {
            $pushLeadAction = new PushLeadAction($this->lead);
            $pushLeadAction->execute();
            $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        }

        // Build activity object
        $activity = [
            'identifiers' => [
                [
                    'type' => 'PartnerId',
                    'value' => (string) $this->lead->getId() . '-activity-' . time(),
                ],
            ],
            'when' => $when ?? now()->format('Y-m-d\TH:i:s.v\Z'),
            'title' => $title,
        ];

        // Add user - use provided user or default to lead owner
        if ($user) {
            $activity['user'] = $user;
        } else {
            // Default to lead owner - use CrmId from custom field
            $leadOwner = $this->lead->user;
            $driveCentricUserId = $leadOwner->get(CustomFieldEnums::DRIVE_CENTRIC_USER_ID->value);
            
            $activity['user'] = [
                'identifiers' => [
                    ['type' => 'CrmId', 'value' => $driveCentricUserId],
                ],
                'firstName' => $leadOwner->firstname,
                'lastName' => $leadOwner->lastname,
            ];
        }

        // Add optional fields
        if ($content) {
            $activity['content'] = $content;
        }

        if ($files) {
            $activity['files'] = $files;
        }

        // Get full deal data with all required fields
        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);
        
        // Add deal identifier for update
        $dealData['identifiers'][] = ['type' => 'CrmId', 'value' => $dealId];
        
        // Add activity
        $dealData['activities'] = [$activity];

        return $this->leadService->upsertDeal($dealData);
    }
}
