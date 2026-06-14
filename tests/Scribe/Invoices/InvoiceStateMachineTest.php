<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Invoices\Services\InvoiceStateMachine;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Pure unit test — no DB. Validates the state-machine transition graph from plan §7.1.
 */
class InvoiceStateMachineTest extends TestCase
{
    private InvoiceStateMachine $machine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->machine = new InvoiceStateMachine();
    }

    #[DataProvider('validTransitionProvider')]
    public function test_valid_transition_passes(InvoiceDocumentStatusEnum $from, InvoiceDocumentStatusEnum $to): void
    {
        $invoice = new Invoice();
        $invoice->document_status = $from;

        $this->machine->assertTransition($invoice, $to);

        $this->assertTrue(true, "{$from->value} → {$to->value} should be allowed.");
    }

    public static function validTransitionProvider(): array
    {
        return [
            'draft → issued' => [InvoiceDocumentStatusEnum::DRAFT, InvoiceDocumentStatusEnum::ISSUED],
            'issued → sent' => [InvoiceDocumentStatusEnum::ISSUED, InvoiceDocumentStatusEnum::SENT],
            'issued → paid' => [InvoiceDocumentStatusEnum::ISSUED, InvoiceDocumentStatusEnum::PAID],
            'issued → voided' => [InvoiceDocumentStatusEnum::ISSUED, InvoiceDocumentStatusEnum::VOIDED],
            'sent → paid' => [InvoiceDocumentStatusEnum::SENT, InvoiceDocumentStatusEnum::PAID],
            'sent → voided' => [InvoiceDocumentStatusEnum::SENT, InvoiceDocumentStatusEnum::VOIDED],
            'draft → draft (idempotent)' => [InvoiceDocumentStatusEnum::DRAFT, InvoiceDocumentStatusEnum::DRAFT],
            'paid → paid (idempotent)' => [InvoiceDocumentStatusEnum::PAID, InvoiceDocumentStatusEnum::PAID],
        ];
    }

    #[DataProvider('invalidTransitionProvider')]
    public function test_invalid_transition_throws(InvoiceDocumentStatusEnum $from, InvoiceDocumentStatusEnum $to): void
    {
        $invoice = new Invoice();
        $invoice->document_status = $from;

        $this->expectException(InvalidInvoiceTransitionException::class);
        $this->machine->assertTransition($invoice, $to);
    }

    public static function invalidTransitionProvider(): array
    {
        return [
            'draft → sent (must go via issued)' => [InvoiceDocumentStatusEnum::DRAFT, InvoiceDocumentStatusEnum::SENT],
            'draft → paid (must go via issued)' => [InvoiceDocumentStatusEnum::DRAFT, InvoiceDocumentStatusEnum::PAID],
            'draft → voided (drafts soft-deleted, not voided)' => [InvoiceDocumentStatusEnum::DRAFT, InvoiceDocumentStatusEnum::VOIDED],
            'paid → voided (use credit note instead)' => [InvoiceDocumentStatusEnum::PAID, InvoiceDocumentStatusEnum::VOIDED],
            'paid → issued (paid is terminal)' => [InvoiceDocumentStatusEnum::PAID, InvoiceDocumentStatusEnum::ISSUED],
            'voided → issued (voided is terminal)' => [InvoiceDocumentStatusEnum::VOIDED, InvoiceDocumentStatusEnum::ISSUED],
            'voided → paid (voided is terminal)' => [InvoiceDocumentStatusEnum::VOIDED, InvoiceDocumentStatusEnum::PAID],
            'sent → draft (cannot rewind)' => [InvoiceDocumentStatusEnum::SENT, InvoiceDocumentStatusEnum::DRAFT],
        ];
    }
}
