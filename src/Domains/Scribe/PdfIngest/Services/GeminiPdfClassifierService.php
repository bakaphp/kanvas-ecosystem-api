<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Filesystem\Services\FilesystemServices;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Exceptions\UnsupportedDocumentTypeException;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Exceptions\AiException;
use Laravel\Ai\Files\Document;
use Laravel\Ai\Responses\StructuredAgentResponse;
use Override;
use RuntimeException;
use Throwable;

use function Laravel\Ai\agent;

/**
 * Gemini 2.5 multimodal classifier — one structured agent call extracts + classifies.
 *
 * Wired through `Laravel\Ai\agent()` with `Lab::Gemini`. The package handles:
 *   - API-key resolution (`ai.providers.gemini.key`)
 *   - HTTP transport, retries, model failover
 *   - JSON-schema enforcement on the response (returns a typed array via `$response->structured`)
 *
 * Flow:
 *   1. Fetch the bytes via SafeUrlFetcher (SSRF-guarded per the root CLAUDE.md rule)
 *   2. Detect the type from the bytes and refuse anything outside SUPPORTED_MIME_TYPES, then
 *      base64-encode + wrap as `Document::fromBase64()` so the package can send `inline_data` — a
 *      receipt photo and a PDF invoice both arrive here
 *   3. Call agent(schema)->prompt() — package serializes/deserializes; our schema definition
 *      mirrors the JSON shape the prompt describes
 *   4. Map the validated dict to PdfClassificationResult
 *
 * Error semantics: any failure (missing key, fetch error, AI error) raises a RuntimeException
 * which ProcessAccountingPdfAction catches and stamps `status=failed` on the log row. Failed
 * ingests are recoverable via retry.
 */
class GeminiPdfClassifierService implements PdfClassifierServiceInterface
{
    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    public const array SUPPORTED_MIME_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/heic',
        'image/heif',
    ];

    public function __construct(
        protected readonly ?string $modelOverride = null,
        protected readonly int $timeoutSeconds = 60,
    ) {
    }

    #[Override]
    public function classify(Filesystem $pdf, array $hints = []): PdfClassificationResult
    {
        $bytes = $this->fetchPdfBytes($pdf);
        $mimeType = $this->resolveMimeType($bytes);
        $sent = sprintf('%s, %d bytes', $mimeType, strlen($bytes));
        $prompt = self::buildPrompt($hints);
        $model = $this->resolveModel();

        try {
            /** @var StructuredAgentResponse $response */
            $response = agent(
                schema: fn ($s) => [
                    'document_type' => $s->string()
                        ->enum([
                            PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT->value,
                            PdfIngestDocumentTypeEnum::VENDOR_INVOICE->value,
                            PdfIngestDocumentTypeEnum::VENDOR_QUOTE->value,
                            PdfIngestDocumentTypeEnum::OUR_INVOICE->value,
                            PdfIngestDocumentTypeEnum::OUR_QUOTE->value,
                            PdfIngestDocumentTypeEnum::BANK_STATEMENT->value,
                            PdfIngestDocumentTypeEnum::REMITTANCE_ADVICE->value,
                            PdfIngestDocumentTypeEnum::CUSTOMER_PO->value,
                            PdfIngestDocumentTypeEnum::CREDIT_REQUEST->value,
                            PdfIngestDocumentTypeEnum::UNKNOWN->value,
                        ])
                        ->description('One of: expense_receipt | vendor_invoice | vendor_quote | our_invoice | our_quote | bank_statement | remittance_advice | customer_po | credit_request | unknown')
                        ->required(),
                    'confidence' => $s->number()->description('Confidence 0..1 in the document_type call.')->required(),
                    'reasoning' => $s->string()->description('1-2 sentences explaining why this classification was picked.'),
                    'extracted' => $s->object([
                        'vendor_name' => $s->string()->description('The vendor / sender name as printed on the document.'),
                        'vendor_tax_id' => $s->string()->description('RNC / EIN / tax id when visible; empty string when not.'),
                        'vendor_email' => $s->string()->description('Vendor email when visible; empty string when not.'),
                        'bill_number' => $s->string()->description("Invoice / bill number (e.g. 'INV-3337'). Empty when not present."),
                        'issue_date' => $s->string()->description('YYYY-MM-DD or empty.'),
                        'due_date' => $s->string()->description('YYYY-MM-DD for vendor_invoice with a future Pay By; empty otherwise.'),
                        'currency' => $s->string()->description('ISO 4217 code (e.g. USD, DOP).'),
                        'subtotal' => $s->number()->description('Pre-tax line total. 0 if not present.'),
                        'tax' => $s->number()->description('Tax amount. 0 if not present.'),
                        'total' => $s->number()->description('Grand total. 0 if not present.'),
                        'payment_method_hint' => $s->string()
                            ->enum(['credit_card', 'bank_transfer', 'cash', 'employee_personal', 'unknown'])
                            ->description("Inferred payment method ('credit_card' | 'bank_transfer' | 'cash' | 'employee_personal' | 'unknown')."),
                        'payment_status_hint' => $s->string()
                            ->enum(['paid', 'unpaid', 'unknown'])
                            ->description("Whether the document shows it has ALREADY been paid. 'paid' for receipts / paid-stamps / payment history sections. 'unpaid' for invoices with a future Pay By and no payment evidence. 'unknown' otherwise."),
                        'notes' => $s->string()->description('Free-text notes / memos visible on the doc.'),
                        'ncf' => $s->string()->description('Dominican NCF / e-NCF printed on a vendor invoice (e.g. B0100000123, E310000000045). Empty when not present.'),
                        'customer_name' => $s->string()->description('For remittance_advice / customer_po / credit_request: the customer (the company writing to us). Empty otherwise.'),
                        'po_number' => $s->string()->description('Purchase order number on a customer_po. Empty otherwise.'),
                        'payment_reference' => $s->string()->description('Transfer / check / payment reference on a remittance_advice. Empty otherwise.'),
                        'referenced_invoice_numbers' => $s->array()
                            ->description('On a remittance_advice: OUR invoice numbers the customer says this payment covers. Empty otherwise.')
                            ->items($s->string()),
                        'line_items' => $s->array()
                            ->description('The document lines as printed (vendor_invoice and customer_po).')
                            ->items($s->object([
                                'description' => $s->string(),
                                'qty' => $s->number(),
                                'unit_price' => $s->number(),
                                'line_total' => $s->number(),
                            ])),
                    ]),
                ],
            )->prompt(
                $prompt,
                attachments: [Document::fromBase64(base64_encode($bytes), $mimeType)],
                provider: Lab::Gemini,
                model: $model,
                timeout: $this->timeoutSeconds,
            );
        } catch (AiException $e) {
            throw new RuntimeException(
                "Gemini structured-classification call failed ({$sent}): {$e->getMessage()}",
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Gemini structured-classification call failed (non-AI, {$sent}): {$e->getMessage()}",
                previous: $e,
            );
        }

        return $this->mapToResult((array) $response->structured);
    }

    /**
     * The prompt shipped to Gemini. Public + static so the orchestrator's prompt-iteration test
     * harness can snapshot it without instantiating the classifier.
     */
    public static function buildPrompt(array $hints = []): string
    {
        $fromEmail = $hints['from_email'] ?? null;
        $subject = $hints['subject'] ?? null;
        $defaultCurrency = $hints['app_currency_default'] ?? 'USD';

        $contextLines = [];
        if (! empty($hints['company_name'])) {
            $contextLines[] = "Our company (the recipient of vendor documents, the seller on customer documents): {$hints['company_name']}";
        }
        if ($fromEmail !== null) {
            $contextLines[] = "Email sender: {$fromEmail} (use as fallback vendor identifier if extraction is low-confidence)";
        }
        if ($subject !== null) {
            $contextLines[] = "Email subject: {$subject} (hint at document type)";
        }
        $contextBlock = $contextLines !== [] ? "\nContext:\n  - " . implode("\n  - ", $contextLines) . "\n" : '';

        return <<<PROMPT
You are a strict accounting document classifier and extractor for a small business in the US and/or Dominican Republic.

Analyze the attached document and return a structured result matching the response schema. It may be a
PDF or a photo of a paper receipt — a phone picture is expected and readable, so extract from it the
same way. Do not lower confidence merely because the image is a photo rather than a clean scan; lower
it only when a field is genuinely illegible.

Allowed document_type values: expense_receipt | vendor_invoice | vendor_quote | our_invoice | our_quote | bank_statement | remittance_advice | customer_po | credit_request | unknown
Default currency when none stated: {$defaultCurrency}

Classification rules (apply STRICTLY in order — return the FIRST that matches):

  1. our_invoice / our_quote — ONLY when the document is clearly FROM US (our company name in the
     "From" header or our letterhead/logo). For outbound docs the operator already created and
     forwarded back to themselves.

  1b. bank_statement — an account statement FROM A BANK to the account holder ("Estado de Cuenta",
     "Statement of Account"): an account number, a statement period, opening/closing balances and a
     table of dated movements (debits, credits, running balance). Never an invoice or a receipt.

  1c. Documents FROM A CUSTOMER TO US (we are the seller; the customer is the one writing):
     - remittance_advice — the customer tells us what they paid: "Remittance Advice", "Payment Advice",
       "Aviso de pago", "Comprobante de transferencia" sent to us, a list of OUR invoice numbers with
       amounts paid. Put our invoice numbers in referenced_invoice_numbers, the amount paid in total.
     - customer_po — a purchase order the customer issues TO US: "Purchase Order", "Orden de compra",
       with us as the supplier/vendor. Never a vendor invoice that merely quotes our PO number.
     - credit_request — a credit application or credit request form from a customer: "Credit
       Application", "Solicitud de crédito", trade references and requested credit limit.
     Put the customer's name in customer_name.

  2. vendor_quote — title or first heading contains "Quote", "Estimate", "Proforma Invoice",
     "Pro Forma Invoice", or "Cotización" (DR). No real billing happens yet.

  3. vendor_invoice — this is the DEFAULT for any formal billing document FROM a vendor TO our
     company. Trigger when ANY of these are true:
       - Document title or header contains "Invoice", "Tax Invoice", "Bill", or "Factura" (DR)
       - Anywhere on the document there's an invoice/bill number field: "Invoice #",
         "Invoice Number", "Invoice No", "INV-…", "Bill No", "Bill #", "Número de Factura"
       - Has a "Due By", "Pay By", "Payment Due", "Net 30/60", or "Payment Terms" field
         (even if blank or unfilled)
       - Has bank account / wire / ACH / BSB / SWIFT / IBAN coordinates so the vendor can be paid
       - Has a remit-to address or "Make checks payable to" instructions

     vendor_invoice is the SAFE DEFAULT. Pick it whenever you're choosing between vendor_invoice
     and expense_receipt and the doc has any invoice-like structure.

  4. expense_receipt — pick this ONLY when ALL of the following are true:
       - There is NO invoice number anywhere on the document
       - There IS explicit payment-already-completed evidence: a "PAID" stamp,
         a transaction id / authorization code, a credit card last-4 ("•••• 1234"), "Auth Code: …",
         a card terminal slip layout, a "Change due: 0.00" line, or a POS-style total line
       - Typical source: restaurant, taxi/rideshare, hotel folio, retail store/POS, fuel station,
         parking, coffee shop, grocery, in-store small purchase

     IMPORTANT: A document with an invoice number plus bank account details for the vendor to
     receive payment is NOT an expense_receipt — that's a vendor_invoice (the vendor is asking
     US to pay them, the bank details are how to remit). Bank coordinates ≠ proof of payment.

  5. unknown — when none of the above match with confidence ≥ 0.7.

If unsure between two options: prefer vendor_invoice over expense_receipt, and prefer unknown
over guessing. Conservative misclassifications are cheaper to fix than aggressive ones.

For Dominican Republic documents look for "NCF" / "e-NCF" (Comprobante Fiscal) and "RNC" (tax id) —
extract the NCF verbatim into ncf and the vendor's RNC into vendor_tax_id.

Payment status — drive the `payment_status_hint` field:
  - 'paid'    when ANY paid-evidence signal is present: a "Receipt" header, "PAID" stamp, "Amount
              paid" line, a payment history / payment method table, "Date paid" field, transaction
              id / authorization code, credit card last-4, POS terminal slip.
  - 'unpaid'  when document has a "Pay By" / "Payment Due" / "Due By" date in the future, a
              non-zero balance-due, or "Make checks payable to" instructions.
  - 'unknown' when neither is clearly indicated.
{$contextBlock}
Empty-string is acceptable for any extracted field that's not on the document. Do NOT invent
values that aren't visible.
PROMPT;
    }

    protected function resolveModel(): string
    {
        if ($this->modelOverride !== null && $this->modelOverride !== '') {
            return $this->modelOverride;
        }

        $configured = app(Apps::class)->get(ConfigurationEnum::GEMINI_MODEL->value);
        if (is_string($configured) && $configured !== '') {
            return $configured;
        }

        return self::DEFAULT_MODEL;
    }

    /**
     * What Gemini is told the bytes are, read from the bytes. `file_type` is not evidence: a file
     * attached by URL — every email attachment — takes it from the URL's extension, and Gemini answers
     * a mislabelled document with a bare 400 INVALID_ARGUMENT (KANVAS-ECOSYSTEM-6CH).
     */
    protected function resolveMimeType(string $bytes): string
    {
        $mimeType = FilesystemServices::detectMimeTypeFromBytes($bytes);

        if (! in_array($mimeType, self::SUPPORTED_MIME_TYPES, true)) {
            throw new UnsupportedDocumentTypeException($mimeType);
        }

        return $mimeType;
    }

    protected function fetchPdfBytes(Filesystem $pdf): string
    {
        try {
            $bytes = SafeUrlFetcher::fetch((string) $pdf->url);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Failed to fetch PDF bytes from {$pdf->url}: {$e->getMessage()}",
                previous: $e,
            );
        }

        if ($bytes === '') {
            throw new RuntimeException("Fetched 0 bytes from {$pdf->url} — refusing to send empty body to Gemini.");
        }

        return $bytes;
    }

    /**
     * Convert the schema-validated dict from `agent()->structured` into the typed result. Defensive
     * lookups stay because the schema's `->required()` only applies to top-level keys; nested
     * fields default to empty when not present.
     *
     * @param  array<string, mixed>  $decoded
     */
    protected function mapToResult(array $decoded): PdfClassificationResult
    {
        $typeString = is_string($decoded['document_type'] ?? null) ? $decoded['document_type'] : 'unknown';
        $type = PdfIngestDocumentTypeEnum::tryFrom($typeString) ?? PdfIngestDocumentTypeEnum::UNKNOWN;
        $confidence = is_numeric($decoded['confidence'] ?? null) ? (float) $decoded['confidence'] : 0.0;
        $confidence = max(0.0, min(1.0, $confidence));
        $reasoning = is_string($decoded['reasoning'] ?? null) ? $decoded['reasoning'] : null;
        $extracted = is_array($decoded['extracted'] ?? null) ? $decoded['extracted'] : null;

        return new PdfClassificationResult(
            document_type: $type,
            confidence: $confidence,
            reasoning: $reasoning,
            extracted: $extracted,
        );
    }
}
