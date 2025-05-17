<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\Enums\CustomFieldEnums;
use Kanvas\Connectors\DriveCentric\Services\LeadService;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;

class PushLeadAction
{
    public function __construct(
        protected LeadModel $lead
    ) {
    }

    public function execute(): array
    {
        $phones = $this->lead->people->phones->map(function ($phone) {
            return [
                'type' => 'Home',
                'value' => $phone->value,
            ];
        });
        $emails = $this->lead->people->emails->map(function ($email) {
            return [
                'type' => 'Home',
                'value' => $email->value,
            ];
        });
        $lead = [
            'source' => [
                'type' => 'Internet',
                'description' => $this->lead->source->name ?? $this->lead->company->name,
            ],
            'customers' => [
                [
                    'isPrimaryBuyer' => true,
                    'type' => 'Individual',
                    'firstName' => $this->lead->people->firstname,
                    'middleName' => $this->lead->people->middlename ?? '',
                    'lastName' => $this->lead->people->lastname,
                    'birthdate' => $this->lead->people->dob,
                    'identifiers' => [
                        [
                            'type' => 'PartnerId',
                            'value' => (string)$this->lead->getId(),
                        ],
                    ],
                    'phones' => $phones->toArray(),
                    'emails' => $emails->toArray(),
                ],
        ],
            'identifiers' => [
                [
                    'type' => 'PartnerId',
                    'value' => (string)$this->lead->getId(),
                ],
            ],
            'stage' => 'Undefined',
        ];
        $leadService = new LeadService($this->lead->app);

        $data = $leadService->create($lead);
        $leadIdentifier = array_filter($data['identifiers'], function ($identifier) {
            return $identifier['type'] === 'CrmId';
        });
        $this->lead->set(CustomFieldEnums::DRIVE_CENTRIC_ID->value, $leadIdentifier['value']);
        $peopleIdentifier = array_filter($data['customers'][0]['identifiers'], function ($identifier) {
            return $identifier['type'] === 'CrmId';
        });
        $this->lead->people->set(CustomFieldEnums::DRIVE_CENTRIC_ID->value, $peopleIdentifier['value']);

        return $data;
    }
}
