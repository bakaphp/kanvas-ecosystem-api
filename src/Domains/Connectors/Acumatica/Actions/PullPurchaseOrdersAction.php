<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportPurchaseOrder;
use Kanvas\Connectors\Acumatica\DataTransferObject\AcumaticaImportPurchaseOrderLine;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Inventory\Variants\Models\Variants;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrder;
use Kanvas\Scribe\Purchasing\Models\PurchaseOrderLine;
use Kanvas\Users\Models\Users;

/**
 * Pull open Acumatica purchase orders (dbo.POOrder + dbo.POLine) into Scribe's PO read-mirror, so
 * the AP-bill agent can match an incoming invoice to its PO and inherit the line's GL coding.
 *
 * Closed POs ('C') are excluded — a new invoice can't match an already-closed order. The vendor is
 * linked to a Guild Organization when one exists (create-if-missing is handled by the vendor pull);
 * line account/subaccount/variant resolve to Kanvas ids when the referenced record is synced.
 */
class PullPurchaseOrdersAction
{
    /** @var array<string, int> per-run skip breakdown */
    public array $skipped = [];

    /** @var array<string, int|null> */
    private array $accountCache = [];

    /** @var array<string, int|null> */
    private array $subaccountCache = [];

    /** @var array<string, int|null> */
    private array $variantCache = [];

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
        $headers = $this->fetchHeaders();

        $orderNbrs = array_values(array_unique(array_filter(array_map(
            static fn (array $h): string => trim((string) ($h['OrderNbr'] ?? '')),
            $headers
        ))));

        return $this->processRows(
            $headers,
            $this->fetchLinesByOrder($orderNbrs)
        );
    }

    /**
     * @return array<int, array<array-key, mixed>>
     */
    protected function fetchHeaders(): array
    {
        $query = SqlClient::connection($this->app)
            ->table('POOrder as o')
            ->leftJoin('BAccount as b', function (JoinClause $join): void {
                $join->on('b.BAccountID', '=', 'o.VendorID')
                    ->on('b.CompanyID', '=', 'o.CompanyID');
            })
            ->where('o.CompanyID', $this->acumaticaCompanyId)
            ->where('o.Status', '!=', 'C')
            ->select([
                'o.OrderType', 'o.OrderNbr', 'b.AcctCD', 'o.Status',
                'o.OrderDate', 'o.CuryID', 'o.CuryOrderTotal', 'o.OrderTotal',
            ])
            ->orderByDesc('o.OrderDate');

        if ($this->modifiedSince !== null) {
            $query->where('o.LastModifiedDateTime', '>', $this->modifiedSince);
        }

        if ($this->limit !== null) {
            $query->limit($this->limit);
        }

        return array_map(fn ($row): array => (array) $row, $query->get()->all());
    }

    /**
     * @param array<int, string> $orderNbrs
     *
     * @return array<string, array<int, array<array-key, mixed>>> keyed by "OrderType-OrderNbr"
     */
    protected function fetchLinesByOrder(array $orderNbrs): array
    {
        if ($orderNbrs === []) {
            return [];
        }

        $rows = SqlClient::connection($this->app)
            ->table('POLine as l')
            ->leftJoin('InventoryItem as i', function (JoinClause $join): void {
                $join->on('i.InventoryID', '=', 'l.InventoryID')
                    ->on('i.CompanyID', '=', 'l.CompanyID');
            })
            ->leftJoin('Account as a', function (JoinClause $join): void {
                $join->on('a.AccountID', '=', 'l.ExpenseAcctID')
                    ->on('a.CompanyID', '=', 'l.CompanyID');
            })
            ->leftJoin('Sub as s', function (JoinClause $join): void {
                $join->on('s.SubID', '=', 'l.ExpenseSubID')
                    ->on('s.CompanyID', '=', 'l.CompanyID');
            })
            ->where('l.CompanyID', $this->acumaticaCompanyId)
            ->whereIn('l.OrderNbr', $orderNbrs)
            ->select([
                'l.OrderType', 'l.OrderNbr', 'l.LineNbr', 'i.InventoryCD as sku', 'l.TranDesc',
                'a.AccountCD', 's.SubCD',
                'l.OrderQty', 'l.OpenQty', 'l.ReceivedQty', 'l.CuryUnitCost', 'l.CuryExtCost',
            ])
            ->orderBy('l.LineNbr')
            ->get();

        $grouped = [];

        foreach ($rows as $row) {
            $key = trim((string) $row->OrderType) . '-' . trim((string) $row->OrderNbr);
            $grouped[$key][] = (array) $row;
        }

        return $grouped;
    }

    /**
     * @param array<int, array<array-key, mixed>>                $headers
     * @param array<string, array<int, array<array-key, mixed>>> $linesByOrder
     */
    public function processRows(array $headers, array $linesByOrder): int
    {
        $count = 0;
        $this->skipped = ['no_number' => 0];

        foreach ($headers as $header) {
            $po = AcumaticaImportPurchaseOrder::from(
                $header,
                $linesByOrder[$header['OrderType'] . '-' . $header['OrderNbr']] ?? []
            );

            if ($po->orderNumber === '') {
                $this->skipped['no_number']++;

                continue;
            }

            $this->upsert($po);
            $count++;
        }

        return $count;
    }

    private function upsert(AcumaticaImportPurchaseOrder $po): void
    {
        /** @var PurchaseOrder|null $existing */
        $existing = PurchaseOrder::query()
            ->where('apps_id', $this->app->getId())
            ->where('companies_id', $this->company->getId())
            ->where('order_type', $po->orderType)
            ->where('order_number', $po->orderNumber)
            ->first();

        $model = $existing ?? new PurchaseOrder();

        if ($existing === null) {
            $model->apps_id = $this->app->getId();
            $model->companies_id = $this->company->getId();
            $model->order_type = $po->orderType;
            $model->order_number = $po->orderNumber;
            $model->source = AcumaticaImportPurchaseOrder::SOURCE;
            $model->external_id = $po->externalId;
            $model->users_id = $this->user->getId();
        }

        $model->vendor_code = $po->vendorCode !== '' ? $po->vendorCode : null;
        $model->vendor_organization_id = $this->resolveVendorId($po->vendorCode);
        $model->status = $po->status;
        $model->order_date = $po->orderDate;
        $model->currency = $po->currency;
        $model->order_total = $po->orderTotal;
        $model->last_synced_at = Carbon::now();
        $model->save();

        $this->replaceLines($model, $po->lines->toCollection()->all());
    }

    /**
     * @param array<int, AcumaticaImportPurchaseOrderLine> $lines
     */
    private function replaceLines(PurchaseOrder $po, array $lines): void
    {
        PurchaseOrderLine::query()->where('purchase_order_id', $po->getId())->delete();

        foreach ($lines as $line) {
            $row = new PurchaseOrderLine();
            $row->purchase_order_id = $po->getId();
            $row->line_number = $line->lineNumber;
            $row->sku = $line->sku;
            $row->description = $line->description;
            $row->inventory_variant_id = $line->sku !== null ? $this->resolveVariantId($line->sku) : null;
            $row->expense_account_id = $line->accountCd !== null ? $this->resolveAccountId($line->accountCd) : null;
            $row->subaccount_id = $line->subCode !== null ? $this->resolveSubaccountId($line->subCode) : null;
            $row->order_qty = $line->orderQty;
            $row->open_qty = $line->openQty;
            $row->received_qty = $line->receivedQty;
            $row->unit_cost = $line->unitCost;
            $row->ext_cost = $line->extCost;
            $row->save();
        }
    }

    private function resolveVendorId(string $vendorCode): ?int
    {
        if ($vendorCode === '') {
            return null;
        }

        /** @var Organization|null $org */
        $org = Organization::getByCustomField(CustomFieldEnum::VENDOR_ID->value, $vendorCode, $this->company);

        return $org?->getId();
    }

    private function resolveAccountId(string $accountCd): ?int
    {
        return $this->accountCache[$accountCd] ??= (function () use ($accountCd): ?int {
            $id = Account::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('account_number', $accountCd)
                ->where('is_deleted', false)
                ->value('id');

            return $id !== null ? (int) $id : null;
        })();
    }

    private function resolveSubaccountId(string $subCode): ?int
    {
        return $this->subaccountCache[$subCode] ??= (function () use ($subCode): ?int {
            $id = Subaccount::query()
                ->where('apps_id', $this->app->getId())
                ->where('companies_id', $this->company->getId())
                ->where('sub_code', $subCode)
                ->where('is_deleted', false)
                ->value('id');

            return $id !== null ? (int) $id : null;
        })();
    }

    private function resolveVariantId(string $sku): ?int
    {
        return $this->variantCache[$sku] ??= (function () use ($sku): ?int {
            $id = Variants::query()
                ->fromApp($this->app)
                ->fromCompany($this->company)
                ->where('sku', $sku)
                ->notDeleted()
                ->value('id');

            return $id !== null ? (int) $id : null;
        })();
    }
}
