<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Intras\Actions;

use Kanvas\Connectors\Intras\Client;
use Kanvas\Connectors\Intras\Enums\SipgoEntityEnum;
use Kanvas\Connectors\Intras\Mappers\OrganizationMapper;
use Kanvas\Connectors\Intras\Support\SipgoCatalog;
use Kanvas\Connectors\Intras\Support\SipgoChangeSet;
use Kanvas\Guild\Organizations\Models\Organization;

class DiffOrganizationWithIntrasAction
{
    public function __construct(
        protected Organization $organization,
    ) {
    }

    /**
     * One `company` item carrying only what differs, or an empty list when SIPGO matches. Null when
     * the Organization is not linked to a SIPGO company, or that row no longer exists. Throws
     * ValidationException when a catalog name cannot be resolved.
     *
     * @return list<array{kind: string, legacy_id: int, changes: array}>|null
     */
    public function execute(): ?array
    {
        $companyId = OrganizationMapper::companyId($this->organization);

        if ($companyId === null) {
            return null;
        }

        $client = new Client($this->organization->app);
        $row = $client->table('companies')->where('id', $companyId)->first();

        if ($row === null) {
            return null;
        }

        $current = PullOrganizationsFromIntrasAction::loadCompanyCustomFields($client, [$companyId])[$companyId] ?? [];
        $changes = SipgoChangeSet::build(
            OrganizationMapper::toIntras($this->organization),
            $row,
            $current,
            new SipgoCatalog($client),
        );

        return $changes === [] ? [] : [SipgoEntityEnum::COMPANY->item($companyId, $changes)];
    }
}
