<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Filesystem\Models\Filesystem;
use Kanvas\Intelligence\Agents\Neuron\Tools\Accounting\ExtractInvoiceDataTool;
use Kanvas\Scribe\PdfIngest\Contracts\PdfClassifierServiceInterface;
use Kanvas\Scribe\PdfIngest\DataTransferObject\PdfClassificationResult;
use Kanvas\Scribe\PdfIngest\Enums\PdfIngestDocumentTypeEnum;
use Tests\Scribe\PdfIngest\Stubs\FakePdfClassifier;
use Tests\TestCase;

class ExtractInvoiceDataToolTest extends TestCase
{
    use DatabaseTransactions;

    public function test_extracts_vendor_and_total_from_the_pdf_via_the_classifier(): void
    {
        [$app, $company] = $this->context();

        $pdf = new Filesystem();
        $pdf->apps_id = $app->getId();
        $pdf->companies_id = $company->getId();
        $pdf->users_id = static::$cachedUser->getId();
        $pdf->name = 'invoice.pdf';
        $pdf->path = 'invoice.pdf';
        $pdf->url = 'https://cdn.example.com/invoice.pdf';
        $pdf->size = '1024';
        $pdf->file_type = 'application/pdf';
        $pdf->saveOrFail();

        $fake = new FakePdfClassifier();
        $fake->queue(new PdfClassificationResult(
            document_type: PdfIngestDocumentTypeEnum::VENDOR_INVOICE,
            confidence: 0.95,
            reasoning: 'Looks like a standard vendor invoice.',
            extracted: [
                'vendor_name' => 'Bandai',
                'currency' => 'EUR',
                'total' => 1250.75,
            ],
        ));
        app()->instance(PdfClassifierServiceInterface::class, $fake);

        $result = new ExtractInvoiceDataTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(filesystem_id: $pdf->getId(), from_email: 'vendor@bandai.com', subject: 'Invoice RE7186');

        $this->assertTrue($result['success']);
        $this->assertSame('vendor_invoice', $result['document_type']);
        $this->assertSame('Bandai', $result['extracted']['vendor_name']);
        $this->assertSame(1250.75, $result['extracted']['total']);
    }

    public function test_returns_a_humanized_error_when_the_file_does_not_exist(): void
    {
        [$app, $company] = $this->context();

        $result = new ExtractInvoiceDataTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(filesystem_id: 999999999);

        $this->assertFalse($result['success']);
        $this->assertSame('file_not_found', $result['reason']);
    }

    /**
     * filesystem_id is an LLM-supplied integer, so a hallucinated or prompt-injected id must resolve
     * to nothing rather than to another company's invoice. Scoping by apps_id alone left every
     * multi-company app one wrong number away from reading a neighbour's document.
     */
    public function test_a_file_from_another_company_in_the_same_app_does_not_resolve(): void
    {
        [$app, $company] = $this->context();

        $foreign = new Filesystem();
        $foreign->apps_id = $app->getId();
        $foreign->companies_id = Companies::factory()->create()->getId();
        $foreign->users_id = static::$cachedUser->getId();
        $foreign->name = 'their-invoice.pdf';
        $foreign->path = 'their-invoice.pdf';
        $foreign->url = 'https://cdn.example.com/their-invoice.pdf';
        $foreign->size = '1024';
        $foreign->file_type = 'application/pdf';
        $foreign->saveOrFail();

        $result = new ExtractInvoiceDataTool()
            ->withContext($app, $company, static::$cachedUser)
            ->__invoke(filesystem_id: $foreign->getId());

        $this->assertFalse($result['success']);
        $this->assertSame('file_not_found', $result['reason']);
    }

    /**
     * @return array{0: Apps, 1: Companies}
     */
    private function context(): array
    {
        $app = app(Apps::class);
        $company = static::$cachedUser->getCurrentCompany();

        return [$app, $company];
    }
}
