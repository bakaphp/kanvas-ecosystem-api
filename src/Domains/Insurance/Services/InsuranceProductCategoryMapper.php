<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Services;

use Kanvas\Connectors\Humano\Enums\PlanEnum;
use Kanvas\Connectors\Humano\Providers\HumanoProvider;
use Kanvas\Connectors\UniversalSeguros\Enums\ProductEnum;
use Kanvas\Connectors\UniversalSeguros\Providers\UniversalSegurosProvider;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;

class InsuranceProductCategoryMapper
{
    public static function categoryFor(string $provider, string $productCode): InsuranceCoverageCategoryEnum
    {
        return match ($provider) {
            UniversalSegurosProvider::NAME => self::universal($productCode),
            HumanoProvider::NAME => self::humano($productCode),
            default => throw new ValidationException('No coverage category mapping for insurance provider ' . $provider),
        };
    }

    private static function universal(string $productCode): InsuranceCoverageCategoryEnum
    {
        $product = ProductEnum::tryFrom($productCode)
            ?? throw new ValidationException('Unknown Universal Seguros product ' . $productCode);

        return match ($product) {
            ProductEnum::PARA_TU_SEGURO_DE_LEY => InsuranceCoverageCategoryEnum::LAW,
            ProductEnum::PARA_TU_AUTO,
            ProductEnum::POR_LO_QUE_CONDUCES,
            ProductEnum::POR_SI_CHOCAS,
            ProductEnum::POR_SI_PIERDES_TU_AUTO => InsuranceCoverageCategoryEnum::FULL,
        };
    }

    private static function humano(string $productCode): InsuranceCoverageCategoryEnum
    {
        $plan = PlanEnum::tryFrom($productCode)
            ?? throw new ValidationException('Unknown Humano plan ' . $productCode);

        return match ($plan) {
            PlanEnum::MI_AUTO_BASICO,
            PlanEnum::MI_MOTO_BASICO => InsuranceCoverageCategoryEnum::LAW,
            PlanEnum::MI_AUTO_PREMIER,
            PlanEnum::MI_AUTO_FULL,
            PlanEnum::MI_AUTO_FLEX => InsuranceCoverageCategoryEnum::FULL,
        };
    }
}
