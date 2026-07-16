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

        $driverLicenseData = $people->get(PeopleCustomFieldEnum::DRIVERS_LICENSE->value)
            ?? $this->lead->get(PeopleCustomFieldEnum::DRIVERS_LICENSE->value);

        if (! empty($driverLicenseData) && is_array($driverLicenseData)) {
            new DriverLicenseVerificationService(
                $this->lead->app,
                $this->lead->company,
                $this->lead->user,
            )->updatePeopleFromDriverLicense($people, $driverLicenseData);
        }

        $this->lead->set(CustomFieldEnum::GET_DOCS_IMPORTER->value, [
            'active' => 1,
            'message' => 'Drivers License Ready to be imported into eLead.',
            'date' => date('Y-m-d H:i:s'),
        ]);

        return is_array($driverLicenseData) ? $driverLicenseData : null;
    }
}
