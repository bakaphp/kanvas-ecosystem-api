<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Services;

use Baka\Http\SafeUrlFetcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use RuntimeException;
use Throwable;

/**
 * Gemini 2.5 multimodal classifier — one HTTP call extracts + classifies.
 *
 * Flow:
 *   1. Fetch PDF bytes via SafeUrlFetcher (SSRF-guarded per the root CLAUDE.md rule)
 *   2. Base64-encode for inline_data part of the request
 *   3. POST to v1beta/models/{model}:generateContent with the JSON-schema'd prompt
 *   4. Parse the JSON response into a PdfClassificationResult
 *
 * Key resolution: app-level ConfigurationEnum::GEMINI_KEY (per-tenant) — same key the NeuronAI
 * agents use. Model defaults to gemini-2.5-flash for cost/latency on receipt-grade docs;
 * override via ConfigurationEnum::GEMINI_MODEL or scribe-specific config later.
 *
 * Error semantics: any failure (missing key, fetch error, HTTP error, malformed JSON) raises a
 * RuntimeException which ProcessAccountingPdfAction catches and stamps `status=failed` on the log row.
 * The orchestrator NEVER propagates the exception to the queue — failed ingests are recoverable via
 * retry.
 */
class GeminiPdfClassifierService implements PdfClassifierServiceInterface
{
    public const ENDPOINT_TEMPLATE = 'https://generativelanguage.googleapis.com/v1beta/models/%s:generateContent';
    public const DEFAULT_MODEL = 'gemini-2.5-flash';

    public function __construct(
        protected readonly ?string $apiKeyOverride = null,
        protected readonly ?string $modelOverride = null,
        protected readonly int $timeoutSeconds = 60,
    ) {
    }

    public function classify(Filesystem $pdf, array $hints = []): PdfClassificationResult
    {
        $apiKey = $this->resolveApiKey();
        $model = $this->resolveModel();

        $bytes = $this->fetchPdfBytes($pdf);
        $base64 = base64_encode($bytes);

        $endpoint = sprintf(self::ENDPOINT_TEMPLATE, $model);
        $prompt = self::buildPrompt($hints);

        $response = Http::timeout($this->timeoutSeconds)
            ->retry(2, 1500, throw: false)
            ->post($endpoint . '?key=' . $apiKey, [
                'contents' => [[
                    'parts' => [
                        ['inline_data' => ['mime_type' => 'application/pdf', 'data' => $base64]],
                        ['text' => $prompt],
                    ],
                ]],
                'generationConfig' => [
                    'responseMimeType' => 'application/json',
                    'temperature' => 0.1,
                ],
            ]);

        if (! $response->successful()) {
            throw new RuntimeException(
                "Gemini classify request failed: HTTP {$response->status()} — "
                . substr((string) $response->body(), 0, 300)
            );
        }

        $text = $this->extractTextPart($response->json());
        $decoded = json_decode($text, associative: true);
        if (! is_array($decoded)) {
            throw new RuntimeException(
                'Gemini returned non-JSON text payload: ' . substr($text, 0, 300)
            );
        }

        return $this->mapToResult($decoded);
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

Analyze the attached PDF and return a single JSON object matching this shape:

{
  "document_type": "expense_receipt" | "vendor_invoice" | "vendor_quote" | "our_invoice" | "our_quote" | "unknown",
  "confidence": 0.0 - 1.0,
  "reasoning": "1-2 sentences why",
  "extracted": {
    "vendor_name": "string",
    "vendor_tax_id": "string or null",
    "vendor_email": "string or null",
    "bill_number": "string or null (the vendor's invoice number)",
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

    protected function resolveApiKey(): string
    {
        if ($this->apiKeyOverride !== null && $this->apiKeyOverride !== '') {
            return $this->apiKeyOverride;
        }

        $key = app(Apps::class)->get(ConfigurationEnum::GEMINI_KEY->value);
        if (! is_string($key) || $key === '') {
            throw new RuntimeException(
                'Gemini API key is not configured for this app. '
                . 'Set the ' . ConfigurationEnum::GEMINI_KEY->value . ' setting on the app.'
            );
        }

        return $key;
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
     * Drill into Gemini's response envelope to pull out the text part. Shape:
     *   { candidates: [{ content: { parts: [{ text: '...' }] } }] }
     *
     * Logs + raises with a helpful diagnostic when the shape isn't what we expect, so prompt
     * regressions surface fast.
     *
     * @param  array<string, mixed>|null  $payload
     */
    protected function extractTextPart(?array $payload): string
    {
        $text = $payload['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (! is_string($text) || $text === '') {
            Log::warning('Scribe.PdfIngest.Gemini unexpected response shape', ['payload' => $payload]);

            throw new RuntimeException('Gemini response missing candidates[0].content.parts[0].text');
        }

        return $text;
    }

    /**
     * Convert the parsed JSON into the typed result. Unknown document_type strings fall back to
     * UNKNOWN; missing confidence defaults to 0 (so it always lands in the operator's "low conf"
     * bucket rather than auto-trusting a malformed extraction).
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
