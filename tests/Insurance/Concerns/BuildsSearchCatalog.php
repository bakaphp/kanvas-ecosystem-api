<?php

declare(strict_types=1);

namespace Tests\Insurance\Concerns;

use Baka\Contracts\CompanyInterface;
use Closure;
use Illuminate\Support\Facades\Redis;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Actions\CreateInsurancePlanAction;
use Kanvas\Insurance\Actions\CreateInsurerAction;
use Kanvas\Insurance\DataTransferObject\CanonicalQuoteInput;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\DataTransferObject\QuoteResult;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\Insurer;
use PHPUnit\Framework\Attributes\After;
use Tests\Insurance\Fakes\FakeCanonicalProvider;

trait BuildsSearchCatalog
{
    private const PLATFORM_COMPANY_SETTING = 'B2B_MAIN_COMPANY_ID';

    private ?array $platformCompanyBackup = null;

    protected function searchCompany(): CompanyInterface
    {
        $company = $this->createUser()->getCurrentCompany();
        $this->pinPlatformCompany($company->getId());

        return $company;
    }

    protected function pinPlatformCompany(int $companyId): void
    {
        $app = app(Apps::class);
        $key = $app->getSettingsRedisPrimaryKey();

        $this->platformCompanyBackup ??= [$key, Redis::hGet($key, self::PLATFORM_COMPANY_SETTING)];
        $app->set(self::PLATFORM_COMPANY_SETTING, $companyId);
    }

    #[After]
    public function restorePlatformCompany(): void
    {
        if ($this->platformCompanyBackup === null) {
            return;
        }

        [$key, $previous] = $this->platformCompanyBackup;
        $this->platformCompanyBackup = null;

        if ($previous === false || $previous === null) {
            Redis::hDel($key, self::PLATFORM_COMPANY_SETTING);

            return;
        }

        Redis::hSet($key, self::PLATFORM_COMPANY_SETTING, $previous);
    }

    protected function bindFakeProvider(
        string $name,
        ?Closure $respond = null,
        bool $needsVehicleModelId = false,
        ?string $productCode = null,
    ): FakeCanonicalProvider {
        $provider = new FakeCanonicalProvider(
            $name,
            $respond ?? fn (string $planCode): QuoteResult => $this->quoteResult('Q-' . $planCode),
            $needsVehicleModelId,
            $productCode,
        );

        $this->app->bind('insurance_provider.' . $name, fn (): FakeCanonicalProvider => $provider);

        return $provider;
    }

    protected function quoteResult(string $quoteNumber, float $total = 116.0, ?float $commission = null): QuoteResult
    {
        return new QuoteResult(
            success: true,
            message: 'ok',
            quoteNumber: $quoteNumber,
            premium: $total / 1.16,
            tax: $total - $total / 1.16,
            total: $total,
            currency: 'DOP',
            commission: $commission,
            canEmit: true,
        );
    }

    protected function seedInsurer(CompanyInterface $company, string $provider): Insurer
    {
        return new CreateInsurerAction(
            InsurerData::from(
                app(Apps::class),
                $company,
                ['name' => 'Insurer ' . $provider . ' ' . uniqid(), 'provider' => $provider]
            )
        )->execute();
    }

    protected function seedPlan(Insurer $insurer, string $code, array $attributes = []): InsurancePlan
    {
        return new CreateInsurancePlanAction(
            InsurancePlanData::from(
                $insurer,
                array_merge(
                    ['name' => 'Plan ' . $code, 'type' => 'full', 'external_code' => $code],
                    $attributes
                )
            )
        )->execute();
    }

    protected function searchInput(
        CompanyInterface $company,
        InsuranceCoverageCategoryEnum $category = InsuranceCoverageCategoryEnum::FULL,
        ?string $model = null,
        ?string $version = null,
        array $underwriting = [],
    ): CanonicalQuoteInput {
        return CanonicalQuoteInput::fromMultiple(
            app(Apps::class),
            $company,
            [
                'category' => $category->value,
                'vehicle' => [
                    'brand' => 'Toyota',
                    'model' => $model ?? 'Corolla ' . uniqid(),
                    'version' => $version,
                    'year' => 2022,
                    'fuel_type' => 'gasoline_diesel',
                    'estimated_value' => 1000000,
                ],
                'underwriting' => $underwriting,
            ]
        );
    }
}
