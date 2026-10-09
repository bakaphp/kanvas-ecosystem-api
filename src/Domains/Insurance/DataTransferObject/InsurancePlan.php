<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Enums\InsuranceCoverageCategoryEnum;
use Kanvas\Insurance\Models\InsurancePlan as InsurancePlanModel;
use Kanvas\Insurance\Models\Insurer;
use Spatie\LaravelData\Data;

class InsurancePlan extends Data
{
    public function __construct(
        public readonly Insurer $insurer,
        public readonly string $name,
        public readonly InsuranceCoverageCategoryEnum $type,
        public readonly ?string $slug = null,
        public readonly ?string $externalCode = null,
        public readonly ?float $basePrice = null,
        public readonly ?int $validityDays = null,
        public readonly ?float $deductible = null,
        public readonly InsuranceCatalogStatusEnum $status = InsuranceCatalogStatusEnum::ACTIVE,
        public readonly bool $sponsored = false,
        public readonly float $commissionRate = 0.0,
        public readonly int $recommendedScore = 0,
        public readonly ?array $coverages = null,
        public readonly ?array $exclusions = null,
        public readonly ?array $requirements = null,
    ) {
    }

    public static function fromMultiple(Insurer $insurer, array $data): self
    {
        return new self(
            insurer: $insurer,
            name: (string) $data['name'],
            type: InsuranceCoverageCategoryEnum::from((string) $data['type']),
            slug: $data['slug'] ?? null,
            externalCode: $data['external_code'] ?? null,
            basePrice: self::nullableFloat($data['base_price'] ?? null),
            validityDays: self::nullableInt($data['validity_days'] ?? null),
            deductible: self::nullableFloat($data['deductible'] ?? null),
            status: InsuranceCatalogStatusEnum::from((string) ($data['status'] ?? InsuranceCatalogStatusEnum::ACTIVE->value)),
            sponsored: (bool) ($data['sponsored'] ?? false),
            commissionRate: (float) ($data['commission_rate'] ?? 0),
            recommendedScore: (int) ($data['recommended_score'] ?? 0),
            coverages: self::normalizeCoverages($data['coverages'] ?? null),
            exclusions: self::normalizeExclusions($data['exclusions'] ?? null),
            requirements: self::normalizeRequirements($data['requirements'] ?? null),
        );
    }

    public static function forUpdate(InsurancePlanModel $plan, array $data): self
    {
        return new self(
            insurer: $plan->insurer,
            name: (string) ($data['name'] ?? $plan->name),
            type: isset($data['type']) ? InsuranceCoverageCategoryEnum::from((string) $data['type']) : $plan->type,
            slug: $plan->slug,
            externalCode: array_key_exists('external_code', $data) ? $data['external_code'] : $plan->external_code,
            basePrice: self::nullableFloat(array_key_exists('base_price', $data) ? $data['base_price'] : $plan->base_price),
            validityDays: self::nullableInt(array_key_exists('validity_days', $data) ? $data['validity_days'] : $plan->validity_days),
            deductible: self::nullableFloat(array_key_exists('deductible', $data) ? $data['deductible'] : $plan->deductible),
            status: isset($data['status']) ? InsuranceCatalogStatusEnum::from((string) $data['status']) : $plan->status,
            sponsored: (bool) ($data['sponsored'] ?? $plan->sponsored),
            commissionRate: (float) ($data['commission_rate'] ?? $plan->commission_rate),
            recommendedScore: (int) ($data['recommended_score'] ?? $plan->recommended_score),
            coverages: self::normalizeCoverages($data['coverages'] ?? null),
            exclusions: self::normalizeExclusions($data['exclusions'] ?? null),
            requirements: self::normalizeRequirements($data['requirements'] ?? null),
        );
    }

    private static function nullableFloat(mixed $value): ?float
    {
        return $value === null ? null : (float) $value;
    }

    private static function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private static function normalizeCoverages(?array $rows): ?array
    {
        if ($rows === null) {
            return null;
        }

        $normalized = [];

        foreach (array_values($rows) as $index => $row) {
            $normalized[] = [
                'name' => (string) $row['name'],
                'description' => $row['description'] ?? null,
                'limit_amount' => self::nullableFloat($row['limit_amount'] ?? null),
                'sort_order' => (int) ($row['sort_order'] ?? $index),
            ];
        }

        return $normalized;
    }

    private static function normalizeExclusions(?array $rows): ?array
    {
        return $rows === null ? null : array_map(
            fn (array $row): array => ['description' => (string) $row['description']],
            array_values($rows)
        );
    }

    private static function normalizeRequirements(?array $rows): ?array
    {
        return $rows === null ? null : array_map(
            fn (array $row): array => [
                'name' => (string) $row['name'],
                'condition' => $row['condition'] ?? null,
            ],
            array_values($rows)
        );
    }
}
