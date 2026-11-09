<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Baka\Http\SafeUrlFetcher;
use Database\Seeders\ScribeEcfInvoiceTemplateSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\Filesystem\Services\PdfService;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\GenerateInvoicePdfTool;
use Kanvas\Scribe\ElectronicInvoicing\Actions\ApplyFiscalAnswerAction;
use Kanvas\Scribe\ElectronicInvoicing\Contracts\FiscalRegimeInterface;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\EcfPdfSourceEnum;
use Kanvas\Scribe\ElectronicInvoicing\Jobs\GenerateEcfPdfJob;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfInvoicePdfService;
use Kanvas\Scribe\Invoices\Actions\IssueCreditNoteAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Templates\Models\Templates;
use Spatie\LaravelData\DataCollection;
use Tests\Stubs\Scribe\FakeEcfProvider;

/**
 * Plan PR 2b.3: our own legal e-CF printout — the DGII fields, the QR from do_ecf.qr_url and the legends —
 * and the per-company choice between it and the provider's PDF.
 */
class EcfInvoicePdfTest extends EcfCompletionTestCase
{
    public function test_generating_our_fiscal_pdf_refreshes_an_old_copy_with_the_current_company_logo(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $this->company->set(ConfigurationEnum::ECF_PDF_SOURCE->value, EcfPdfSourceEnum::KANVAS->value);
        $invoice = $this->acceptedInvoice();
        $oldPdf = $this->createFilesystemRow();
        $invoice->addFile($oldPdf, FiscalRegimeInterface::INVOICE_PDF_FIELD);
        $logo = $this->createFilesystemRow(extension: 'png', fileType: 'image/png');
        $this->company->addFile($logo, 'photo');
        SafeUrlFetcher::fake(fn (): string => base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jRZkAAAAASUVORK5CYII='));

        $this->assertStringContainsString('<img class="logo" src="data:image/png;base64,', new EcfInvoicePdfService($invoice)->render());

        $result = new GenerateInvoicePdfTool()
            ->withContext($this->kanvasApp, $this->company, static::$cachedUser)
            ->__invoke(invoice_id: $invoice->getId());

        $this->assertTrue($result['generated'], json_encode($result));
        $this->assertNotSame($oldPdf->getId(), $result['filesystem_id']);
        $this->assertSame($result['filesystem_id'], $invoice->getFileByName(FiscalRegimeInterface::INVOICE_PDF_FIELD)->filesystem->getId());
    }

    protected function tearDown(): void
    {
        $this->company->del(ConfigurationEnum::ECF_PDF_SOURCE->value);
        $this->company->del(ConfigurationEnum::ECF_ISSUER_TAX_ID->value);

        parent::tearDown();
    }

    public function test_the_printout_carries_every_field_dgii_requires(): void
    {
        $this->company->set(ConfigurationEnum::ECF_ISSUER_TAX_ID->value, '101010101');
        $invoice = $this->acceptedInvoice();

        $html = new EcfInvoicePdfService($invoice)->render();

        $this->assertStringContainsString('Factura de Crédito Fiscal Electrónica', $html);
        $this->assertStringContainsString('E310000000004', $html);
        $this->assertStringContainsString('31-12-2028', $html, 'Sequence expiry.');
        $this->assertStringContainsString('RNC 101010101', $html);
        $this->assertStringContainsString(self::RNC, $html, "Buyer's RNC.");
        $this->assertStringContainsString('Código de seguridad: ABC123', $html);
        $this->assertStringContainsString('Fecha de firma: 01-06-2026', $html);
        $this->assertStringContainsString('src="data:image/png;base64,', $html, 'QR rendered from do_ecf.qr_url.');
        $this->assertStringContainsString('1,180.00', $html);
        $this->assertStringContainsString('Consultoría', $html);
        $this->assertMatchesRegularExpression('#<td>Subtotal</td><td class="num">1,000.00</td>#', $html, 'Subtotal before ITBIS.');
        $this->assertStringNotContainsString('class="logo"', $html, 'No photo on the company, so no logo; never the app avatar.');
        if ($invoice->due_date !== null) {
            $this->assertStringContainsString('Fecha límite de pago: ' . $invoice->due_date->format('d-m-Y'), $html);
        }
    }

    public function test_a_credit_note_printout_names_the_modified_e_ncf(): void
    {
        $invoice = $this->acceptedInvoice();
        $creditNote = new IssueCreditNoteAction(
            parentInvoice: $invoice,
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: null,
                lines: new DataCollection(InvoiceLineData::class, [
                    new InvoiceLineData(
                        description: 'Descuento',
                        quantity: 1,
                        unit_price_native: 100.0,
                        tax_rate: 18.0,
                        tax_amount_native: 18.0
                    ),
                ]),
                currency: 'DOP',
                fx_rate_to_base: 1.0,
                notes: 'Descuento por pronto pago',
            ),
            user: static::$cachedUser,
        )->execute();

        $html = new EcfInvoicePdfService($creditNote)->render();

        $this->assertStringContainsString('Nota de Crédito Electrónica', $html);
        $this->assertStringContainsString('E340000000004', $html);
        $this->assertStringContainsString('E310000000004', $html);
        $this->assertStringContainsString('Código de modificación 3', $html);
        $this->assertStringContainsString('Descuento por pronto pago', $html);
    }

    public function test_the_default_source_follows_the_provider_and_the_setting_overrides_it(): void
    {
        $this->assertSame(EcfPdfSourceEnum::PROVIDER, EcfPdfSourceEnum::forCompany($this->company, 'webpos'));
        $this->assertSame(EcfPdfSourceEnum::KANVAS, EcfPdfSourceEnum::forCompany($this->company, 'alanube'));

        $this->company->set(ConfigurationEnum::ECF_PDF_SOURCE->value, 'kanvas');
        $this->assertSame(EcfPdfSourceEnum::KANVAS, EcfPdfSourceEnum::forCompany($this->company, 'webpos'));
    }

    public function test_the_provider_pdf_is_attached_by_default_when_the_provider_sends_one(): void
    {
        FakeEcfProvider::$pdfBase64 = base64_encode('%PDF-1.4 provider copy');

        $invoice = $this->acceptedInvoice();

        $file = $invoice->getFileByName(ApplyFiscalAnswerAction::PDF_FIELD_NAME);
        $this->assertNotNull($file);
        $this->assertSame('e-cf-E310000000004.pdf', $file->filesystem->name);
        Queue::assertNotPushed(GenerateEcfPdfJob::class);
    }

    public function test_choosing_our_own_pdf_queues_the_render_and_it_attaches_under_its_own_field(): void
    {
        if (! is_executable(PdfService::BINARY_PATH)) {
            $this->markTestSkipped('wkhtmltopdf is not installed at ' . PdfService::BINARY_PATH);
        }

        $this->company->set(ConfigurationEnum::ECF_PDF_SOURCE->value, EcfPdfSourceEnum::KANVAS->value);
        FakeEcfProvider::$pdfBase64 = base64_encode('%PDF-1.4 provider copy');

        $invoice = $this->acceptedInvoice();

        $this->assertNull($invoice->getFileByName(ApplyFiscalAnswerAction::PDF_FIELD_NAME), 'The provider copy is not stored when ours is chosen.');
        $job = null;
        Queue::assertPushed(GenerateEcfPdfJob::class, function (GenerateEcfPdfJob $pushed) use ($invoice, &$job): bool {
            $job = $pushed;

            return $pushed->invoice->is($invoice);
        });

        $job->handle();

        $file = $invoice->refresh()->getFileByName(FiscalRegimeInterface::INVOICE_PDF_FIELD);
        $this->assertNotNull($file);
        $this->assertSame('e-cf-E310000000004.pdf', $file->filesystem->name);
    }

    public function test_with_no_provider_copy_our_render_is_queued_as_the_fallback(): void
    {
        FakeEcfProvider::$pdfBase64 = null;

        $invoice = $this->acceptedInvoice();

        Queue::assertPushed(GenerateEcfPdfJob::class, fn (GenerateEcfPdfJob $job): bool => $job->invoice->is($invoice));
    }

    public function test_a_company_template_row_restyles_the_printout(): void
    {
        $invoice = $this->acceptedInvoice();

        Templates::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'name' => EcfInvoicePdfService::TEMPLATE_NAME,
            'title' => 'Custom e-CF',
            'template' => '<p>Custom {{ ecf.encf }} {{ ecf.type_label }}</p>',
        ]);

        $this->assertStringContainsString('Custom E310000000004 Factura de Crédito Fiscal Electrónica', new EcfInvoicePdfService($invoice)->render());
    }

    public function test_the_seeder_stores_the_packaged_template_once(): void
    {
        $appsId = $this->kanvasApp->getId();
        DB::table('email_templates')->where('name', EcfInvoicePdfService::TEMPLATE_NAME)->where('apps_id', $appsId)->where('companies_id', 0)->delete();

        $this->assertTrue(ScribeEcfInvoiceTemplateSeeder::forApp($appsId));
        $this->assertFalse(ScribeEcfInvoiceTemplateSeeder::forApp($appsId));

        $row = DB::table('email_templates')->where('name', EcfInvoicePdfService::TEMPLATE_NAME)->where('apps_id', $appsId)->first();
        $this->assertSame('twig', $row->engine);
        $this->assertStringContainsString('ecf.qr_image', $row->template);
    }
}
