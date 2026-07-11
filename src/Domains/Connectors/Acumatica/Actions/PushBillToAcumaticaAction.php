<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Acumatica\Actions;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Acumatica\Enums\CustomFieldEnum;
use Kanvas\Connectors\Acumatica\Exceptions\AcumaticaWriteException;
use Kanvas\Connectors\Acumatica\Services\AcumaticaWriteService;
use Kanvas\Connectors\Acumatica\Support\AcumaticaPayload;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\Ledger\Models\Account;
use Kanvas\Scribe\Ledger\Models\Subaccount;
use Kanvas\Scribe\PdfIngest\Models\PdfIngestLog;
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
    private ?AcumaticaWriteService $writer;

    public function __construct(
        protected Apps $app,
        protected Bill $bill,
        ?AcumaticaWriteService $writer = null,
    ) {
        $this->writer = $writer;
    }

    /**
     * @return string the Acumatica ReferenceNbr (or record id) of the created bill
     */
    public function execute(): string
    {
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
        );

        $id = AcumaticaPayload::recordId($record);
        $referenceNbr = (string) (AcumaticaPayload::value($record, 'ReferenceNbr') ?? $id ?? '');

        if ($id !== null) {
            $this->bill->set(CustomFieldEnum::BILL_ID->value, $id);
        }

        if ($referenceNbr !== '') {
            $this->bill->set(CustomFieldEnum::BILL_REF->value, $referenceNbr);
        }

        return $referenceNbr;
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
            $lines[] = AcumaticaPayload::wrap([
                'Description' => $line->description,
                'Qty' => (float) $line->quantity,
                'UnitCost' => (float) $line->unit_price_native,
                'Account' => $this->accountCode($line->expense_account_id),
                'Subaccount' => $this->subaccountCode($line->subaccount_id),
            ]);
        }

        return $lines;
    }

    private function accountCode(?int $accountId): ?string
    {
        if ($accountId === null) {
            return null;
        }

        $code = Account::query()->where('id', $accountId)->value('account_number');

        return $code !== null ? (string) $code : null;
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
            $this->app,
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

    private function writer(): AcumaticaWriteService
    {
        return $this->writer ??= new AcumaticaWriteService($this->app);
    }
}
