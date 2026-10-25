<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Zoho\Services;

use Baka\Contracts\CompanyInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
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
     * The pull direction of the same map: Zoho record values keyed by the Kanvas custom field they
     * belong to. Mirrors the lead pull exactly — table columns are never written as custom fields,
     * empty Zoho values are left alone rather than wiping what Kanvas has, and `date` entries are
     * normalised to Y-m-d.
     *
     * @param array<string, string|array> $map kanvasKey => zohoKey | ['name' => zohoKey, 'type' => ...]
     * @param array<string, mixed> $record the Zoho record as data
     * @param list<string> $reservedKeys Kanvas keys that are table columns, not custom fields
     * @return array<string, mixed>
     */
    public static function extract(array $map, array $record, array $reservedKeys = []): array
    {
        $customFields = [];

        foreach ($map as $kanvasKey => $zohoConfig) {
            if (! is_string($kanvasKey) || in_array($kanvasKey, $reservedKeys, true)) {
                continue;
            }

            $value = $record[self::zohoFieldName($zohoConfig, $kanvasKey)] ?? null;

            if (empty($value)) {
                continue;
            }

            if (is_array($zohoConfig) && ($zohoConfig['type'] ?? null) === 'date') {
                $value = ZohoFieldTypeService::cast($value, 'date') ?? $value;
            }

            $customFields[$kanvasKey] = $value;
        }

        return $customFields;
    }

    /**
     * A map entry is either the Zoho api name or `['name' => ..., 'type' => ...]`; an empty one
     * means the Kanvas key is already the Zoho name.
     */
    public static function zohoFieldName(mixed $config, string $fallback): string
    {
        $name = is_array($config) ? ($config['name'] ?? null) : $config;

        return is_string($name) && $name !== '' ? $name : $fallback;
    }

    /**
     * @return list<string>
     */
    public static function tableColumns(Model $model): array
    {
        $connectionName = $model->getConnection()->getName();
        $table = $model->getTable();

        return Cache::remember(
            "zoho_table_columns_{$connectionName}_{$table}",
            3600,
            fn () => Schema::connection($connectionName)->getColumnListing($table)
        );
    }

    /**
     * The company's ZOHO_DEAL_FIELDS_MAP or the default. Same shape as the lead map, Kanvas-first:
     * `kanvasKey => zohoKey` or `kanvasKey => ['name' => zohoKey, 'type' => phpType]`.
     */
    public static function dealMapFor(CompanyInterface $company): array
    {
        $map = $company->get(CustomFieldEnum::ZOHO_DEAL_FIELDS_MAP->value);

        return is_array($map) && $map !== [] ? $map : self::defaultDealMap();
    }

    /**
     * @return array<string, string> kanvasKey => zohoKey
     */
    public static function defaultDealMap(): array
    {
        return [
            'business_legal_name' => 'Business_Name',
            'business_dba' => 'DBA',
            'business_ein' => 'EIN',
            'business_stated_monthly_revenue' => 'Monthly_Business_Revenue',
            'funding_estimated_annual_revenue' => 'Annual_Revenue',
            'funding_amount_requested' => 'Amount_Requested',
            'business_start_date' => 'Business_Start_Date',
            'business_address_line' => 'Employer_Address',
            'business_city' => 'City',
            'business_state' => 'State',
            'business_zip' => 'Zip_Code',
        ];
    }

    private static function castScalar(mixed $value, string $type): mixed
    {
        settype($value, $type);

        return $value;
    }
}
