<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Users\Models\Users;

class PullCustomersAction
{
    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected int $acumaticaCompanyId,
        protected bool $isVendor = false,
        protected ?int $limit = null,
        protected ?Carbon $modifiedSince = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchRows());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchRows(): array
    {
        $table = $this->isVendor ? 'Vendor' : 'Customer';

        $query = SqlClient::connection($this->app)
                ->table($table . ' as p')
                ->join('BAccount as b', function (JoinClause $join): void {
                    $join->on('b.BAccountID', '=', 'p.BAccountID')
                        ->on('b.CompanyID', '=', 'p.CompanyID');
                })
                ->leftJoin('Contact as c', function (JoinClause $join): void {
                    $join->on('c.ContactID', '=', 'b.DefContactID')
                        ->on('c.CompanyID', '=', 'b.CompanyID');
                })
                ->leftJoin('Address as a', function (JoinClause $join): void {
                    $join->on('a.AddressID', '=', 'b.DefAddressID')
                        ->on('a.CompanyID', '=', 'b.CompanyID');
                })
                ->where('p.CompanyID', $this->acumaticaCompanyId)
                ->select([
                    'b.AcctCD', 'b.AcctName', 'b.NoteID',
                    'c.FirstName', 'c.LastName', 'c.EMail', 'c.Phone1',
                    'a.AddressLine1', 'a.AddressLine2', 'a.City', 'a.State',
                    'a.CountryID', 'a.PostalCode', 'a.Latitude', 'a.Longitude',
                ]);

        if ($this->modifiedSince !== null) {
            $query->where('b.LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(
            fn ($row): array => (array) $row,
            $query->get()->all()
        );
    }

    /**
     * @param array<int, array<array-key, mixed>> $rows
     */
    public function processRows(array $rows): int
    {
        $create = new CreateAcumaticaPersonAction($this->app, $this->company, $this->user);
        $count = 0;

        foreach ($rows as $row) {
            if ($create->execute($row, $this->isVendor) !== null) {
                $count++;
            }
        }

        return $count;
    }
}
