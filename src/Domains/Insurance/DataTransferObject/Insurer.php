<?php

declare(strict_types=1);

namespace Kanvas\Insurance\DataTransferObject;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Insurance\Enums\InsuranceCatalogStatusEnum;
use Kanvas\Insurance\Models\Insurer as InsurerModel;
use Spatie\LaravelData\Data;

class Insurer extends Data
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly string $name,
        public readonly ?string $slug = null,
        public readonly ?string $provider = null,
        public readonly ?string $logo = null,
        public readonly InsuranceCatalogStatusEnum $status = InsuranceCatalogStatusEnum::ACTIVE,
    ) {
    }

    public static function fromMultiple(
        AppInterface $app,
        CompanyInterface $company,
        array $data,
    ): self {
        return new self(
            app: $app,
            company: $company,
            name: (string) $data['name'],
            slug: $data['slug'] ?? null,
            provider: $data['provider'] ?? null,
            logo: $data['logo'] ?? null,
            status: InsuranceCatalogStatusEnum::from((string) ($data['status'] ?? InsuranceCatalogStatusEnum::ACTIVE->value)),
        );
    }

    public static function forUpdate(InsurerModel $insurer, array $data): self
    {
        return new self(
            app: $insurer->app,
            company: $insurer->company,
            name: (string) ($data['name'] ?? $insurer->name),
            slug: $insurer->slug,
            provider: array_key_exists('provider', $data) ? $data['provider'] : $insurer->provider,
            logo: array_key_exists('logo', $data) ? $data['logo'] : $insurer->logo,
            status: isset($data['status']) ? InsuranceCatalogStatusEnum::from((string) $data['status']) : $insurer->status,
        );
    }
}
