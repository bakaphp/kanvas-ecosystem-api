<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Salesforce\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Salesforce\Client;
use Kanvas\Connectors\Salesforce\Services\SalesforceApiClient;
use Kanvas\Inventory\Variants\Models\Variants;

/**
 * Pulls one Location__c into Kanvas as a Variant of its Project__c's Product — the same shape the
 * bulk import produces (see ImportSalesforcePropertiesCommand). Never as a Product of its own: a SKU
 * is looked up company-wide, so a location's variant living anywhere else captures every later
 * import of that location and it never reaches its project.
 */
class PullPropertyAction
{
    public const string LOCATION_FIELDS = 'Id, Name, Parent_Deal__c, Property_Name__c, Deal_Status__c, '
        . 'Marketing_Status__c, Store__c, Street__c, City__c, State_Province__c, Zip_Code__c, Brand__c, '
        . 'Ask_Deal_Type__c, Location_Type__c, Gross_SF__c, Property_Acreage__c, Year_Built__c, Zoning__c, '
        . 'Latitude__c, Longitude__c';

    private const string SKU_PREFIX = 'sf-location-';

    public function __construct(
        protected AppInterface $app,
        protected CompanyInterface $company,
        protected UserInterface $user,
        protected string $salesforceId,
    ) {
    }

    /**
     * An Outbound Message carries only the fields picked in Salesforce Setup, which need not include
     * Parent_Deal__c — so the row is re-read here, giving the webhook the same fields as the import.
     */
    public function execute(): ?Variants
    {
        SalesforceApiClient::assertValidId($this->salesforceId);

        $location = Client::getInstance($this->app, $this->company)->query(
            'SELECT ' . self::LOCATION_FIELDS . ', Parent_Deal__r.Name FROM Location__c '
            . "WHERE Id = '{$this->salesforceId}' LIMIT 1"
        )['records'][0] ?? null;

        $projectSalesforceId = (string) ($location['Parent_Deal__c'] ?? '');

        if ($projectSalesforceId === '') {
            return null;
        }

        $product = new PullProjectAction(
            $this->app,
            $this->company,
            $this->user,
            ['Name' => $location['Parent_Deal__r']['Name'] ?? null],
            $projectSalesforceId,
        )->execute([self::variantFromPayload($location)]);

        return Variants::query()
            ->where('products_id', $product->getId())
            ->where('sku', self::skuFor((string) $location['Id']))
            ->first();
    }

    public static function skuFor(string $salesforceId): string
    {
        return self::SKU_PREFIX . $salesforceId;
    }

    public static function salesforceIdFromSku(?string $sku): ?string
    {
        return $sku !== null && str_starts_with($sku, self::SKU_PREFIX)
            ? substr($sku, strlen(self::SKU_PREFIX))
            : null;
    }

    /**
     * @return array{name: string, description: string, sku: string, attributes: array<int, array{name: string, value: mixed}>}
     */
    public static function variantFromPayload(array $location): array
    {
        return [
            'name' => (string) ($location['Property_Name__c'] ?? $location['Name'] ?? 'Unknown Property'),
            'description' => (string) ($location['Brand__c'] ?? ''),
            'sku' => self::skuFor((string) $location['Id']),
            'attributes' => self::attributesFromPayload($location),
        ];
    }

    /**
     * @return array<int, array{name: string, value: mixed}>
     */
    public static function attributesFromPayload(array $payload): array
    {
        $map = [
            'Deal Status' => 'Deal_Status__c',
            'Marketing Status' => 'Marketing_Status__c',
            'Store Number' => 'Store__c',
            'Building Type' => 'Location_Type__c',
            'Building Size' => 'Gross_SF__c',
            'Acreage' => 'Property_Acreage__c',
            'Year Built' => 'Year_Built__c',
            'Zoning' => 'Zoning__c',
            'Offering' => 'Ask_Deal_Type__c',
            'Street' => 'Street__c',
            'City' => 'City__c',
            'State Province' => 'State_Province__c',
            'Zip Code' => 'Zip_Code__c',
            'Latitude' => 'Latitude__c',
            'Longitude' => 'Longitude__c',
        ];

        $attributes = [];
        foreach ($map as $attributeName => $salesforceField) {
            $value = $payload[$salesforceField] ?? null;
            if ($value === null || $value === '') {
                continue;
            }
            $attributes[] = ['name' => $attributeName, 'value' => $value];
        }

        return $attributes;
    }
}
