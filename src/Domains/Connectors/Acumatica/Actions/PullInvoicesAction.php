<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportInvoice;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Actions\ImportInvoiceFromExternalAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Ledger\Enums\JournalEntryOriginEnum;
use Kanvas\Users\Models\Users;
use Spatie\LaravelData\DataCollection;

/**
 * Pull released Acumatica AR documents (dbo.ARRegister) into Scribe as external invoices for AR
 * aging. The customer is mapped to a Guild Organization (create-if-missing), the document lands via
 * ImportInvoiceFromExternalAction (terminal state, no JE — the GL was imported separately).
 */
class PullInvoicesAction
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
        protected ?string $ref = null,
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
            ->table('ARRegister as r')
            ->leftJoin('BAccount as b', function (JoinClause $join): void {
                $join->on('b.BAccountID', '=', 'r.CustomerID')
                    ->on('b.CompanyID', '=', 'r.CompanyID');
            })
            ->where('r.CompanyID', $this->acumaticaCompanyId)
            ->where('r.Released', 1)
            ->whereIn('r.DocType', ['INV', 'CRM'])
            ->select([
                'r.DocType', 'r.RefNbr', 'b.AcctCD', 'r.DocDate', 'r.DueDate',
                'r.CuryID', 'r.CuryOrigDocAmt', 'r.CuryDocBal', 'r.DocDesc',
            ])
            ->orderByDesc('r.DocDate');

        if ($this->ref !== null && $this->ref !== '') {
            $query->where('r.RefNbr', $this->ref);
        }

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
            'no_customer_code' => 0,
            'customer_not_in_kanvas' => 0,
        ];

        foreach ($headers as $header) {
            $invoice = AcumaticaImportInvoice::from($header);

            if ($invoice->refNbr === '') {
                $this->skipped['no_ref']++;

                continue;
            }

            if ($invoice->customerCode === '') {
                $this->skipped['no_customer_code']++;

                continue;
            }

            $organization = $this->ensureOrganization($invoice->customerCode);

            if ($organization === null) {
                $this->skipped['customer_not_in_kanvas']++;

                continue;
            }

            new ImportInvoiceFromExternalAction(
                data: new InvoiceData(
                    app: $this->app,
                    company: $this->company,
                    billable: $organization,
                    lines: new DataCollection(InvoiceLineData::class, [
                        new InvoiceLineData(
                            description: $invoice->memo ?? ('Acumatica ' . $invoice->externalId),
                            quantity: 1,
                            unit_price_native: $invoice->total,
                        ),
                    ]),
                    currency: $invoice->currency,
                    fx_rate_to_base: 1.0,
                    document_type: $invoice->documentType,
                    invoice_number: $invoice->refNbr,
                    issued_date: $invoice->issuedDate,
                    due_date: $invoice->dueDate,
                    source: 'acumatica',
                    external_id: $invoice->externalId,
                    origin: JournalEntryOriginEnum::EXTERNAL,
                ),
                paidNative: $invoice->paid,
                user: $this->user,
            )->execute();

            $count++;
        }

        return $count;
    }

    private function ensureOrganization(string $acctCd): ?Organization
    {
        /** @var Organization|null $organization */
        $organization = Organization::getByCustomField(CustomFieldEnum::CUSTOMER_ID->value, $acctCd, $this->company);

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
        )->execute($row);
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
