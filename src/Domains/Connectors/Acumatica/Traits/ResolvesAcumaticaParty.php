<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Traits;

use Illuminate\Database\Query\JoinClause;
use Kanvas\Connectors\Acumatica\Actions\CreateAcumaticaOrganizationAction;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Guild\Organizations\Models\Organization;

/**
 * Resolves an Acumatica BAccount code (vendor or customer) to a Guild Organization, creating it from
 * the source party row when it doesn't exist yet — so an imported document always has a counterparty.
 *
 * The using action must expose `app`, `company`, `user` and `acumaticaCompanyId`.
 */
trait ResolvesAcumaticaParty
{
    private function resolveOrganization(string $acctCd, string $customFieldKey, bool $isVendor): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = Organization::getByCustomField($customFieldKey, $acctCd, $this->company);

        if ($organization !== null) {
            return $organization;
        }

        $row = $this->fetchPartyRow($acctCd);

        if ($row === null) {
            return null;
        }

        return new CreateAcumaticaOrganizationAction(
            $this->app,
            $this->company,
            $this->user
        )->execute($row, isVendor: $isVendor);
    }

    /**
     * @return array<array-key, mixed>|null
     */
    private function fetchPartyRow(string $acctCd): ?array
    {
        $row = SqlClient::connection($this->app)
            ->table('BAccount as b')
            ->leftJoin('Contact as c', function (JoinClause $join): void {
                $join->on('c.ContactID', '=', 'b.DefContactID')
                    ->on('c.CompanyID', '=', 'b.CompanyID');
            })
            ->leftJoin('Address as a', function (JoinClause $join): void {
                $join->on('a.AddressID', '=', 'b.DefAddressID')
                    ->on('a.CompanyID', '=', 'b.CompanyID');
            })
            ->where('b.CompanyID', $this->acumaticaCompanyId)
            ->where('b.AcctCD', $acctCd)
            ->select([
                'b.AcctCD', 'b.AcctName', 'b.NoteID',
                'c.FirstName', 'c.LastName', 'c.EMail', 'c.Phone1',
                'a.AddressLine1', 'a.AddressLine2', 'a.City', 'a.State',
                'a.CountryID', 'a.PostalCode', 'a.Latitude', 'a.Longitude',
            ])
            ->first();

        return $row !== null ? (array) $row : null;
    }
}
