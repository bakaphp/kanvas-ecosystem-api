<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Baka\Http\SafeUrlFetcher;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
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
 *   - API-key resolution (via the KanvasGeminiGateway registered in AppServiceProvider)
 *   - HTTP transport, retries, model failover
 *   - JSON-schema enforcement on the response (returns a typed array via `$response->structured`)
 *
 * Flow:
 *   1. Fetch PDF bytes via SafeUrlFetcher (SSRF-guarded per the root CLAUDE.md rule)
 *   2. Base64-encode + wrap as `Document::fromBase64()` so the package can send `inline_data`
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

    public function __construct(
        protected readonly ?string $modelOverride = null,
        protected readonly int $timeoutSeconds = 60,
    ) {
    }

    #[Override]
    public function classify(Filesystem $pdf, array $hints = []): PdfClassificationResult
    {
        $bytes = $this->fetchPdfBytes($pdf);
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
                            PdfIngestDocumentTypeEnum::UNKNOWN->value,
                        ])
                        ->description('One of: expense_receipt | vendor_invoice | vendor_quote | our_invoice | our_quote | unknown')
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
                    ]),
                ],
            )->prompt(
                $prompt,
                attachments: [Document::fromBase64(base64_encode($bytes), 'application/pdf')],
                provider: Lab::Gemini,
                model: $model,
                timeout: $this->timeoutSeconds,
            );
        } catch (AiException $e) {
            throw new RuntimeException(
                "Gemini structured-classification call failed: {$e->getMessage()}",
                previous: $e,
            );
        } catch (Throwable $e) {
            throw new RuntimeException(
                "Gemini structured-classification call failed (non-AI): {$e->getMessage()}",
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
        if ($fromEmail !== null) {
            $contextLines[] = "Email sender: {$fromEmail} (use as fallback vendor identifier if extraction is low-confidence)";
        }
        if ($subject !== null) {
            $contextLines[] = "Email subject: {$subject} (hint at document type)";
        }
        $contextBlock = $contextLines !== [] ? "\nContext:\n  - " . implode("\n  - ", $contextLines) . "\n" : '';

        return <<<PROMPT
You are a strict accounting document classifier and extractor for a small business in the US and/or Dominican Republic.

Analyze the attached PDF and return a structured result matching the response schema.

Allowed document_type values: expense_receipt | vendor_invoice | vendor_quote | our_invoice | our_quote | unknown
Default currency when none stated: {$defaultCurrency}

Classification rules (apply STRICTLY in order — return the FIRST that matches):

  1. our_invoice / our_quote — ONLY when the document is clearly FROM US (our company name in the
     "From" header or our letterhead/logo). For outbound docs the operator already created and
     forwarded back to themselves.

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

For Dominican Republic documents look for "NCF" (Comprobante Fiscal) and "RNC" (tax id) — extract
verbatim into vendor_tax_id where appropriate.

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
