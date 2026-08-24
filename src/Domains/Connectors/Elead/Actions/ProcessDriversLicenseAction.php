<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\Enums\CustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Enums\PeopleCustomFieldEnum;
use Kanvas\Connectors\SalesAssist\Services\DriverLicenseVerificationService;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;

class ProcessDriversLicenseAction
{
    private const int DRIVERS_LICENSE_DOCUMENT_KEY = 3;

    public function __construct(
        protected Lead $lead,
        protected ?People $peopleToUpdate = null,
    ) {
    }

    public function execute(array $messageData): ?array
    {
        if (! isset($messageData['data'][self::DRIVERS_LICENSE_DOCUMENT_KEY])) {
            return null;
        }

        $people = $this->peopleToUpdate ?? $this->lead->people;

        $scan = $people->get(PeopleCustomFieldEnum::DRIVERS_LICENSE->value)
            ?? $this->lead->get(PeopleCustomFieldEnum::DRIVERS_LICENSE->value);

        if (! empty($scan) && is_array($scan)) {
            new DriverLicenseVerificationService(
                $this->lead->app,
                $this->lead->company,
                $this->lead->user,
            )->updatePeopleFromDriverLicense($people, $scan);
        }

        $this->lead->set(CustomFieldEnum::GET_DOCS_IMPORTER->value, [
            'active' => 1,
            'message' => 'Drivers License Ready to be imported into eLead.',
            'date' => date('Y-m-d H:i:s'),
        ]);

        $license = $people->getDriverLicense();

        if ($license === null) {
            return null;
        }

        return [
            'license' => $license->number,
            'state' => $license->state,
            'firstname' => $license->firstname,
            'middlename' => $license->middlename,
            'lastname' => $license->lastname,
            'address' => $license->address,
            'birthday' => $license->dob?->format('Y-m-d'),
            'exp_date' => $license->expirationDate?->format('Y-m-d'),
        ];
    }
}
