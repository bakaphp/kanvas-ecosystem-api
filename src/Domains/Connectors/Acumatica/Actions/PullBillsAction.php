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
use Kanvas\Connectors\Acumatica\Traits\ResolvesAcumaticaGlCoding;
use Kanvas\Connectors\Acumatica\Traits\ResolvesAcumaticaParty;
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
    use ResolvesAcumaticaGlCoding;
    use ResolvesAcumaticaParty;

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
        $headers = $this->fetchHeaders();

        $refNbrs = array_values(array_unique(array_filter(array_map(
            static fn (array $h): string => trim((string) ($h['RefNbr'] ?? '')),
            $headers
        ))));

        return $this->processRows($headers, $this->fetchLines($refNbrs));
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
            // AP vendor bill = DocType 'INV' (Acumatica stores it as INV, not the display label
            // "Bill"); 'ACR' = credit adjustment.
            ->whereIn('r.DocType', ['INV', 'ACR'])
            ->select([
                'r.DocType', 'r.RefNbr', 'b.AcctCD', 'r.DocDate', 'i.DueDate as DueDate',
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
     * Per-line GL coding for the bills being imported: APTran distribution rows joined to Account
     * (AccountCD) and Sub (SubCD). Grouped by "TranType|RefNbr" so each header picks up exactly its
     * own lines. Tax lives on separate tax rows, so these sum to the pre-tax subtotal — the header
     * delta is reconciled in buildLines.
     *
     * @param array<int, string> $refNbrs
     *
     * @return array<string, array<int, array<array-key, mixed>>>
     */
    protected function fetchLines(array $refNbrs): array
    {
        if ($refNbrs === []) {
            return [];
        }

        $rows = SqlClient::connection($this->app)
            ->table('APTran as t')
            ->join('Account as a', function (JoinClause $join): void {
                $join->on('a.AccountID', '=', 't.AccountID')
                    ->on('a.CompanyID', '=', 't.CompanyID');
            })
            ->leftJoin('Sub as s', function (JoinClause $join): void {
                $join->on('s.SubID', '=', 't.SubID')
                    ->on('s.CompanyID', '=', 't.CompanyID');
            })
            ->where('t.CompanyID', $this->acumaticaCompanyId)
            ->whereIn('t.RefNbr', $refNbrs)
            ->select([
                't.TranType', 't.RefNbr', 'a.AccountCD', 's.SubCD',
                't.Qty', 't.CuryUnitCost', 't.CuryTranAmt', 't.TranDesc',
            ])
            ->orderBy('t.LineNbr')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = trim((string) $row->TranType) . '|' . trim((string) $row->RefNbr);
            $grouped[$key][] = (array) $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<array-key, mixed>>                $headers
     * @param array<string, array<int, array<array-key, mixed>>> $linesByDoc
     */
    public function processRows(array $headers, array $linesByDoc = []): int
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

            $organization = $this->resolveOrganization($bill->vendorCode, CustomFieldEnum::VENDOR_ID->value, true);

            if ($organization === null) {
                $this->skipped['vendor_not_in_kanvas']++;

                continue;
            }

            $key = trim((string) ($header['DocType'] ?? '')) . '|' . trim((string) ($header['RefNbr'] ?? ''));

            $billModel = new ImportBillFromExternalAction(
                data: new BillData(
                    app: $this->app,
                    company: $this->company,
                    vendor: $organization,
                    lines: new DataCollection(
                        BillLineData::class,
                        $this->buildLines($linesByDoc[$key] ?? [], $bill)
                    ),
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

    /**
     * Build the Kanvas bill lines from the ERP's APTran distribution rows, each carrying its
     * expense account + subaccount so Kanvas mirrors the source GL coding. Falls back to a single
     * header-total line when the replica has no line detail. A final "Tax & adjustments" line
     * reconciles any header/line delta (tax rows are separate) so the imported total is exact.
     *
     * @param array<int, array<array-key, mixed>> $rawLines
     *
     * @return array<int, BillLineData>
     */
    private function buildLines(array $rawLines, AcumaticaImportBill $bill): array
    {
        if ($rawLines === []) {
            return [new BillLineData(
                description: $bill->memo ?? ('Acumatica ' . $bill->externalId),
                quantity: 1,
                unit_price_native: $bill->total,
            )];
        }

        $lines = [];
        $sum = 0.0;

        foreach ($rawLines as $row) {
            $qty = (float) ($row['Qty'] ?? 0);
            $unit = (float) ($row['CuryUnitCost'] ?? 0);
            $amount = (float) ($row['CuryTranAmt'] ?? 0);

            // Service / fee rows carry no quantity — represent them as one line at the row amount.
            if ($qty === 0.0) {
                $qty = 1.0;
                $unit = $amount;
            }

            $subCode = trim((string) ($row['SubCD'] ?? ''));

            $lines[] = new BillLineData(
                description: trim((string) ($row['TranDesc'] ?? '')) ?: ('Acumatica ' . $bill->refNbr),
                quantity: $qty,
                unit_price_native: $unit,
                expense_account_id: $this->resolveAccountId(trim((string) ($row['AccountCD'] ?? ''))),
                subaccount_id: $this->resolveSubaccountId($subCode !== '' ? $subCode : null),
            );

            $sum += $qty * $unit;
        }

        $delta = round($bill->total - $sum, 4);

        if (abs($delta) >= 0.005) {
            $lines[] = new BillLineData(
                description: 'Tax & adjustments',
                quantity: 1,
                unit_price_native: $delta,
            );
        }

        return $lines;
    }
}
