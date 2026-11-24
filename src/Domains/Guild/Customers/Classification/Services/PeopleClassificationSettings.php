<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\Classification\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Guild\Customers\Classification\DataTransferObject\PeopleTaxonomy;
use Kanvas\Guild\Customers\Classification\Enums\PeopleClassificationConfigurationEnum as ConfigurationEnum;

final readonly class PeopleClassificationSettings
{
    public const float DEFAULT_MIN_CONFIDENCE = 0.85;

    public function __construct(
        public bool $enabled,
        public ?PeopleTaxonomy $taxonomy,
        public float $minConfidence,
        public bool $applyTags,
    ) {
    }

    public static function for(AppInterface $app, ?CompanyInterface $company = null): self
    {
        $read = static fn (ConfigurationEnum $key): mixed => $company?->get($key->value) ?? $app->get($key->value);

        return new self(
            enabled: filter_var($read(ConfigurationEnum::ENABLED), FILTER_VALIDATE_BOOLEAN),
            taxonomy: PeopleTaxonomy::fromSetting($read(ConfigurationEnum::TAXONOMY)),
            minConfidence: (float) ($read(ConfigurationEnum::MIN_CONFIDENCE) ?? self::DEFAULT_MIN_CONFIDENCE),
            applyTags: filter_var($read(ConfigurationEnum::APPLY_TAGS), FILTER_VALIDATE_BOOLEAN),
        );
    }

    public function isActive(): bool
    {
        return $this->enabled && $this->taxonomy !== null;
    }
}
