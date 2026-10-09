<?php

declare(strict_types=1);

namespace Tests\Insurance\Concerns;

use Baka\Contracts\CompanyInterface;
use Closure;
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
use Tests\Insurance\Fakes\FakeCanonicalProvider;

trait BuildsSearchCatalog
{
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
    ): CanonicalQuoteInput {
        return CanonicalQuoteInput::fromMultiple(
            app(Apps::class),
            $company,
            [
                'category' => $category->value,
                'vehicle' => [
                    'brand' => 'Toyota',
                    'model' => $model ?? 'Corolla ' . uniqid(),
                    'year' => 2022,
                    'fuel_type' => 'gasoline_diesel',
                    'estimated_value' => 1000000,
                ],
            ]
        );
    }
}
