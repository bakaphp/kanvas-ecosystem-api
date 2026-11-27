<?php

declare(strict_types=1);

namespace Kanvas\Connectors\NetSuite\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\NetSuite\Client;
use NetSuite\Classes\LocationSearch;
use NetSuite\Classes\LocationSearchBasic;
use NetSuite\Classes\RecordRef;
use NetSuite\Classes\SearchBooleanField;
use NetSuite\Classes\SearchMultiSelectField;
use NetSuite\Classes\SearchMultiSelectFieldOperator;

class NetSuiteLocationService
{
    protected Client $client;

    public function __construct(protected AppInterface $app, protected CompanyInterface $company)
    {
        $this->client = new Client($app, $company);
    }

    /**
     * Join criteria restricting an item search to these locations.
     *
     * @param list<string|int> $locationIds
     */
    public static function joinFilter(array $locationIds): LocationSearchBasic
    {
        $filter = new LocationSearchBasic();
        $filter->internalId = new SearchMultiSelectField();
        $filter->internalId->operator = SearchMultiSelectFieldOperator::anyOf;
        $filter->internalId->searchValue = array_map(function (string|int $locationId): RecordRef {
            $ref = new RecordRef();
            $ref->internalId = (string) $locationId;

            return $ref;
        }, $locationIds);

        return $filter;
    }

    /**
     * `suggested` is only a hint for the picker. Neither NetSuite flag alone says "a warehouse we
     * sell from": a real account flags In Transit and Other as available inventory, and types a
     * warehouse it holds no available stock in. Both together are the closest guess; the user decides.
     *
     * @return list<array{id: string, name: string, type: ?string, inventory_available: bool, suggested: bool}>
     */
    public function active(): array
    {
        $basic = new LocationSearchBasic();
        $basic->isInactive = new SearchBooleanField();
        $basic->isInactive->searchValue = false;

        $search = new LocationSearch();
        $search->basic = $basic;

        return array_map(function (object $location): array {
            $type = $location->locationType !== null ? ltrim((string) $location->locationType, '_') : null;
            $available = (bool) $location->makeInventoryAvailable;

            return [
                'id' => (string) $location->internalId,
                'name' => (string) $location->name,
                'type' => $type,
                'inventory_available' => $available,
                'suggested' => $type === 'warehouse' && $available,
            ];
        }, $this->client->searchAll($search));
    }
}
