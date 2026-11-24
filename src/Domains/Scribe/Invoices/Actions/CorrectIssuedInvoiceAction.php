<?php

declare(strict_types=1);

namespace Kanvas\Scribe\Invoices\Actions;

use Baka\Support\Str;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Support\Facades\DB;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Models\InvoiceLine;
use Spatie\LaravelData\DataCollection;

/**
 * An edit to an issued e-CF invoice, turned into the documents DGII allows (plan §10 decision 3). The law
 * has no edit for an e-CF, so the invoice stays exactly as issued and the difference between what it says
 * now and what the person wants it to say goes out as notes:
 *
 *   - amounts down / lines removed → credit note (E34, code 3; code 1 when it cancels the invoice in full)
 *   - amounts up / lines added      → debit note (E33)
 *   - both in one edit              → one of each
 *
 * "What it says now" is the invoice plus the notes already issued on it, so a second edit corrects from
 * the last corrected state instead of crediting the same change twice. Lines are matched by item, then
 * SKU, then description; a quantity change at an unchanged price becomes a quantity line, anything else
 * a single amount line, so the note reads like the change that was made.
 *
 * A person's edit (UI, API) issues the notes as that person with no approval. An agent's edit drafts them
 * and submits each for the always-on credit/debit note approval.
 */
class CorrectIssuedInvoiceAction
{
    private const float EPSILON = 0.005;

    public function __construct(
        public readonly Invoice $invoice,
        public readonly InvoiceData $data,
        public readonly ?UserInterface $user = null,
    ) {
    }

    /**
     * @return list<Invoice> the correcting notes, credit first
     */
    public function execute(): array
    {
        $this->assertCorrectable();

        [$credit, $debit] = $this->differences();

        if ($credit === [] && $debit === []) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->invoice_number} is an issued e-CF and its amounts did not change. Text, dates "
                . 'and references on an issued e-CF are changed with an amendment, never an edit.'
            );
        }

        $byAgent = ApprovalOriginService::current() === ApprovalOriginEnum::AGENT;

        return DB::connection('accounting')->transaction(function () use ($credit, $debit, $byAgent): array {
            $notes = [];

            if ($credit !== []) {
                $notes[] = $this->issueCredit($credit, $byAgent);
            }

            if ($debit !== []) {
                $notes[] = $this->issueDebit($debit, $byAgent);
            }

            return $notes;
        });
    }

    public static function isLiveEcf(Invoice $invoice): bool
    {
        return $invoice->document_type === DocumentTypeEnum::INVOICE
            && EcfCompliance::hasNumber($invoice)
            && EcfCompliance::status($invoice) !== EcfStatusEnum::REJECTED
            && $invoice->document_status->isEffective();
    }

    private function assertCorrectable(): void
    {
        if (! self::isLiveEcf($this->invoice)) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->getId()} is not an issued e-CF invoice, so there is nothing to correct with a note."
            );
        }

        if (EcfCompliance::status($this->invoice) === EcfStatusEnum::WAITING) {
            throw new InvalidInvoiceTransitionException(
                "Invoice {$this->invoice->invoice_number} is still waiting for DGII's answer. A note can only modify an "
                . 'e-CF DGII accepted; correct it once the result is in.'
            );
        }
    }

    /**
     * @param list<InvoiceLineData> $lines
     */
    private function issueCredit(array $lines, bool $byAgent): Invoice
    {
        $action = new IssueCreditNoteAction(
            parentInvoice: $this->invoice,
            data: $this->noteData($lines, 'Nota de crédito'),
            user: $this->user,
        );

        return $byAgent ? $this->submit($action->draft()) : $action->execute();
    }

    /**
     * @param list<InvoiceLineData> $lines
     */
    private function issueDebit(array $lines, bool $byAgent): Invoice
    {
        $action = new IssueDebitNoteAction(
            parentInvoice: $this->invoice,
            data: $this->noteData($lines, 'Nota de débito'),
            user: $this->user,
        );

        return $byAgent ? $this->submit($action->draft()) : $action->execute();
    }

    private function submit(Invoice $draft): Invoice
    {
        return new SubmitInvoiceForApprovalAction(invoice: $draft, user: $this->user)->execute();
    }

    /**
     * @param list<InvoiceLineData> $lines
     */
    private function noteData(array $lines, string $label): InvoiceData
    {
        return new InvoiceData(
            app: $this->invoice->app,
            company: $this->invoice->company,
            billable: null,
            lines: new DataCollection(InvoiceLineData::class, $lines),
            currency: $this->invoice->currency,
            fx_rate_to_base: (float) $this->invoice->fx_rate_to_base,
            notes: "{$label} que corrige la factura {$this->invoice->invoice_number}.",
            metadata: ['corrects_invoice_id' => $this->invoice->getId()],
        );
    }

    /**
     * @return array{0: list<InvoiceLineData>, 1: list<InvoiceLineData>}
     */
    private function differences(): array
    {
        $current = $this->currentState();
        $wanted = $this->wantedState();
        $wantedTotal = array_sum(array_map(fn (array $line): float => $line['net'] + $line['tax'], $wanted));

        // Removing every line, or zeroing them, cancels the invoice: credit what is left of it in full.
        if ($wanted === [] || abs($wantedTotal) < self::EPSILON) {
            $credit = [];

            foreach ($current as $line) {
                if ($line['net'] + $line['tax'] > self::EPSILON) {
                    $credit[] = $this->noteLine(
                        $line,
                        $line['quantity'],
                        $line['net'],
                        $line['tax']
                    );
                }
            }

            return [$credit, []];
        }

        $credit = [];
        $debit = [];

        foreach (array_unique([...array_keys($current), ...array_keys($wanted)]) as $key) {
            $before = $current[$key] ?? null;
            $after = $wanted[$key] ?? null;
            $template = $before ?? $after;

            $deltaNet = ($after['net'] ?? 0.0) - ($before['net'] ?? 0.0);
            $deltaTax = ($after['tax'] ?? 0.0) - ($before['tax'] ?? 0.0);

            if (abs($deltaNet) < self::EPSILON && abs($deltaTax) < self::EPSILON) {
                continue;
            }

            if ($deltaNet * $deltaTax < 0 && abs($deltaNet) >= self::EPSILON && abs($deltaTax) >= self::EPSILON) {
                throw new InvalidInvoiceTransitionException(
                    "Line \"{$template['description']}\" changes its amount and its ITBIS in opposite directions, which "
                    . 'no single credit or debit note can express. Make the change in two edits.'
                );
            }

            $quantity = $this->quantityDelta($before, $after);
            $line = $this->noteLine(
                $template,
                $quantity,
                abs($deltaNet),
                abs($deltaTax)
            );

            if ($deltaNet + $deltaTax < 0) {
                $credit[] = $line;
            } else {
                $debit[] = $line;
            }
        }

        return [$credit, $debit];
    }

    /**
     * A pure quantity change at the same unit price reads as that quantity; anything else as one unit of the
     * amount difference.
     *
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     */
    private function quantityDelta(?array $before, ?array $after): float
    {
        if ($before === null || $after === null) {
            return (float) ($after['quantity'] ?? $before['quantity'] ?? 1.0);
        }

        $samePrice = abs($before['unit_price'] - $after['unit_price']) < 0.00005;
        $noDiscount = $before['discount'] < self::EPSILON && $after['discount'] < self::EPSILON;
        $deltaQuantity = abs($after['quantity'] - $before['quantity']);

        return $samePrice && $noDiscount && $deltaQuantity > 0 ? $deltaQuantity : 1.0;
    }

    /**
     * @param array<string, mixed> $template
     */
    private function noteLine(
        array $template,
        float $quantity,
        float $net,
        float $tax
    ): InvoiceLineData {
        $quantity = $quantity > 0 ? $quantity : 1.0;

        return new InvoiceLineData(
            description: $template['description'],
            quantity: $quantity,
            unit_price_native: round($net / $quantity, 4),
            item_id: $template['item_id'],
            sku: $template['sku'],
            tax_rate: $template['tax_rate'],
            tax_amount_native: round($tax, 4),
            metadata: $template['metadata'],
        );
    }

    /**
     * The invoice as it legally reads today: its own lines, less what credit notes took off, plus what debit
     * notes added, keyed like the edit's lines.
     *
     * @return array<string, array<string, mixed>>
     */
    private function currentState(): array
    {
        $state = [];

        foreach ($this->invoice->lines()->get() as $line) {
            $this->accumulate($state, $this->fromModel($line), 1);
        }

        $notes = Invoice::query()
            ->where('parent_invoice_id', $this->invoice->getId())
            ->whereIn('document_type', [DocumentTypeEnum::CREDIT_NOTE->value, DocumentTypeEnum::DEBIT_NOTE->value])
            ->whereIn('document_status', InvoiceDocumentStatusEnum::effective())
            ->notDeleted()
            ->with('lines')
            ->get();

        foreach ($notes as $note) {
            /** @var Invoice $note */
            $sign = $note->isCreditNote() ? -1 : 1;

            foreach ($note->lines as $line) {
                $this->accumulate($state, $this->fromModel($line), $sign);
            }
        }

        return $state;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function wantedState(): array
    {
        $state = [];

        foreach ($this->data->lines as $line) {
            /** @var InvoiceLineData $line */
            $this->accumulate($state, [
                'key' => $this->key($line->item_id, $line->sku, $line->description),
                'description' => $line->description,
                'item_id' => $line->item_id,
                'sku' => $line->sku,
                'tax_rate' => $line->tax_rate,
                'metadata' => $line->metadata,
                'quantity' => $line->quantity,
                'unit_price' => $line->unit_price_native,
                'discount' => $line->discount_amount_native,
                'net' => $line->lineSubtotalNative() - $line->discount_amount_native,
                'tax' => $line->tax_amount_native,
            ], 1);
        }

        return $state;
    }

    /**
     * @return array<string, mixed>
     */
    private function fromModel(InvoiceLine $line): array
    {
        return [
            'key' => $this->key($line->item_id, $line->sku, (string) $line->description),
            'description' => (string) $line->description,
            'item_id' => $line->item_id,
            'sku' => $line->sku,
            'tax_rate' => $line->tax_rate,
            'metadata' => $line->metadata,
            'quantity' => (float) $line->quantity,
            'unit_price' => (float) $line->unit_price_native,
            'discount' => (float) $line->discount_amount_native,
            'net' => ((float) $line->quantity * (float) $line->unit_price_native) - (float) $line->discount_amount_native,
            'tax' => (float) $line->tax_amount_native,
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $state
     * @param array<string, mixed> $line
     */
    private function accumulate(array &$state, array $line, int $sign): void
    {
        $key = $line['key'];

        if (! isset($state[$key])) {
            $state[$key] = [...$line, 'quantity' => 0.0, 'discount' => 0.0, 'net' => 0.0, 'tax' => 0.0];
        }

        $state[$key]['quantity'] += $sign * $line['quantity'];
        $state[$key]['discount'] += $sign * $line['discount'];
        $state[$key]['net'] += $sign * $line['net'];
        $state[$key]['tax'] += $sign * $line['tax'];
    }

    private function key(?int $itemId, ?string $sku, string $description): string
    {
        if ($itemId !== null) {
            return 'item:' . $itemId;
        }

        $sku = Str::trimToNull($sku);

        return $sku !== null ? 'sku:' . $sku : 'desc:' . mb_strtolower(trim($description));
    }
}
