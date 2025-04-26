<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Elead\Actions;

use Kanvas\Connectors\Elead\DataTransferObject\Vehicle;
use Kanvas\Guild\Leads\Models\Lead;

class AddVehicleAction
{
    public function __construct(
        protected Lead $lead
    ) {
    }

    public function execute(array $message): ?Vehicle
    {
        $syncLead = new SyncLeadAction($this->lead);
        $eLead = $syncLead->execute();

        $products = $message['data']['products'];

        $i = 0;
        foreach ($products as $product) {
            if (! isset($product['make']) || ! isset($product['model']) || ! isset($product['interested'])) {
                continue;
            }

            if ((bool) $product['interested'] == false) {
                continue;
            }

            $primary = false;
            if ($i == count($products) - 1) {
                $primary = true;
            }

            $vehicleInfo = new Vehicle(
                (bool) $product['is_new'],
                (int) $product['year'],
                (int) $product['year'],
                (string) $product['make'],
                (string) $product['model'],
                (string) ($product['trim'] ?? ' '),
                (string) $product['vin'],
                (string) $product['stock_number'],
                0,
                (int) $product['price'],
                (int) ($product['millage'] ?? 0),
                (bool) $primary
            );

            $eLead->addVehicle($vehicleInfo);
            $i++;
        }

        return $vehicleInfo ?? null;
    }
}
