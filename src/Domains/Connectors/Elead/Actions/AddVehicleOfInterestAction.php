<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Baka\Enums\StateEnums;
use Kanvas\Connectors\Elead\DataTransferObject\Vehicle;
use Kanvas\Guild\Leads\Models\Lead;

class AddVehicleOfInterestAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    /**
     * Sync a Lead.
     */
    public function execute(array $message): Vehicle
    {
        $syncLead = new SyncLeadAction($this->lead);
        $eLead = $syncLead->execute($this->lead);

        $formData = $message['data']['form'];
        //clean up milage
        $number = str_replace(',', '', $message['data']['form']['mileage']);

        $vehicle = new Vehicle(
            (bool) $formData['is_new'],
            (int) $formData['year'],
            (int)  $formData['year'],
            $formData['make'] ?? '',
            $formData['model'] ?? '',
            isset($formData['trim']) ? substr($formData['trim'], 0, 50) : '',
            $formData['vin'],
            $formData['stock_number'],
            (int)  $formData['price'],
            (int)  $formData['price'],
            (int)  $formData['milage'] ?? 0,
            (bool) StateEnums::NO->getValue()
        );

        $eLead->addVehicle($vehicle);

        return $vehicle;
    }
}
