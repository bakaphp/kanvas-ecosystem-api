<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Services\InsuranceProductCategoryMapper;
use PHPUnit\Framework\TestCase;

class InsuranceProductCategoryMapperTest extends TestCase
{
    public function testUniversalLawProductMapsToLaw(): void
    {
        $this->assertSame(
            InsuranceCoverageCategoryEnum::LAW,
            InsuranceProductCategoryMapper::categoryFor(UniversalSegurosProvider::NAME, ProductEnum::PARA_TU_SEGURO_DE_LEY->value)
        );
    }

    public function testEveryOtherUniversalProductMapsToFull(): void
    {
        $fullProducts = [
            ProductEnum::PARA_TU_AUTO,
            ProductEnum::POR_SI_CHOCAS,
            ProductEnum::POR_LO_QUE_CONDUCES,
            ProductEnum::POR_SI_PIERDES_TU_AUTO,
        ];

        foreach ($fullProducts as $product) {
            $this->assertSame(
                InsuranceCoverageCategoryEnum::FULL,
                InsuranceProductCategoryMapper::categoryFor(UniversalSegurosProvider::NAME, $product->value),
                $product->value
            );
        }
    }

    public function testHumanoBasicPlansAreLawAndTheRestAreFull(): void
    {
        $expected = [
            PlanEnum::MI_AUTO_BASICO->value => InsuranceCoverageCategoryEnum::LAW,
            PlanEnum::MI_MOTO_BASICO->value => InsuranceCoverageCategoryEnum::LAW,
            PlanEnum::MI_AUTO_PREMIER->value => InsuranceCoverageCategoryEnum::FULL,
            PlanEnum::MI_AUTO_FULL->value => InsuranceCoverageCategoryEnum::FULL,
            PlanEnum::MI_AUTO_FLEX->value => InsuranceCoverageCategoryEnum::FULL,
        ];

        foreach ($expected as $code => $category) {
            $this->assertSame($category, InsuranceProductCategoryMapper::categoryFor(HumanoProvider::NAME, $code), $code);
        }
    }

    public function testEveryAdapterProductHasACategory(): void
    {
        foreach (ProductEnum::cases() as $product) {
            $this->assertInstanceOf(
                InsuranceCoverageCategoryEnum::class,
                InsuranceProductCategoryMapper::categoryFor(UniversalSegurosProvider::NAME, $product->value)
            );
        }

        foreach (PlanEnum::cases() as $plan) {
            $this->assertInstanceOf(
                InsuranceCoverageCategoryEnum::class,
                InsuranceProductCategoryMapper::categoryFor(HumanoProvider::NAME, $plan->value)
            );
        }
    }

    public function testUnknownProductIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        InsuranceProductCategoryMapper::categoryFor(UniversalSegurosProvider::NAME, 'A-XX');
    }

    public function testUnknownProviderIsRefused(): void
    {
        $this->expectException(ValidationException::class);

        InsuranceProductCategoryMapper::categoryFor('mapfre', 'anything');
    }
}
