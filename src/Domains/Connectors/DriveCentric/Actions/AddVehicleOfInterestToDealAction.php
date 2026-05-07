<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Social\Messages\Models\Message;

class AddVehicleOfInterestToDealAction
{
    public function __construct(
        protected LeadModel $lead
    ) {
    }

    public function execute(Message $message): array
    {
        $messageData = $message->getMessage();

        $vehicleOfInterest = $this->formatVehicleOfInterest($messageData);

        if (empty($vehicleOfInterest)) {
            return [];
        }

        $this->lead->set(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value, $vehicleOfInterest);

        $pushLeadAction = new PushLeadAction($this->lead);

        return $pushLeadAction->execute();
    }

    protected function formatVehicleOfInterest(array $messageData): ?array
    {
        $products = $messageData['data']['products'] ?? [];

        if (empty($products)) {
            return null;
        }

        $product = $products[0];

        if (! isset($product['make']) || ! isset($product['model'])) {
            return null;
        }

        if (isset($product['interested']) && (bool) $product['interested'] === false) {
            return null;
        }

        return [
            'year' => $product['year'] ?? null,
            'yearFrom' => $product['year'] ?? null,
            'make' => $product['make'],
            'model' => $product['model'],
            'vin' => $product['vin'] ?? null,
            'trim' => $product['trim'] ?? null,
            'stock_number' => $product['stock_number'] ?? null,
            'stockNumber' => $product['stock_number'] ?? null,
            'mileage' => $product['millage'] ?? $product['mileage'] ?? 0,
            'price' => $product['price'] ?? null,
            'msrp' => $product['price'] ?? null,
            'sellingPrice' => $product['price'] ?? null,
            'exteriorColor' => $product['ext_color'] ?? $product['exteriorColor'] ?? $product['color'] ?? null,
            'interiorColor' => $product['int_color'] ?? $product['interiorColor'] ?? null,
            'isNew' => $product['is_new'] ?? $product['isNew'] ?? null,
            'doors' => $product['doors'] ?? 4,
        ];
    }
}
