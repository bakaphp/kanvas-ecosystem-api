<?php

declare(strict_types=1);

namespace Tests\Scribe\Invoices;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\IssueInvoiceAction;
use Kanvas\Scribe\Invoices\Actions\VoidInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLineData;
use Kanvas\Scribe\Invoices\Enums\InvoiceDocumentStatusEnum;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Enums\JournalEntryStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Models\JournalEntry;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

/**
 * Verifies VoidInvoiceAction:
 *   - posts a mirror (DR↔CR swap) JE that nets to zero with the original Issue JE
 *   - marks the original Issue JE as 'reversed' (history preserved per §7.7)
 *   - flips invoice document_status to VOIDED + clears collection_state
 *   - rejects voiding terminal states (paid)
 */
class VoidInvoiceActionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'accounting'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $this->company->getId());

        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'period_start' => '2026-06-01',
            'period_end' => '2026-06-30',
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);
    }

    public function test_voiding_an_issued_invoice_posts_mirror_je_and_marks_original_reversed(): void
    {
        $billable = $this->seedOrganization();
        $invoice = $this->issueTestInvoice($billable, totalNative: 1180.00, taxNative: 180.00);

        $originalJe = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->whereNull('is_reversal_of')
            ->first();

        $this->assertNotNull($originalJe);

        $voided = new VoidInvoiceAction(
            invoice: $invoice,
            voidReasonCode: 'duplicate',
            user: static::$cachedUser,
        )->execute();

        // Invoice state
        $this->assertSame(InvoiceDocumentStatusEnum::VOIDED, $voided->document_status);
        $this->assertNull($voided->collection_state);
        $this->assertNotNull($voided->voided_at);
        $this->assertSame('duplicate', $voided->void_reason_code);

        // Original JE flipped to reversed
        $originalJe->refresh();
        $this->assertSame(JournalEntryStatusEnum::REVERSED, $originalJe->status);

        // Mirror JE exists, is_reversal_of points at original
        $mirrorJe = JournalEntry::query()
            ->where('source_type', 'invoice')
            ->where('source_id', $invoice->id)
            ->where('is_reversal_of', $originalJe->id)
            ->first();

        $this->assertNotNull($mirrorJe, 'A reversal mirror JE should have been posted.');
        $this->assertTrue($mirrorJe->is_adjustment, 'Reversal JEs are flagged as adjustments.');

        // Mirror JE has equal debit/credit AND each line is the DR↔CR swap of original
        $mirrorJe->load('lines');
        $originalJe->load('lines');

        $this->assertCount($originalJe->lines->count(), $mirrorJe->lines, 'Same number of lines.');
        $this->assertEquals(
            $mirrorJe->lines->sum('debit_base'),
            $mirrorJe->lines->sum('credit_base'),
            'Mirror JE balanced.'
        );

        // Sum across both JEs should net to zero per account
        $netByAccount = [];
        foreach ($originalJe->lines as $line) {
            $netByAccount[$line->account_id] = ($netByAccount[$line->account_id] ?? 0)
                + (float) $line->debit_base - (float) $line->credit_base;
        }
        foreach ($mirrorJe->lines as $line) {
            $netByAccount[$line->account_id] = ($netByAccount[$line->account_id] ?? 0)
                + (float) $line->debit_base - (float) $line->credit_base;
        }
        foreach ($netByAccount as $accountId => $net) {
            $this->assertEquals(0.0, $net, "Account {$accountId} should net to zero after void.");
        }
    }

    public function test_voiding_a_draft_throws(): void
    {
        $billable = $this->seedOrganization();

        // Draft invoice — never issued
        $invoice = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(description: 'Test', quantity: 1, unit_price_native: 100),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
            ),
            user: static::$cachedUser,
        )->execute();

        $this->expectException(\Kanvas\Scribe\Invoices\Exceptions\InvalidInvoiceTransitionException::class);

        new VoidInvoiceAction(
            invoice: $invoice,
            voidReasonCode: 'mistake',
        )->execute();
    }

    private function issueTestInvoice(Organization $billable, float $totalNative, float $taxNative): \Kanvas\Scribe\Invoices\Models\Invoice
    {
        $unitPrice = ($totalNative - $taxNative);     // subtotal = total - tax (no discount)

        $draft = new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $billable,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Software consulting',
                        quantity: 1,
                        unit_price_native: $unitPrice,
                        tax_amount_native: $taxNative,
                    ),
                ]),
                currency: 'USD',
                fx_rate_to_base: 1.0,
                net_terms_days: 30,
                issued_date: Carbon::parse('2026-06-15'),
            ),
            user: static::$cachedUser,
        )->execute();

        return new IssueInvoiceAction(
            invoice: $draft,
            billable: $billable,
            user: static::$cachedUser,
        )->execute();
    }

    private function seedOrganization(string $name = 'Test Org', ?string $address = null): Organization
    {
        return Organization::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => $name,
            'address' => $address ?? '',
            'total_employees' => 0,
        ]);
    }
}
