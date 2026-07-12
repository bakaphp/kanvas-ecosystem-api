<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportBill;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Actions\ImportBillFromExternalAction;
use Kanvas\Scribe\Bills\DataTransferObject\Bill as BillData;
use Kanvas\Scribe\Bills\DataTransferObject\BillLine as BillLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

/**
 * Pull released Acumatica AP documents (dbo.APRegister) into Scribe as external bills for AP aging.
 * The vendor is mapped to a Guild Organization (create-if-missing), the document lands via
 * ImportBillFromExternalAction (terminal state, no JE — the GL was imported separately).
 */
class PullBillsAction
{
    /** @var array<string, int> per-run skip breakdown */
    public array $skipped = [];

    public function __construct(
        protected Apps $app,
        protected Companies $company,
        protected Users $user,
        protected int $acumaticaCompanyId,
        protected ?int $limit = null,
        protected ?Carbon $modifiedSince = null,
    ) {
    }

    public function execute(): int
    {
        return $this->processRows($this->fetchHeaders());
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchHeaders(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('APRegister as r')
            ->leftJoin('BAccount as b', function (JoinClause $join): void {
                $join->on('b.BAccountID', '=', 'r.VendorID')
                    ->on('b.CompanyID', '=', 'r.CompanyID');
            })
            // APRegister has no DueDate — the AP due date lives on APInvoice.
            ->leftJoin('APInvoice as i', function (JoinClause $join): void {
                $join->on('i.DocType', '=', 'r.DocType')
                    ->on('i.RefNbr', '=', 'r.RefNbr')
                    ->on('i.CompanyID', '=', 'r.CompanyID');
            })
            ->where('r.CompanyID', $this->acumaticaCompanyId)
            ->where('r.Released', 1)
            ->whereIn('r.DocType', ['BIL', 'ACR'])
            ->select([
                'r.DocType', 'r.RefNbr', 'b.AcctCD', 'r.DocDate', 'i.DueDate as DueDate',
                'r.CuryID', 'r.CuryOrigDocAmt', 'r.CuryDocBal', 'r.DocDesc',
            ])
            ->orderByDesc('r.DocDate');

        if ($this->modifiedSince !== null) {
            $query->where('r.LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, array<array-key, mixed>> $headers
     */
    public function processRows(array $headers): int
    {
        $count = 0;
        $this->skipped = [
            'no_ref' => 0,
            'no_vendor_code' => 0,
            'vendor_not_in_kanvas' => 0,
        ];

        foreach ($headers as $header) {
            $bill = AcumaticaImportBill::from($header);

            if ($bill->refNbr === '') {
                $this->skipped['no_ref']++;

                continue;
            }

            if ($bill->vendorCode === '') {
                $this->skipped['no_vendor_code']++;

                continue;
            }

            $organization = $this->ensureOrganization($bill->vendorCode);

            if ($organization === null) {
                $this->skipped['vendor_not_in_kanvas']++;

                continue;
            }

            $billModel = new ImportBillFromExternalAction(
                data: new BillData(
                    app: $this->app,
                    company: $this->company,
                    vendor: $organization,
                    lines: new DataCollection(BillLineData::class, [
                        new BillLineData(
                            description: $bill->memo ?? ('Acumatica ' . $bill->externalId),
                            quantity: 1,
                            unit_price_native: $bill->total,
                        ),
                    ]),
                    currency: $bill->currency,
                    fx_rate_to_base: 1.0,
                    bill_number: $bill->refNbr,
                    bill_date: $bill->billDate,
                    due_date: $bill->dueDate,
                    source: 'acumatica',
                    external_id: $bill->externalId,
                    origin: JournalEntryOriginEnum::EXTERNAL,
                ),
                paidNative: $bill->paid,
                user: $this->user,
            )->execute();

            if ($bill->externalId !== '') {
                $billModel->set(CustomFieldEnum::BILL_ID->value, $bill->externalId);
            }

            $count++;
        }

        return $count;
    }

    private function ensureOrganization(string $acctCd): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = Organization::getByCustomField(CustomFieldEnum::VENDOR_ID->value, $acctCd, $this->company);

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
        )->execute($row, isVendor: true);
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
