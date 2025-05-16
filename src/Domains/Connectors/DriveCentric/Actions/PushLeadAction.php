<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\DriveCentric\DataTransferObject\CustomerDriveCentric;
use Kanvas\Connectors\DriveCentric\DataTransferObject\IdentifierDriveCentric;
use Kanvas\Connectors\DriveCentric\DataTransferObject\LeadDriveCentric;
use Kanvas\Connectors\DriveCentric\DataTransferObject\LeadSourceDriveCentric;
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
        $identifiers = [
            IdentifierDriveCentric::from([
                'type' => 'CrmId',
                'value' => $this->lead->getId(),
            ]),
        ];
        $leadSource = [
            LeadSourceDriveCentric::from([
                'type' => 'Internet',
                'value' => $this->lead->source->name,
            ]),
        ];
        $phones = $this->lead->people->phones->map(function ($phone) {
            return [
                'type' => 'Home',
                'value' => $phone->id,
            ];
        });
        $emails = $this->lead->people->emails->map(function ($email) {
            return [
                'type' => 'Home',
                'value' => $email->id,
            ];
        });
        $customer = CustomerDriveCentric::from([
            'identifiers' => [
                IdentifierDriveCentric::from([
                    'type' => 'CrmId',
                    'value' => $this->lead->people->getId(),
                ]),
                ],
            'isPrimaryBuyer' => true,
            'type' => 'Individual',
            'firstName' => $this->lead->people->firstname,
            'middleName' => $this->lead->people->middlename,
            'lastName' => $this->lead->people->lastname,
            'birthdate' => $this->lead->people->dob,
            'phones' => $phones,
            'emails' => $emails,
        ]);
        $lead = LeadDriveCentric::from([
            'identifiers' => $identifiers,
            'leadSource' => $leadSource,
            'customer' => $customer,
        ]);

        $leadService = new LeadService($this->lead->app);
        return $leadService->create($lead);
    }
}
