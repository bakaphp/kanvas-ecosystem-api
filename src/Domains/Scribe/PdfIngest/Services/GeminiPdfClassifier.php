<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use RuntimeException;

/**
 * Gemini 2.5 multimodal classifier. One call extracts + classifies — no separate OCR pipeline.
 *
 * IMPLEMENTATION NOTE (PR 9 scaffold):
 *   The actual Gemini HTTP wiring lands in a follow-up. This class exists today as the production
 *   binding for `PdfClassifierServiceInterface` so the orchestrator + routing tests work end-to-end
 *   against the FakePdfClassifier. When ready to ship live ingest:
 *
 *   1. Fetch the PDF bytes via `SafeUrlFetcher::fetch($pdf->url)` (per the SSRF rule in root CLAUDE.md)
 *   2. POST to Gemini's `generateContent` endpoint with the PDF as inline base64 + the JSON-schema'd
 *      prompt that requests `{document_type, confidence, reasoning, extracted}`
 *   3. Parse + return a PdfClassificationResult
 *
 * The prompt shape lives below in `buildPrompt()` — adjust there when wiring the real call.
 */
class GeminiPdfClassifier implements PdfClassifierServiceInterface
{
    public function classify(Filesystem $pdf, array $hints = []): PdfClassificationResult
    {
        throw new RuntimeException(
            'GeminiPdfClassifier is not yet wired to the live Gemini API. '
            . 'In tests bind PdfClassifierServiceInterface to FakePdfClassifier. '
            . 'See class docblock for the wire-up steps.'
        );
    }

    /**
     * The prompt the live classifier will send. Kept in code (not config) so we can iterate it
     * with the rest of the classifier code under version control.
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

Analyze the attached PDF and return a single JSON object matching this shape:

{
  "document_type": "expense_receipt" | "vendor_invoice" | "vendor_quote" | "our_invoice" | "our_quote" | "unknown",
  "confidence": 0.0 - 1.0,
  "reasoning": "1-2 sentences why",
  "extracted": {
    "vendor_name": "string",
    "vendor_tax_id": "string or null",
    "vendor_email": "string or null",
    "issue_date": "YYYY-MM-DD or null",
    "due_date": "YYYY-MM-DD or null (only for vendor_invoice)",
    "currency": "ISO 4217 code, default {$defaultCurrency}",
    "subtotal": number,
    "tax": number,
    "total": number,
    "line_items": [{"description": "string", "qty": number, "unit_price": number, "line_total": number}],
    "tax_metadata": {"ncf": "string or null", "rnc": "string or null", "jurisdiction": "DO|US|... or null"},
    "payment_method_hint": "credit_card | bank_transfer | cash | employee_personal | unknown",
    "notes": "free-text"
  }
}

Classification rules (apply in order):
  1. If the document shows "Paid", a credit card last-4, or any payment-already-made indicator → expense_receipt.
  2. If the document is from a vendor TO our company with a "Pay By" date in the future and balance due > 0 → vendor_invoice.
  3. If it's a quote / estimate / proforma from a vendor → vendor_quote.
  4. If the document is FROM our company and we recognize our own letterhead → our_invoice or our_quote.
  5. Otherwise → unknown.

For Dominican Republic documents look for "NCF" (Comprobante Fiscal) and "RNC" (tax id) — extract verbatim into tax_metadata.
{$contextBlock}
Output ONLY the JSON — no surrounding prose, no markdown, no code fence.
PROMPT;
    }
}
