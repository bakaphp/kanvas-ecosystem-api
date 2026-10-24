<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Services;

use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;

final class ZohoFieldMapService
{
    private const array CREDIT_SCORES = [
        1 => '720-950',
        'Excellent' => 'Excellent(720+)',
        2 => '680-719',
        'Great' => 'Great(680-719)',
        3 => '640-679',
        'Good' => 'Good(650-679)',
        4 => '639 or less',
        'Fair' => 'Fair(600-649)',
        'Poor' => 'Poor(Below 600)',
        'A+' => 'A+',
    ];

    /**
     * Turns `kanvasKey => zohoKey` (or `kanvasKey => ['name' => zohoKey, 'type' => phpType]`) plus an
     * entity's values into a Zoho payload. Numbers and dates go through the caster because
     * settype('$50,000', 'double') is 0.0 and Carbon::parse(null) is today — both send Zoho a value
     * that is wrong rather than absent; the caster yields null instead and the field is skipped.
     */
    public static function map(array $map, array $entity): array
    {
        $data = [];

        foreach ($map as $key => $name) {
            $value = $entity[$key] ?? null;

            if (is_array($name)) {
                $type = (string) $name['type'];

                $value = $type === 'date' || ZohoFieldTypeService::isNumericType($type)
                    ? ZohoFieldTypeService::cast($value, $type)
                    : self::castScalar($value, $type);

                $name = $name['name'];
            }

            if (strtolower((string) $key) === 'credit_score' && $value != null) {
                $value = self::CREDIT_SCORES[(int) $value] ?? $value;
            }

            if (strtolower((string) $key) === 'subid' && $value != null) {
                $value = (string) $value;
            }

            if ($value !== null) {
                $data[$name] = $value;
            }
        }

        return $data;
    }

    /**
     * The deal map is stored inbound-first (`zohoKey => kanvasKey`) because the webhook came first.
     * The push reads the same setting the other way round so one config drives both directions.
     */
    public static function invert(array $map): array
    {
        $inverted = [];

        foreach ($map as $zohoKey => $kanvasKey) {
            if (is_array($kanvasKey)) {
                if (empty($kanvasKey['name'])) {
                    continue;
                }

                $inverted[(string) $kanvasKey['name']] = ['name' => $zohoKey] + array_diff_key($kanvasKey, ['name' => true]);

                continue;
            }

            if (is_string($kanvasKey) && $kanvasKey !== '') {
                $inverted[$kanvasKey] = $zohoKey;
            }
        }

        return $inverted;
    }

    /**
     * The company's ZOHO_DEAL_FIELDS_MAP, inbound-first (`zohoKey => kanvasKey`), or the default.
     */
    public static function dealMapFor(CompanyInterface $company): array
    {
        $map = $company->get(CustomFieldEnum::ZOHO_DEAL_FIELDS_MAP->value);

        return is_array($map) && $map !== [] ? $map : self::defaultDealMap();
    }

    /**
     * @return array<string, string> zohoKey => kanvasKey
     */
    public static function defaultDealMap(): array
    {
        return [
            'Business_Name' => 'business_legal_name',
            'DBA' => 'business_dba',
            'EIN' => 'business_ein',
            'Monthly_Business_Revenue' => 'business_stated_monthly_revenue',
            'Annual_Revenue' => 'funding_estimated_annual_revenue',
            'Amount_Requested' => 'funding_amount_requested',
            'Business_Start_Date' => 'business_start_date',
            'Employer_Address' => 'business_address_line',
            'City' => 'business_city',
            'State' => 'business_state',
            'Zip_Code' => 'business_zip',
        ];
    }

    private static function castScalar(mixed $value, string $type): mixed
    {
        settype($value, $type);

        return $value;
    }
}
