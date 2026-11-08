<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Organizations\Enums\TaxIdTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfDocument;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfLine;
use Kanvas\Scribe\ElectronicInvoicing\DataTransferObject\EcfTotals;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfItbisRateEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrEcfValidator;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfDocumentBuilder;
use Kanvas\Scribe\ElectronicInvoicing\Services\EcfTypeResolver;
use Kanvas\Scribe\ElectronicInvoicing\Support\EcfCompliance;
use Kanvas\Scribe\Invoices\Enums\DocumentTypeEnum;

class EcfDocumentBuilderTest extends EcfTestCase
{
    public function test_a_customer_with_an_rnc_or_cedula_gets_credito_fiscal(): void
    {
        $resolver = new EcfTypeResolver();
        $customer = $this->customer();

        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL, $resolver->resolve($this->draftInvoice($customer, '131-79432-1')));
        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL, $resolver->resolve($this->draftInvoice($customer, '00112345678')));
    }

    public function test_a_customer_without_a_valid_tax_id_gets_consumo(): void
    {
        $resolver = new EcfTypeResolver();
        $customer = $this->customer();

        $this->assertSame(EcfTypeEnum::CONSUMO, $resolver->resolve($this->draftInvoice($customer, null)));
        $this->assertSame(EcfTypeEnum::CONSUMO, $resolver->resolve($this->draftInvoice($customer, '12345')));
    }

    public function test_a_us_ein_is_not_an_rnc_even_though_it_has_nine_digits(): void
    {
        $customer = $this->customer('Too Corporation Americas LLC');
        $customer->forceFill(['tax_id' => '832056446', 'tax_id_type' => TaxIdTypeEnum::US_EIN])->saveQuietly();
        $invoice = $this->draftInvoice($customer, '832056446');

        $this->assertSame(EcfTypeEnum::CONSUMO, new EcfTypeResolver()->resolve($invoice));

        $document = new EcfDocumentBuilder()->build(
            $invoice,
            EcfTypeEnum::CONSUMO,
            'E320000000001',
            '2028-12-31'
        );
        $this->assertTrue($document->buyer->isForeign, 'The EIN goes to the provider as a foreign id, never as an RNC.');
        $this->assertSame([], new DrEcfValidator()->errors($document));
    }

    public function test_a_dominican_customer_whose_rnc_is_typed_still_gets_credito_fiscal(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['tax_id' => self::RNC, 'tax_id_type' => TaxIdTypeEnum::DO_RNC])->saveQuietly();

        $this->assertSame(EcfTypeEnum::CREDITO_FISCAL, new EcfTypeResolver()->resolve($this->draftInvoice($customer, self::RNC)));
    }

    public function test_a_type_preset_on_the_draft_wins_for_special_regimes(): void
    {
        $invoice = $this->draftInvoice($this->customer());
        EcfCompliance::writeDraftSettings($invoice, ['type' => EcfTypeEnum::GUBERNAMENTAL->value]);

        $this->assertSame(EcfTypeEnum::GUBERNAMENTAL, new EcfTypeResolver()->resolve($invoice));
    }

    public function test_the_document_carries_the_booked_totals_and_line_rates(): void
    {
        $invoice = $this->draftInvoice($this->customer(), self::RNC, [[1000.0, 180.0], [250.0, 0.0]]);

        $document = new EcfDocumentBuilder()->build(
            $invoice,
            EcfTypeEnum::CREDITO_FISCAL,
            'E310000000004',
            '2028-12-31'
        );

        $this->assertSame('', $document->systemRef, 'Before allocation there is no fiscal row and so no systemRef.');
        $this->assertSame(self::RNC, $document->buyer->taxId);
        $this->assertCount(2, $document->lines);
        $this->assertSame(EcfItbisRateEnum::RATE_18, $document->lines[0]->itbis);
        $this->assertSame(EcfItbisRateEnum::EXEMPT, $document->lines[1]->itbis);
        $this->assertSame(1000.0, $document->totals->taxed);
        $this->assertSame(250.0, $document->totals->exempt);
        $this->assertSame(180.0, $document->totals->itbis);
        $this->assertSame(1430.0, $document->totals->total);
        $this->assertSame([], new DrEcfValidator()->errors($document));
    }

    public function test_header_totals_follow_dgii_cuadratura_from_the_cent_rounded_lines(): void
    {
        // Two lines of 0.69 at 18%: DGII's TotalITBIS1 is MontoGravadoI1 × rate (1.38 × 0.18 = 0.25), not the
        // lines' own ITBIS added up (0.13 + 0.13), and MontoTotal is gravado + exento + ITBIS.
        $invoice = $this->draftInvoice($this->customer(), self::RNC, [[0.6944, 0.125], [0.6944, 0.125]]);

        $document = new EcfDocumentBuilder()->build(
            $invoice,
            EcfTypeEnum::CREDITO_FISCAL,
            'E310000000004',
            '2028-12-31'
        );

        $this->assertSame(1.38, $document->totals->taxed);
        $this->assertSame(0.25, $document->totals->itbis);
        $this->assertSame(1.63, $document->totals->total);
        $this->assertSame(
            round($document->totals->taxed + $document->totals->exempt + $document->totals->itbis, 2),
            $document->totals->total,
        );
        $this->assertSame([], new DrEcfValidator()->errors($document));
    }

    public function test_the_validator_requires_a_tax_id_on_credito_fiscal(): void
    {
        $invoice = $this->draftInvoice($this->customer(), null);
        $document = new EcfDocumentBuilder()->build(
            $invoice,
            EcfTypeEnum::CREDITO_FISCAL,
            '',
            ''
        );

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("requires the customer's RNC");

        new DrEcfValidator()->assertValid($document);
    }

    public function test_the_validator_rejects_a_malformed_tax_id_inconsistent_totals_too_many_lines_and_an_expired_range(): void
    {
        $line = fn (int $number): EcfLine => new EcfLine(
            lineNumber: $number,
            code: (string) $number,
            description: 'x',
            quantity: 1,
            unitPrice: 10,
            itbis: EcfItbisRateEnum::RATE_18,
            isService: true,
            discountAmount: 0,
            subtotal: 10,
            tax: 1.8,
        );

        $invoice = $this->draftInvoice($this->customer(), '12345');
        $base = new EcfDocumentBuilder()->build(
            $invoice,
            EcfTypeEnum::CREDITO_FISCAL,
            '',
            '2026-05-31'
        );

        $document = new EcfDocument(
            type: $base->type,
            encf: '',
            sequenceExpiresAt: '2026-05-31',
            issueDate: $base->issueDate,
            systemRef: $base->systemRef,
            buyer: $base->buyer,
            currency: 'DOP',
            exchangeRate: 1.0,
            isCredit: true,
            lines: array_map($line, range(1, 101)),
            totals: new EcfTotals(
                exempt: 0,
                taxed: 1010,
                itbis: 181.8,
                total: 999
            ),
        );

        $errors = implode(' | ', new DrEcfValidator()->errors($document));

        $this->assertStringContainsString('9 digits', $errors);
        $this->assertStringContainsString('at most 100', $errors);
        $this->assertStringContainsString('do not add up', $errors);
        $this->assertStringContainsString('expired on 2026-05-31', $errors);
    }

    public function test_consumo_under_the_anonymous_limit_needs_no_tax_id(): void
    {
        $invoice = $this->draftInvoice($this->customer(), null);
        $document = new EcfDocumentBuilder()->build(
            $invoice,
            EcfTypeEnum::CONSUMO,
            '',
            ''
        );

        $this->assertSame([], new DrEcfValidator()->errors($document));
    }

    public function test_a_credit_note_references_the_parent_e_ncf_with_full_or_partial_code(): void
    {
        $builder = new EcfDocumentBuilder();
        $parent = $this->draftInvoice($this->customer());
        $parent->issued_date = now();
        $parent->save();
        $this->fiscalRowFor($parent, 'E310000000004');

        $full = $parent->replicate(['uuid']);
        $full->document_type = DocumentTypeEnum::CREDIT_NOTE;
        $full->parent_invoice_id = $parent->getId();
        $full->regional_compliance = null;
        $full->save();

        $reference = $builder->reference($full);
        $this->assertSame('E310000000004', $reference?->encf);
        $this->assertSame(EcfDocumentBuilder::MODIFICATION_CANCELS, $reference?->modificationCode);

        $full->total_native = 10.0;
        $this->assertSame(EcfDocumentBuilder::MODIFICATION_AMOUNTS, $builder->reference($full)?->modificationCode);
    }
}
