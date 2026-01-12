<?php

declare(strict_types=1);

namespace Kanvas\Connectors\SalesAssist\Actions;

use Exception;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Guild\Leads\Models\Lead;

class SyncLeadWithLegacyCRMAction
{
    protected const string SALT = '$is_ghetto#api&secure?';
    protected string $apiUrl;
    protected string $cid;

    public function __construct(
        protected Lead $lead
    ) {
        $this->apiUrl = $this->lead->app->get(ConfigurationEnum::LEGACY_CRM_API_URL->value);

        if (empty($this->apiUrl)) {
            throw new InvalidArgumentException('Legacy CRM API URL is not configured.');
        }

        $this->cid = $this->lead->app->get(ConfigurationEnum::LEGACY_CRM_CLIENT_ID->value)
            ?? $this->lead->company->get(ConfigurationEnum::LEGACY_CRM_CLIENT_ID->value)
            ?? '';
    }

    public function execute(): array
    {
        if (empty($this->cid)) {
            return [
                'success' => false,
                'message' => 'Legacy CRM Client ID not configured',
            ];
        }

        $leadType = $this->detectLeadType();
        $payload = $this->formatLeadData($leadType);

        try {
            $response = Http::get($this->apiUrl, $payload);
            $result = $response->json();

            return [
                'success' => ($result['status'] ?? 0) === 1,
                'message' => $result['msg'] ?? 'Unknown response',
                'data' => $result,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Detect lead type based on lead data.
     */
    protected function detectLeadType(): string
    {
        // Check custom field first, then fall back to lead type name
        $leadTypeFromCustomField = $this->lead->get('lead_type');
        if ($leadTypeFromCustomField) {
            return $leadTypeFromCustomField;
        }

        $leadTypeName = $this->lead->type?->name ?? '';

        return match (strtolower($leadTypeName)) {
            'reservation', 'vehicle reservation' => 'reservation',
            'contact', 'general inquiry' => 'contact',
            'credit', 'finance', 'credit application' => 'credit',
            'service', 'service appointment' => 'service',
            'part', 'parts order' => 'part',
            'trade', 'trade-in' => 'trade',
            'private sale', 'sell', 'private_sale' => 'private_sale',
            'newsletter' => 'newsletter',
            'coupon' => 'coupon',
            'test drive', 'test' => 'test',
            default => 'contact',
        };
    }

    /**
     * Format lead data for legacy CRM API.
     */
    protected function formatLeadData(string $leadType): array
    {
        $people = $this->lead->people;
        $companyUrl = $this->lead->company->url ?? '';
        $domain = $companyUrl ? Str::replaceFirst('www.', '', parse_url($companyUrl, PHP_URL_HOST) ?? '') : '';

        $baseData = [
            'lead_type' => $leadType,
            'cid' => $this->cid,
            'key' => $this->generateKey(),
            'domain' => 'Internet',
            'vd_referrer' => $domain,
            'fname' => $people->firstname,
            'lname' => $people->lastname,
            'telephone' => $people->getAllPhones()->first()?->value ?? '',
            'email' => $people->getEmails()->first()?->value ?? '',
            'message' => $this->lead->get('message') ?? $this->lead->description ?? '',
            'callback' => 'jsonp_' . time(),
        ];

        // Add lead type-specific fields
        return array_merge($baseData, $this->getLeadTypeSpecificFields($leadType));
    }

    /**
     * Get fields specific to each lead type.
     */
    protected function getLeadTypeSpecificFields(string $leadType): array
    {
        return match ($leadType) {
            'reservation' => $this->getReservationFields(),
            'credit' => $this->getCreditFields(),
            'service' => $this->getServiceFields(),
            'part' => $this->getPartFields(),
            'trade' => $this->getTradeFields(),
            'private_sale' => $this->getSellFields(),
            'test' => $this->getTestDriveFields(),
            'coupon' => $this->getCouponFields(),
            default => [],
        };
    }

    /**
     * Get reservation-specific fields (vehicle of interest).
     */
    protected function getReservationFields(): array
    {
        $year = $this->lead->get('vehicleYear') ?? '';
        $make = $this->lead->get('vehicleMake') ?? '';
        $model = $this->lead->get('vehicleModel') ?? '';
        $trim = $this->lead->get('vehicleTrim') ?? '';
        $vin = $this->lead->get('vehicleVin') ?? '';
        $vehicleId = $this->lead->get('vehicleId') ?? '';
        $price = $this->lead->get('vehiclePrice') ?? '';
        $condition = $this->lead->get('vehicleCondition') ?? 'Usado';

        return [
            'veh_id' => $vehicleId,
            'veh_vin' => $vin,
            'vehicle' => $this->formatVehicleNameFromFields($year, $make, $model, $trim),
            'veh_price' => $price,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'trim' => $trim,
            'condition' => $condition,
            'price' => $price,
            'subject' => $this->lead->get('request') ?? $this->lead->description ?? '',
        ];
    }

    /**
     * Get credit application fields.
     */
    protected function getCreditFields(): array
    {
        $people = $this->lead->people;
        $dob = $people->dob ? date_parse($people->dob) : null;

        $addresses = $people->address()->get()->first();

        $year = $this->lead->get('vehicleYear') ?? '';
        $make = $this->lead->get('vehicleMake') ?? '';
        $model = $this->lead->get('vehicleModel') ?? '';
        $trim = $this->lead->get('vehicleTrim') ?? '';

        return [
            'month' => $dob['month'] ?? '',
            'year' => $dob['year'] ?? '',
            'day' => $dob['day'] ?? '',
            'civil_status' => $this->lead->get('civil_status') ?? 'single',
            'address' => $addresses?->address ?? '',
            'city' => $addresses?->city ?? '',
            'zip' => $addresses?->zip ?? '',
            'employment_status' => $this->lead->get('employment_status') ?? '',
            'monthly_income' => $this->lead->get('monthly_income') ?? '',
            'vehicle_interested' => $this->formatVehicleNameFromFields($year, $make, $model, $trim),
        ];
    }

    /**
     * Get service appointment fields.
     */
    protected function getServiceFields(): array
    {
        return [
            'preferred_date' => $this->lead->get('preferred_date') ?? '',
            'mileage' => $this->lead->get('vehicleMileage') ?? '',
            'make' => $this->lead->get('vehicleMake') ?? '',
            'model' => $this->lead->get('vehicleModel') ?? '',
            'year' => $this->lead->get('vehicleYear') ?? '',
        ];
    }

    /**
     * Get parts order fields.
     */
    protected function getPartFields(): array
    {
        return [
            'make' => $this->lead->get('vehicleMake') ?? '',
            'model' => $this->lead->get('vehicleModel') ?? '',
            'year' => $this->lead->get('vehicleYear') ?? '',
            'parts_for' => $this->lead->get('parts_for') ?? '',
            'urgency' => $this->lead->get('urgency') ?? '',
            'description' => $this->lead->get('message') ?? $this->lead->description ?? '',
        ];
    }

    /**
     * Get trade-in fields.
     */
    protected function getTradeFields(): array
    {
        return [
            'make' => $this->lead->get('vehicleMake') ?? '',
            'model' => $this->lead->get('vehicleModel') ?? '',
            'year' => $this->lead->get('vehicleYear') ?? '',
            'mileage' => $this->lead->get('vehicleMileage') ?? '',
            'condition' => $this->lead->get('vehicleCondition') ?? 'Good',
            'vin' => $this->lead->get('vehicleVin') ?? '',
        ];
    }

    /**
     * Get sell/private sale fields.
     */
    protected function getSellFields(): array
    {
        return [
            'make' => $this->lead->get('vehicleMake') ?? '',
            'model' => $this->lead->get('vehicleModel') ?? '',
            'year' => $this->lead->get('vehicleYear') ?? '',
            'mileage' => $this->lead->get('vehicleMileage') ?? '',
            'condition' => $this->lead->get('vehicleCondition') ?? 'Good',
            'vin' => $this->lead->get('vehicleVin') ?? '',
        ];
    }

    /**
     * Get test drive fields.
     */
    protected function getTestDriveFields(): array
    {
        $year = $this->lead->get('vehicleYear') ?? '';
        $make = $this->lead->get('vehicleMake') ?? '';
        $model = $this->lead->get('vehicleModel') ?? '';
        $trim = $this->lead->get('vehicleTrim') ?? '';

        return [
            'vehicle' => $this->formatVehicleNameFromFields($year, $make, $model, $trim),
            'make' => $make,
            'model' => $model,
            'year' => $year,
        ];
    }

    /**
     * Get coupon fields.
     */
    protected function getCouponFields(): array
    {
        $people = $this->lead->people;

        return [
            'full_name' => $people->firstname . ' ' . $people->lastname,
            'shopping_status' => $this->lead->get('shopping_status') ?? 'Researching',
            'make' => $this->lead->get('vehicleMake') ?? '',
            'model' => $this->lead->get('vehicleModel') ?? '',
        ];
    }

    /**
     * Format vehicle name from individual fields.
     */
    protected function formatVehicleNameFromFields(string $year, string $make, string $model, string $trim): string
    {
        $parts = array_filter([$year, $make, $model, $trim]);

        return implode(' ', $parts);
    }

    /**
     * Generate authentication key.
     */
    protected function generateKey(): string
    {
        return sha1($this->cid . self::SALT);
    }
}
