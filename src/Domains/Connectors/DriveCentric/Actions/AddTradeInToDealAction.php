<?php

declare(strict_types=1);

namespace Kanvas\Connectors\DriveCentric\Actions;

use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Leads\Models\Lead as LeadModel;
use Kanvas\Social\Messages\Models\Message;

class AddTradeInToDealAction
{
    public function __construct(
        protected LeadModel $lead
    ) {
    }

    public function execute(Message $message): array
    {
        $messageData = $message->getMessage();

        $tradeIn = $this->formatTradeIn($messageData);

        if (empty($tradeIn)) {
            return [];
        }

        $this->lead->set(LeadCustomFieldEnum::TRADE_IN->value, $tradeIn);

        $pushLeadAction = new PushLeadAction($this->lead);

        return $pushLeadAction->execute();
    }

    protected function formatTradeIn(array $messageData): ?array
    {
        $formData = $messageData['data']['form'] ?? [];

        // Check for required fields (at least year, make, model)
        if (empty($formData['year']) || empty($formData['make']) || empty($formData['model'])) {
            return null;
        }

        // Clean up mileage (remove commas)
        $mileage = isset($formData['mileage'])
            ? (int) str_replace(',', '', (string) $formData['mileage'])
            : null;

        // Clean up monetary values
        $payoffAmount = $this->parseMonetaryValue($formData['payoff_amount'] ?? $formData['payoff'] ?? null);
        $value = $this->parseMonetaryValue($formData['value'] ?? $formData['acv'] ?? null);
        $allowance = $this->parseMonetaryValue($formData['allowance'] ?? null);

        $tradeIn = [
            'vehicle' => [
                'vin' => $formData['vin'] ?? null,
                'year' => (int) $formData['year'],
                'make' => $formData['make'],
                'model' => $formData['model'],
                'trim' => isset($formData['trim']) ? substr((string) $formData['trim'], 0, 50) : null,
                'mileage' => $mileage,
                'exteriorColor' => $formData['ext_color'] ?? $formData['exteriorColor'] ?? null,
                'interiorColor' => $formData['int_color'] ?? $formData['interiorColor'] ?? null,
                'stockNumber' => $formData['stock_number'] ?? $formData['stockNumber'] ?? null,
            ],
            'payoffAmount' => $payoffAmount,
            'allowance' => $allowance,
            'actualCashValue' => $value,
        ];

        // Add additional vehicle details if available
        if (isset($formData['body_style'])) {
            $tradeIn['vehicle']['bodyStyle'] = $formData['body_style'];
        }

        if (isset($formData['engine'])) {
            $tradeIn['vehicle']['engineName'] = $formData['engine'];
        }

        if (isset($formData['trans']) || isset($formData['transmission'])) {
            $tradeIn['vehicle']['transmission'] = $formData['trans'] ?? $formData['transmission'];
        }

        if (isset($formData['drive_train']) || isset($formData['driveTrain'])) {
            $tradeIn['vehicle']['driveTrain'] = $formData['drive_train'] ?? $formData['driveTrain'];
        }

        if (isset($formData['doors'])) {
            $tradeIn['vehicle']['doors'] = (int) $formData['doors'];
        }

        // Add lienholder information if provided
        $lienholder = $this->formatLienholder($formData);
        if (! empty($lienholder)) {
            $tradeIn['lienholder'] = $lienholder;
        }

        // Handle payoff-form specific case where only payoff is provided
        if ($messageData['verb'] ?? '' === 'payoff-form') {
            $tenDayPayoff = $formData['ten_day_payoff']['value'] ?? null;
            if ($tenDayPayoff !== null) {
                $tradeIn['payoffAmount'] = $this->parseMonetaryValue($tenDayPayoff);
            }
        }

        return $tradeIn;
    }

    protected function formatLienholder(array $formData): ?array
    {
        // Check if lienholder data exists
        if (empty($formData['lienholder_name']) && empty($formData['lienholder'])) {
            return null;
        }

        $lienholderName = $formData['lienholder_name'] ?? $formData['lienholder'] ?? null;

        if (empty($lienholderName)) {
            return null;
        }

        $lienholder = [
            'name' => $lienholderName,
        ];

        // Add contact information
        if (isset($formData['lienholder_contact'])) {
            $lienholder['contact'] = $formData['lienholder_contact'];
        }

        if (isset($formData['lienholder_phone'])) {
            $lienholder['phone'] = $this->cleanPhoneNumber($formData['lienholder_phone']);
        }

        // Add lienholder address
        if (isset($formData['lienholder_address']) || isset($formData['lienholder_address_line1'])) {
            $lienholder['address'] = [
                'line1' => $formData['lienholder_address'] ?? $formData['lienholder_address_line1'] ?? null,
                'line2' => $formData['lienholder_address_line2'] ?? null,
                'city' => $formData['lienholder_city'] ?? null,
                'stateOrProvince' => $formData['lienholder_state'] ?? null,
                'zipOrPostalCode' => $formData['lienholder_zip'] ?? null,
                'countryCode' => 'US',
            ];
        }

        // Add account information
        if (isset($formData['lienholder_account'])) {
            $lienholder['account'] = $formData['lienholder_account'];
        }

        // Add good until date
        if (isset($formData['lienholder_good_until']) || isset($formData['payoff_good_until'])) {
            $goodUntil = $formData['lienholder_good_until'] ?? $formData['payoff_good_until'];
            $lienholder['goodUntil'] = $this->formatDateTime($goodUntil);
        }

        // Add per diem
        if (isset($formData['lienholder_per_diem']) || isset($formData['per_diem'])) {
            $lienholder['perDiem'] = $this->parseMonetaryValue(
                $formData['lienholder_per_diem'] ?? $formData['per_diem']
            );
        }

        // Add comments
        if (isset($formData['lienholder_comments']) || isset($formData['payoff_comments'])) {
            $lienholder['comments'] = $formData['lienholder_comments'] ?? $formData['payoff_comments'];
        }

        return $lienholder;
    }

    /**
     * Parse monetary value to float.
     */
    protected function parseMonetaryValue(float|null|string $value): ?float
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

    /**
     * Clean phone number to standard format.
     */
    protected function cleanPhoneNumber(string $phone): ?string
    {
        $cleaned = preg_replace('/\D/', '', $phone);

        // Remove leading 1 if 11 digits (US country code)
        if (strlen($cleaned) === 11 && str_starts_with($cleaned, '1')) {
            $cleaned = substr($cleaned, 1);
        }

        return strlen($cleaned) === 10 ? $cleaned : null;
    }

    /**
     * Format datetime string for API.
     */
    protected function formatDateTime(?string $dateTime): ?string
    {
        if (empty($dateTime)) {
            return null;
        }

        try {
            $date = new \DateTime($dateTime);

            return $date->format('Y-m-d\TH:i:s.000\Z');
        } catch (\Throwable) {
            return null;
        }
    }
}
