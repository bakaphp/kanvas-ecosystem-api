<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Social\Messages\Models\Message;

class ProcessPurchaseVehicleAction
{
    public function __construct(
        protected LeadModel $lead
    ) {
    }

    public function execute(Message $message): array
    {
        $messageData = $message->getMessage();
        $results = [];

        // Extract and add trade-in from purchase vehicle data
        $tradeInData = $this->extractTradeInFromPurchaseVehicle($messageData);

        if (! empty($tradeInData)) {
            // Save trade-in data to lead custom field
            $this->lead->set(LeadCustomFieldEnum::TRADE_IN->value, $tradeInData);

            // Push the lead with trade-in data to DriveCentric
            $pushLeadAction = new PushLeadAction($this->lead);
            $results = $pushLeadAction->execute();
        }

        return $results;
    }

    /**
     * Extract trade-in data from purchase vehicle message structure.
     * All data is in documentForms[0]['form'] with keys like 'lead.trade-in.year', 'lead.payoff.bank.amount', etc.
     */
    protected function extractTradeInFromPurchaseVehicle(array $messageData): ?array
    {
        $documentForms = $messageData['data']['documentForms'] ?? [];

        if (empty($documentForms)) {
            return null;
        }

        // All data is in documentForms[0]['form']
        $formData = $documentForms[0]['form'] ?? [];

        // Check for required fields
        $year = $formData['lead.trade-in.year'] ?? null;
        $make = $formData['lead.trade-in.make'] ?? null;
        $model = $formData['lead.trade-in.model'] ?? null;

        if (empty($year) || empty($make) || empty($model)) {
            return null;
        }

        // Clean up mileage
        $mileage = $formData['lead.trade-in.odometer'] ?? null;
        if ($mileage !== null) {
            $mileage = (int) str_replace(',', '', (string) $mileage);
        }

        // Clean up monetary values
        $payoffAmount = $this->parseMonetaryValue($formData['lead.payoff.bank.amount'] ?? null);

        // Build lienholder info
        $lienholderName = $formData['lead.payoff.bank.name'] ?? null;
        $lienholder = null;
        if (! empty($lienholderName)) {
            $lienholder = [
                'name' => $lienholderName,
            ];
        }

        return [
            'vehicle' => [
                'vin' => $formData['lead.trade-in.vin'] ?? null,
                'year' => $year,
                'make' => $make,
                'model' => $model,
                'trim' => null,
                'mileage' => $mileage,
                'exteriorColor' => null,
                'interiorColor' => null,
                'stockNumber' => null,
                'bodyStyle' => null,
            ],
            'payoffAmount' => $payoffAmount,
            'allowance' => null,
            'actualCashValue' => null,
            'lienholder' => $lienholder,
        ];
    }

    /**
     * Parse monetary value to float.
     */
    protected function parseMonetaryValue(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        // Remove currency symbols and commas
        $cleaned = preg_replace('/[^0-9.]/', '', (string) $value);

        if ($cleaned === '') {
            return null;
        }

        $floatValue = (float) $cleaned;

        return $floatValue > 0 ? round($floatValue, 2) : null;
    }
}
