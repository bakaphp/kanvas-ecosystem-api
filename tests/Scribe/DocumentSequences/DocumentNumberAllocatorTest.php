<?php

declare(strict_types=1);

namespace Tests\Scribe\DocumentSequences;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Models\DocumentSequence;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Tests\TestCase;

/**
 * Verifies the atomic sequential allocation pattern.
 *
 * @see plan §5 accounting.document_sequences
 * @see plan §11 worked example — invoice number uniqueness via SELECT … FOR UPDATE
 */
class DocumentNumberAllocatorTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private const APPS_ID = 1;
    private const MCDR_COMPANY_ID = 100;
    private const KUSA_COMPANY_ID = 200;

    public function test_first_call_auto_creates_sequence_at_one(): void
    {
        $allocator = new DocumentNumberAllocatorService();

        $number = $allocator->allocate(
            self::APPS_ID,
            self::MCDR_COMPANY_ID,
            DocumentTypeEnum::INVOICE,
            defaultPrefix: 'MCDR-INV-2026-',
        );

        $this->assertSame('MCDR-INV-2026-1', $number);

        $row = DocumentSequence::query()
            ->where('apps_id', self::APPS_ID)
            ->where('companies_id', self::MCDR_COMPANY_ID)
            ->where('document_type', DocumentTypeEnum::INVOICE->value)
            ->first();

        $this->assertNotNull($row);
        $this->assertSame(2, (int) $row->next_value, 'next_value advances by 1 after first allocation.');
    }

    public function test_consecutive_calls_return_consecutive_numbers(): void
    {
        $allocator = new DocumentNumberAllocatorService();

        $first = $allocator->allocate(
            self::APPS_ID,
            self::MCDR_COMPANY_ID,
            DocumentTypeEnum::INVOICE,
            'MCDR-INV-'
        );
        $second = $allocator->allocate(
            self::APPS_ID,
            self::MCDR_COMPANY_ID,
            DocumentTypeEnum::INVOICE,
            'MCDR-INV-'
        );
        $third = $allocator->allocate(
            self::APPS_ID,
            self::MCDR_COMPANY_ID,
            DocumentTypeEnum::INVOICE,
            'MCDR-INV-'
        );

        $this->assertSame('MCDR-INV-1', $first);
        $this->assertSame('MCDR-INV-2', $second);
        $this->assertSame('MCDR-INV-3', $third);
    }

    /**
     * Each company has its own sequence — MCDR's #5 and KUSA's #5 coexist.
     */
    public function test_per_company_sequences_are_independent(): void
    {
        $allocator = new DocumentNumberAllocatorService();

        for ($i = 0; $i < 4; $i++) {
            $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE, 'MCDR-INV-');
        }
        $mcdr5 = $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE, 'MCDR-INV-');

        // Kanvas USA starts fresh
        $kusa1 = $allocator->allocate(self::APPS_ID, self::KUSA_COMPANY_ID, DocumentTypeEnum::INVOICE, 'KUSA-INV-');

        $this->assertSame('MCDR-INV-5', $mcdr5);
        $this->assertSame('KUSA-INV-1', $kusa1, 'KUSA sequence starts at 1, independent of MCDR.');
    }

    /**
     * Each document_type has its own sequence within the same company.
     */
    public function test_per_document_type_sequences_are_independent(): void
    {
        $allocator = new DocumentNumberAllocatorService();

        $inv1 = $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE, 'INV-');
        $inv2 = $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE, 'INV-');
        $qte1 = $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::QUOTE, 'QTE-');
        $crn1 = $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::CREDIT_NOTE, 'CRN-');

        $this->assertSame('INV-1', $inv1);
        $this->assertSame('INV-2', $inv2);
        $this->assertSame('QTE-1', $qte1, 'Quotes have their own sequence starting at 1.');
        $this->assertSame('CRN-1', $crn1, 'Credit notes have their own sequence starting at 1.');
    }

    public function test_peek_does_not_consume_a_number(): void
    {
        $allocator = new DocumentNumberAllocatorService();

        $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE, 'X-');

        $peek1 = $allocator->peek(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE);
        $peek2 = $allocator->peek(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE);

        $this->assertSame(2, $peek1);
        $this->assertSame(2, $peek2, 'Peek must not advance the counter.');

        $allocated = $allocator->allocate(self::APPS_ID, self::MCDR_COMPANY_ID, DocumentTypeEnum::INVOICE, 'X-');
        $this->assertSame('X-2', $allocated);
    }
}
