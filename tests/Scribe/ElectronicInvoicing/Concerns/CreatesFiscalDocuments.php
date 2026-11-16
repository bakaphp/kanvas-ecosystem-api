<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing\Concerns;

use Illuminate\Support\Carbon;
use Kanvas\Scribe\Bills\Models\Bill;
use Kanvas\Scribe\ElectronicInvoicing\Actions\CreateFiscalDocumentAction;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDirectionEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalDocumentStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Enums\FiscalSubmissionStatusEnum;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocument;
use Kanvas\Scribe\ElectronicInvoicing\Models\FiscalDocumentSubmission;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\DrFiscalRegime;
use Kanvas\Scribe\Expenses\Models\Expense;
use Kanvas\Scribe\Invoices\Models\Invoice;

/**
 * A fiscal row put straight on a document, for tests about what happens after allocation.
 */
trait CreatesFiscalDocuments
{
    protected function fiscalRowFor(
        Invoice|Bill|Expense $document,
        string $encf,
        FiscalDocumentStatusEnum $status = FiscalDocumentStatusEnum::PENDING,
        string $provider = 'webpos',
        string $environment = 'test',
        FiscalDirectionEnum $direction = FiscalDirectionEnum::ISSUED,
        ?string $issuerTaxId = null,
        array $attributes = [],
    ): FiscalDocument {
        $issuer = $issuerTaxId ?? 'company-' . $document->companies_id;

        return new CreateFiscalDocumentAction(
            entity: $document,
            direction: $direction,
            attributes: [
                'country' => DrFiscalRegime::COUNTRY,
                'provider' => $provider,
                'environment' => $environment,
                'document_type' => substr($encf, 1, 2),
                'series' => substr($encf, 0, 3),
                'number' => $encf,
                'issuer_tax_id' => $issuerTaxId,
                'identity_key' => DrFiscalRegime::identityKey($issuer, $encf),
                'status' => $status,
                'issued_at' => Carbon::today(),
                'retain_until' => Carbon::today()->addYears(DrFiscalRegime::RETENTION_YEARS),
                'regime_data' => [],
                ...$attributes,
            ],
        )->execute();
    }

    protected function submissionFor(
        FiscalDocument $fiscal,
        ?string $providerRef,
        int $attempt = 1,
        bool $withSystemRef = true
    ): FiscalDocumentSubmission {
        return FiscalDocumentSubmission::create([
            'apps_id' => $fiscal->apps_id,
            'companies_id' => $fiscal->companies_id,
            'fiscal_document_id' => $fiscal->getKey(),
            'system_ref' => $withSystemRef ? FiscalDocumentSubmission::newSystemRef() : null,
            'attempt' => $attempt,
            'status' => FiscalSubmissionStatusEnum::DELIVERED,
            'provider' => $fiscal->provider,
            'provider_ref' => $providerRef,
            'sent_at' => Carbon::now(),
        ]);
    }
}
