<?php

declare(strict_types=1);

namespace Tests\Scribe\PdfIngest;

use Kanvas\Scribe\PdfIngest\Services\GeminiPdfClassifierService;
use PHPUnit\Framework\TestCase;

/**
 * Pinned snapshots of the GeminiPdfClassifierService prompt rules so prompt drift is visible at
 * code-review time. When you intentionally tweak the rules, update the expected strings below —
 * the diff in the PR is the audit trail.
 *
 * Tests run against `buildPrompt(array $hints)` directly — no Gemini API call, no DB. Pure
 * unit-level on a pure-static method.
 */
class GeminiPdfClassifierPromptSnapshotTest extends TestCase
{
    public function test_prompt_picks_vendor_invoice_as_default_when_in_doubt(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt(['app_currency_default' => 'USD']);

        // The rule the user explicitly added after the slicedinvoices misclassification: when uncertain
        // between vendor_invoice and expense_receipt, choose vendor_invoice.
        $this->assertStringContainsString(
            'prefer vendor_invoice over expense_receipt',
            $prompt,
            'Prompt must encode the "vendor_invoice is the safe default" rule.',
        );
    }

    public function test_prompt_requires_strict_proof_for_expense_receipt(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt([]);

        // expense_receipt requires explicit payment proof — bank transfer details alone don't qualify.
        $this->assertStringContainsString('"PAID" stamp', $prompt);
        $this->assertStringContainsString('credit card last-4', $prompt);
        $this->assertStringContainsString(
            'Bank coordinates ≠ proof of payment',
            $prompt,
            'Prompt must disambiguate bank-transfer instructions from receipt-style "already paid" evidence.',
        );
    }

    public function test_prompt_treats_invoice_number_as_vendor_invoice_signal(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt([]);

        $this->assertStringContainsString('Invoice #', $prompt);
        $this->assertStringContainsString('Invoice Number', $prompt);
        $this->assertStringContainsString('Bill No', $prompt);
    }

    public function test_prompt_uses_strict_ordering(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt([]);

        $this->assertStringContainsString(
            'apply STRICTLY in order — return the FIRST that matches',
            $prompt,
            'Without strict-first-match ordering, the LLM can pick a later rule that loosely fits.',
        );
    }

    public function test_prompt_lists_currency_default(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt(['app_currency_default' => 'DOP']);

        $this->assertStringContainsString('default DOP', $prompt);
    }

    public function test_prompt_keeps_dr_specific_extraction_hints(): void
    {
        $prompt = GeminiPdfClassifierService::buildPrompt([]);

        $this->assertStringContainsString('NCF', $prompt, 'DR Comprobante Fiscal extraction must stay supported.');
        $this->assertStringContainsString('RNC', $prompt, 'DR tax-id extraction must stay supported.');
    }
}
