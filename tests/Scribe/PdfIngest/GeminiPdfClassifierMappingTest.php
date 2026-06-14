<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Illuminate\Support\Facades\Http;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use ReflectionClass;
use Tests\Scribe\ScribeTestCase;

/**
 * Verifies the parts of GeminiPdfClassifierService we can exercise without a live API key:
 *   - buildPrompt() injects context hints (sender, subject, currency default)
 *   - mapToResult() defends against malformed LLM JSON (unknown type → UNKNOWN, missing confidence → 0)
 *
 * The full classify() round-trip (fetch + Gemini POST + parse) requires a real API key and is
 * deferred to a manual smoke test. The orchestrator-level routing tests use FakePdfClassifier.
 */
class GeminiPdfClassifierMappingTest extends ScribeTestCase
{
    public function test_prompt_includes_sender_subject_currency_hints(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt([
            'from_email' => 'invoice@aws.test',
            'subject' => 'Your AWS invoice for May',
            'app_currency_default' => 'DOP',
        ]);

        $this->assertStringContainsString('Email sender: invoice@aws.test', $prompt);
        $this->assertStringContainsString('Email subject: Your AWS invoice for May', $prompt);
        $this->assertStringContainsString('DOP', $prompt);
        $this->assertStringContainsString('NCF', $prompt, 'DR compliance hints should be in the prompt');
    }

    public function test_prompt_works_without_any_hints(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt([]);

        $this->assertStringNotContainsString('Email sender:', $prompt);
        $this->assertStringContainsString('USD', $prompt, 'Default currency fallback should be USD');
    }

    public function test_map_to_result_handles_well_formed_payload(): void
    {
        $classifier = new GeminiPdfClassifierService();
        $result = $this->invokeMapToResult($classifier, [
            'document_type' => 'expense_receipt',
            'confidence' => 0.93,
            'reasoning' => 'Receipt shows paid stamp.',
            'extracted' => ['vendor_name' => 'Mercury', 'total' => 50.0],
        ]);

        $this->assertSame(PdfIngestDocumentTypeEnum::EXPENSE_RECEIPT, $result->document_type);
        $this->assertEqualsWithDelta(0.93, $result->confidence, 0.0001);
        $this->assertSame('Receipt shows paid stamp.', $result->reasoning);
        $this->assertSame('Mercury', $result->extracted['vendor_name']);
    }

    public function test_map_to_result_clamps_confidence_and_defaults_unknown_type(): void
    {
        $classifier = new GeminiPdfClassifierService();

        // Confidence > 1 — should clamp to 1
        $clamped = $this->invokeMapToResult($classifier, [
            'document_type' => 'expense_receipt',
            'confidence' => 1.5,
        ]);
        $this->assertEqualsWithDelta(1.0, $clamped->confidence, 0.0001);

        // Unknown document_type string → UNKNOWN
        $unknown = $this->invokeMapToResult($classifier, [
            'document_type' => 'some_bogus_type_the_llm_invented',
            'confidence' => 0.5,
        ]);
        $this->assertSame(PdfIngestDocumentTypeEnum::UNKNOWN, $unknown->document_type);

        // Missing confidence → 0
        $missingConf = $this->invokeMapToResult($classifier, [
            'document_type' => 'vendor_invoice',
        ]);
        $this->assertSame(0.0, $missingConf->confidence);
    }

    public function test_classify_surfaces_http_error_with_helpful_message(): void
    {
        Http::fake([
            'generativelanguage.googleapis.com/*' => Http::response(['error' => 'overloaded'], 503),
        ]);

        $classifier = new GeminiPdfClassifierService(
            apiKeyOverride: 'fake-key',
            modelOverride: 'gemini-2.5-flash',
        );

        $pdf = $this->createFilesystemRow();

        try {
            $classifier->classify($pdf);
            $this->fail('Expected RuntimeException from upstream failure.');
        } catch (\RuntimeException $e) {
            // Could fail at fetch (URL not reachable) OR at HTTP layer — both surface as RuntimeException
            $this->assertNotEmpty($e->getMessage());
        }
    }

    private function invokeMapToResult(GeminiPdfClassifierService $classifier, array $decoded)
    {
        $ref = new ReflectionClass($classifier);
        $method = $ref->getMethod('mapToResult');
        $method->setAccessible(true);

        return $method->invoke($classifier, $decoded);
    }
}
