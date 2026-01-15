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

    public function execute(
        string $title,
        ?string $content = null,
        ?string $when = null,
        ?array $user = null,
        ?array $files = null
    ): array {
        $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);

        if (! $dealId) {
            $pushLeadAction = new PushLeadAction($this->lead);
            $pushLeadAction->execute();
            $dealId = $this->lead->get(CustomFieldEnums::DRIVE_CENTRIC_DEAL_ID->value);
        }

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

        if ($user) {
            $activity['user'] = $user;
        } else {
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

        if ($content) {
            $activity['content'] = $content;
        }

        if ($files) {
            $activity['files'] = $files;
        }

        $dealData = $this->leadService->formatLeadForDriveCentric($this->lead);

        $dealData['identifiers'][] = ['type' => 'CrmId', 'value' => $dealId];

        $dealData['activities'] = [$activity];

        return $this->leadService->upsertDeal($dealData);
    }
}
