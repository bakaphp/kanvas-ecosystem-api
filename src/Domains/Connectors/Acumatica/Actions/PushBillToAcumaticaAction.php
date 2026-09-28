<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Baka\Http\SafeUrlFetcher;
use Illuminate\Database\Query\JoinClause;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\ConfigurationEnum;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\SqlClient;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Connectors\Acumatica\Traits\HasAcumaticaWriter;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Bills\Models\BillLine;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Throwable;

/**
 * Pushes an approved Kanvas bill out to Acumatica as an AP Bill (create + Release), the last step of
 * the Kanvas-first AP flow: the agent proposed it, a human approved it, now it lands in the ERP.
 *
 * Idempotent — a bill already carrying its ACUMATICA_BILL_ID custom field is not re-pushed. The
 * returned Acumatica reference is stored back on the bill so the sync + agent can see it's mirrored.
 * Line coding is translated Kanvas → Acumatica: expense_account_id → AccountCD, subaccount_id → SubCD.
 */
class PushBillToAcumaticaAction
{
    use HasAcumaticaWriter;

    /** @var array<string, string|null> memoized dominant subaccount per account code, one bill push */
    private array $subaccountByAccount = [];

    /** @var array<int|string, string> resolved subaccount code per bill line, to mirror back post-push */
    private array $lineSubaccountCodes = [];

    /** The bill's own app — the tenant whose Acumatica config/credentials this push runs against. */
    protected Apps $app;

    public function __construct(
        protected Bill $bill,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->app = $bill->app;
        $this->writer = $writer;
    }

    /**
     * @return string the Acumatica ReferenceNbr (or record id) of the created bill
     */
    public function execute(): string
    {
        // Seatbelt: the real guarantee is that the push only runs from the approval handler,
        // but this catches a call site added later that skips it and would otherwise send an
        // un-approved bill to Acumatica.
        $this->bill->assertApproved();

        // Never push a bill that originated in Acumatica back to it — self-defending even when this
        // action is called directly (bypassing the activity's guard).
        if ($this->bill->source === IntegrationsEnum::ACUMATICA->value) {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} originated from Acumatica — cannot push it back."
            );
        }

        $existing = (string) $this->bill->get(CustomFieldEnum::BILL_ID->value, '');

        if ($existing !== '') {
            return $existing;
        }

        $vendorCode = $this->ensureVendorCode();

        if ($vendorCode === '') {
            throw new AcumaticaWriteException(
                "Bill {$this->bill->getId()} has no vendor organization — assign a vendor before pushing."
            );
        }

        $record = $this->writer()->push(
            'Bill',
            $this->buildPayload($vendorCode),
            release: true,
            files: $this->collectAttachments(),
            findQuery: $this->existingBillQuery($vendorCode),
        );

        $id = AcumaticaPayload::recordId($record);
        $referenceNbr = (string) (AcumaticaPayload::value($record, 'ReferenceNbr') ?? $id ?? '');

        if ($id !== null) {
            $this->bill->set(CustomFieldEnum::BILL_ID->value, $id);
        }

        if ($referenceNbr !== '') {
            $this->bill->set(CustomFieldEnum::BILL_REF->value, $referenceNbr);
        }

        $this->mirrorSubaccountOntoLines();

        return $referenceNbr;
    }

    /**
     * OData filter to adopt an Acumatica bill this push may have already created on a prior, partially
     * failed attempt (put succeeded, local id storage crashed). Matched on the vendor + their invoice
     * number (VendorRef), which together identify the AP bill. Null when there's no bill number to key on.
     *
     * @return array<string, mixed>|null
     */
    private function existingBillQuery(string $vendorCode): ?array
    {
        $vendorRef = (string) ($this->bill->bill_number ?? '');

        if ($vendorRef === '') {
            return null;
        }

        $filter = "VendorRef eq '" . AcumaticaPayload::escapeLiteral($vendorRef) . "' and Vendor eq '" . AcumaticaPayload::escapeLiteral($vendorCode) . "'";

        return ['$filter' => $filter, '$top' => 1];
    }

    /**
     * Build the exact Acumatica payload this action would send, WITHOUT writing anything. For
     * dry-runs / debugging — no write gate, no network call.
     *
     * @return array<string, mixed>
     */
    public function preview(): array
    {
        return $this->buildPayload($this->resolveVendorCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPayload(string $vendorCode): array
    {
        $header = AcumaticaPayload::wrap([
            'Type' => 'Bill',
            'Vendor' => $vendorCode,
            'VendorRef' => $this->bill->bill_number,
            'Description' => $this->bill->notes ?? ('Kanvas bill ' . $this->bill->bill_number),
            'Date' => $this->bill->bill_date?->toDateString(),
            'DueDate' => $this->bill->due_date?->toDateString(),
            'CurrencyID' => $this->bill->currency,
            'Hold' => false,
        ]);

        $header['Details'] = $this->buildLines();

        return $header;
    }

    /**
     * @return array<int, array<string, array{value: mixed}>>
     */
    private function buildLines(): array
    {
        $lines = [];

        foreach ($this->bill->lines as $line) {
            /** @var BillLine $line */
            $account = $this->lineAccount($line->expense_account_id);
            $accountCode = $account?->account_number;
            $subaccountCode = $this->resolveSubaccountCode($line->subaccount_id, $account);

            if ($subaccountCode !== null && $subaccountCode !== '') {
                $this->lineSubaccountCodes[$line->getKey()] = $subaccountCode;
            }

            $lines[] = AcumaticaPayload::wrap([
                'Description' => $line->description,
                'Qty' => (float) $line->quantity,
                'UnitCost' => (float) $line->unit_price_native,
                'Account' => $accountCode,
                'Subaccount' => $subaccountCode,
            ]);
        }

        return $lines;
    }

    /**
     * Resolve the AP line subaccount. Some tenants make Subaccount required on AP lines, but a
     * Kanvas-originated bill usually carries none — so we derive it. Order: (1) the line's own
     * subaccount, (2) the dominant subaccount this expense account is historically coded with in the
     * replica (cached on the account after the first lookup), (3) the tenant's
     * ACUMATICA_DEFAULT_SUBACCOUNT fallback. Derivation is best-effort — a missing/unreachable replica
     * just drops through to the config default.
     */
    private function resolveSubaccountCode(?int $subaccountId, ?Account $account): ?string
    {
        if ($subaccountId !== null) {
            $code = $this->subaccountCode($subaccountId);

            if ($code !== null && $code !== '') {
                return $code;
            }
        }

        if ($account !== null) {
            $derived = $this->deriveSubaccountForAccount($account);

            if ($derived !== null && $derived !== '') {
                return $derived;
            }
        }

        $default = (string) $this->app->get(ConfigurationEnum::ACUMATICA_DEFAULT_SUBACCOUNT->value);

        return $default !== '' ? $default : null;
    }

    /**
     * The subaccount an expense account is most often coded against in released AP transactions on the
     * replica — the "derive from the expense account" source, since Scribe accounts carry no default
     * subaccount and the REST Account entity exposes none. Company-global (the dominant across the
     * dataset). The first lookup is cached onto the account (ACUMATICA_DERIVED_SUBACCOUNT custom
     * field), so every later bill on the same account skips the replica round-trip entirely.
     */
    private function deriveSubaccountForAccount(Account $account): ?string
    {
        $accountCode = $account->account_number;

        if ($accountCode === '') {
            return null;
        }

        if (array_key_exists($accountCode, $this->subaccountByAccount)) {
            return $this->subaccountByAccount[$accountCode];
        }

        $cached = (string) $account->get(CustomFieldEnum::DERIVED_SUBACCOUNT->value, '');

        if ($cached !== '') {
            return $this->subaccountByAccount[$accountCode] = $cached;
        }

        try {
            $subCode = SqlClient::connection($this->app)
                ->table('APTran as t')
                ->join('Account as a', function (JoinClause $join): void {
                    $join->on('a.AccountID', '=', 't.AccountID')
                        ->on('a.CompanyID', '=', 't.CompanyID');
                })
                ->join('Sub as s', function (JoinClause $join): void {
                    $join->on('s.SubID', '=', 't.SubID')
                        ->on('s.CompanyID', '=', 't.CompanyID');
                })
                ->where('a.AccountCD', $accountCode)
                ->whereNotNull('t.SubID')
                ->groupBy('s.SubCD')
                ->orderByRaw('COUNT(*) DESC')
                ->value('s.SubCD');

            $derived = $subCode !== null ? (string) $subCode : null;
        } catch (Throwable) {
            $derived = null;
        }

        if ($derived !== null && $derived !== '') {
            $account->set(CustomFieldEnum::DERIVED_SUBACCOUNT->value, $derived);
        }

        return $this->subaccountByAccount[$accountCode] = $derived;
    }

    private function lineAccount(?int $accountId): ?Account
    {
        if ($accountId === null) {
            return null;
        }

        return Account::query()->where('id', $accountId)->first();
    }

    private function subaccountCode(?int $subaccountId): ?string
    {
        if ($subaccountId === null) {
            return null;
        }

        $code = Subaccount::query()->where('id', $subaccountId)->value('sub_code');

        return $code !== null ? (string) $code : null;
    }

    /**
     * Middle-out mirroring: stamp the subaccount actually pushed to Acumatica back onto the Kanvas
     * bill line, so the internal record reflects the ERP's real GL coding instead of a blank
     * dimension. Only fills lines that had none — an explicit line subaccount is never overwritten.
     * Best-effort: a mirror failure must not fail an already-successful push.
     */
    private function mirrorSubaccountOntoLines(): void
    {
        if ($this->lineSubaccountCodes === []) {
            return;
        }

        foreach ($this->bill->lines as $line) {
            /** @var BillLine $line */
            if (! empty($line->subaccount_id)) {
                continue;
            }

            $code = $this->lineSubaccountCodes[$line->getKey()] ?? null;

            if ($code === null) {
                continue;
            }

            $subaccount = $this->findOrCreateSubaccount($code);

            if ($subaccount !== null) {
                $line->subaccount_id = $subaccount->getKey();
                $line->saveQuietly();
            }
        }
    }

    /**
     * Resolve the Scribe subaccount mirroring an Acumatica SubCD (reference data is normally already
     * synced; create a stub when it isn't yet). Keyed on the app/company/sub_code unique.
     */
    private function findOrCreateSubaccount(string $subCode): ?Subaccount
    {
        try {
            /** @var Subaccount $subaccount */
            $subaccount = Subaccount::firstOrCreate(
                [
                    'apps_id' => $this->app->getId(),
                    'companies_id' => $this->bill->companies_id,
                    'sub_code' => $subCode,
                ],
                ['source' => IntegrationsEnum::ACUMATICA->value],
            );

            return $subaccount;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The original vendor-invoice PDF from the Mailgun ingest pipeline, to ride onto the Acumatica
     * bill. Best-effort — no ingested PDF or an unreadable file just pushes without an attachment.
     *
     * @return array<int, array{name: string, content: string, type: string}>
     */
    private function collectAttachments(): array
    {
        if ($this->bill->pdf_ingest_log_id === null) {
            return [];
        }

        $file = PdfIngestLog::query()
            ->where('id', $this->bill->pdf_ingest_log_id)
            ->first()?->filesystem;

        if ($file === null || empty($file->url)) {
            return [];
        }

        try {
            $bytes = SafeUrlFetcher::fetch((string) $file->url);
        } catch (Throwable) {
            return [];
        }

        $name = $file->name !== '' && $file->name !== null ? (string) $file->name : 'invoice.pdf';

        return [[
            'name' => str_ends_with(strtolower($name), '.pdf') ? $name : $name . '.pdf',
            'content' => $bytes,
            'type' => 'application/pdf',
        ]];
    }

    /**
     * Find-or-create the vendor in Acumatica (push path). Creates the ERP vendor lazily when the org
     * has no code yet — only reached on a real push, so a rejected bill never spawns a junk vendor.
     */
    private function ensureVendorCode(): string
    {
        $vendor = $this->vendorOrg();

        if ($vendor === null) {
            return '';
        }

        return new EnsureAcumaticaVendorAction(
            $vendor,
            taxId: $this->bill->vendor_tax_id,
            name: $this->bill->vendor_display_name,
            email: $this->bill->vendor_email,
            writer: $this->writer(),
        )->execute();
    }

    /**
     * Read-only vendor code for the dry-run preview — never creates anything in the ERP.
     */
    private function resolveVendorCode(): string
    {
        $vendor = $this->vendorOrg();

        return $vendor !== null ? (string) $vendor->get(CustomFieldEnum::VENDOR_ID->value, '') : '';
    }

    private function vendorOrg(): ?Organization
    {
        if ($this->bill->vendor_organization_id === null) {
            return null;
        }

        return Organization::query()->where('id', $this->bill->vendor_organization_id)->first();
    }
}
