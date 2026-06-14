<?php

declare(strict_types=1);

namespace Kanvas\Scribe\PdfIngest\Contracts;

use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;

/**
 * Classifies + extracts structured data from an accounting PDF.
 *
 * Real impl: GeminiPdfClassifier (one multimodal LLM call → classification + extraction in one shot).
 * Test impl: FakePdfClassifier (returns a pre-set verdict, no network).
 *
 * The interface lets the orchestrator action stay testable without mocking the LLM transport.
 */
interface PdfClassifierServiceInterface
{
    /**
     * @param  Filesystem  $pdf  the Filesystem row pointing at the inbound PDF
     * @param  array  $hints  optional context — {from_email, subject, from_name, app_currency_default}
     *                       passed to the classifier as supplementary prompt context (sender email becomes
     *                       a fallback vendor identifier when LLM-extracted vendor is low confidence)
     */
    public function classify(Filesystem $pdf, array $hints = []): PdfClassificationResult;
}
