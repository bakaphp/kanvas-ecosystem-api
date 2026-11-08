<?php

declare(strict_types=1);

namespace Tests\Scribe\ElectronicInvoicing;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Queue;
use Kanvas\Approvals\Services\ApprovalOriginService;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Scribe\DocumentSequences\Enums\DocumentTypeEnum;
use Kanvas\Scribe\DocumentSequences\Services\DocumentNumberAllocatorService;
use Kanvas\Scribe\ElectronicInvoicing\Enums\ConfigurationEnum;
use Kanvas\Scribe\ElectronicInvoicing\Regimes\Dr\EcfTypeEnum;
use Kanvas\Scribe\ElectronicInvoicing\Services\FiscalArtifactService;
use Kanvas\Scribe\FxRates\Models\FxRate;
use Kanvas\Scribe\Invoices\Actions\CreateInvoiceAction;
use Kanvas\Scribe\Invoices\DataTransferObject\Invoice as InvoiceData;
use Kanvas\Scribe\Invoices\DataTransferObject\InvoiceLine as InvoiceLineData;
use Kanvas\Scribe\Invoices\Models\Invoice;
use Kanvas\Scribe\Ledger\Enums\FiscalPeriodStatusEnum;
use Kanvas\Scribe\Ledger\Models\FiscalPeriod;
use Kanvas\Scribe\Ledger\Services\ChartOfAccountsSeederService;
use Spatie\LaravelData\DataCollection;
use Tests\Scribe\ElectronicInvoicing\Concerns\CreatesFiscalDocuments;
use Tests\Scribe\ScribeTestCase;
use Tests\Stubs\Scribe\FakeEcfProvider;
use Tests\Stubs\Scribe\InMemoryFiscalArtifactService;

/**
 * Each test runs on its own fresh company with the fake provider set: the provider setting is written to
 * Redis, which no transaction rolls back, so it must never land on the shared test company other
 * parallel tests issue invoices for.
 */
abstract class EcfTestCase extends ScribeTestCase
{
    use CreatesFiscalDocuments;

    public const string RNC = '131794321';
    public const string ISSUER_RNC = '101010101';
    public const float USD_DOP_RATE = 60.0;

    protected function afterScribeSetUp(): void
    {
        Queue::fake();
        $this->app->instance(FiscalArtifactService::class, new InMemoryFiscalArtifactService());
        FakeEcfProvider::reset();
        ApprovalOriginService::forget();
        config()->set('scribe.ecf_providers.fake', FakeEcfProvider::class);

        $company = Companies::factory()->create([
            'users_id' => Auth::id(),
            'is_active' => 1,
        ]);
        $company->associateApp($this->kanvasApp);
        $this->company = $company;

        new ChartOfAccountsSeederService()->seedUsDefault($this->kanvasApp->getId(), $company->getId());
        FiscalPeriod::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $company->getId(),
            'period_start' => $this->fiscalPeriodStart(),
            'period_end' => $this->fiscalPeriodEnd(),
            'status' => FiscalPeriodStatusEnum::OPEN,
        ]);

        // What a configured environment has: the Banco Central rate in force on the fixtures' issue date, so a USD
        // document can declare its DOP value. Tests of the missing-rate path delete it.
        FxRate::query()->updateOrCreate(
            ['apps_id' => $this->kanvasApp->getId(), 'base_currency' => 'USD', 'quote_currency' => 'DOP', 'rate_date' => '2026-06-15'],
            ['rate' => self::USD_DOP_RATE, 'source' => 'manual']
        );

        if ($this->companyIssuesEcf()) {
            $company->set(ConfigurationEnum::ECF_PROVIDER->value, 'fake');
            $company->set(ConfigurationEnum::ECF_ISSUER_TAX_ID->value, self::ISSUER_RNC);

            foreach ([EcfTypeEnum::CREDITO_FISCAL, EcfTypeEnum::CONSUMO, EcfTypeEnum::NOTA_CREDITO] as $type) {
                $this->configureRange(
                    $type,
                    4,
                    1000,
                    '2028-12-31'
                );
            }
        }
    }

    protected function tearDown(): void
    {
        ApprovalOriginService::forget();
        $this->company->del(ConfigurationEnum::ECF_PROVIDER->value);
        $this->company->del(ConfigurationEnum::ECF_ISSUER_TAX_ID->value);

        parent::tearDown();
    }

    protected function companyIssuesEcf(): bool
    {
        return true;
    }

    protected function configureRange(
        EcfTypeEnum $type,
        int $start,
        int $end,
        ?string $expiresAt
    ): void {
        new DocumentNumberAllocatorService()->configureRange(
            appsId: $this->kanvasApp->getId(),
            companiesId: $this->company->getId(),
            documentType: DocumentTypeEnum::forEcf($type),
            prefix: $type->prefix(),
            rangeStart: $start,
            rangeEnd: $end,
            expiresAt: $expiresAt !== null ? Carbon::parse($expiresAt) : null,
            padding: 10,
        );
    }

    protected function customer(?string $name = 'Cliente RNC SRL'): Organization
    {
        return $this->seedTestOrganization($name);
    }

    /**
     * @param list<array{0: float, 1: float}> $lines [unit price, ITBIS]
     */
    protected function draftInvoice(
        Organization $customer,
        ?string $taxId = self::RNC,
        array $lines = [[1000.0, 180.0]],
    ): Invoice {
        return new CreateInvoiceAction(
            data: new InvoiceData(
                app: $this->kanvasApp,
                company: $this->company,
                billable: $customer,
                lines: new DataCollection(InvoiceLineData::class, array_map(
                    fn (array $line): InvoiceLineData => new InvoiceLineData(
                        description: 'Servicio de consultoría',
                        quantity: 1,
                        unit_price_native: $line[0],
                        tax_rate: $line[1] > 0 ? 18.0 : null,
                        tax_amount_native: $line[1],
                    ),
                    $lines
                )),
                currency: 'DOP',
                fx_rate_to_base: 1.0,
                issued_date: Carbon::parse('2026-06-15'),
                due_date: Carbon::parse('2026-07-15'),
                billable_tax_id: $taxId,
            ),
            user: static::$cachedUser,
        )->execute();
    }
}
