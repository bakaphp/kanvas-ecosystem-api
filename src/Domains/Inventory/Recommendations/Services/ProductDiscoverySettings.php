<?php

declare(strict_types=1);

namespace Kanvas\Inventory\Recommendations\Services;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use InvalidArgumentException;
use Kanvas\Inventory\Recommendations\Enums\ConfigurationEnum;
use Kanvas\Souk\Enums\ConfigurationEnum as SoukConfigurationEnum;

/**
 * Both flags are tri-state on purpose: "unset" must keep whatever runs today (Typesense discovery,
 * legacy enrichment rules), while new LLM spend only starts on an explicit true. Callers pick the
 * accessor that matches that policy instead of defaulting a null themselves.
 */
final class ProductDiscoverySettings
{
    public const string SOURCE_COMPANY = 'company';
    public const string SOURCE_APP = 'app';
    public const string SOURCE_DEFAULT = 'default';
    public const array DEFAULT_QUERY_LANGUAGES = ['es', 'en'];

    private function __construct(
        private readonly AppInterface $app,
        private readonly ?CompanyInterface $company,
    ) {
    }

    /**
     * Whether discovery may return other companies' products in this app. The one read of
     * the setting every tenant filter in discovery branches on.
     */
    public static function crossCompanyVariants(AppInterface $app): bool
    {
        return (bool) $app->get(SoukConfigurationEnum::ALLOW_CROSS_COMPANY_VARIANTS->value);
    }

    /**
     * The catalog's languages, read by Algolia's stop words / plurals and by enrichment, which writes
     * the blurb in each of them and nothing else. A company's own list wins over the app's, so one
     * English-only catalog on a Spanish app does not pay for blurbs nobody searches in.
     *
     * @return list<string>
     */
    public static function queryLanguages(AppInterface $app, ?CompanyInterface $company = null): array
    {
        if ($company !== null) {
            $languages = self::parseLanguages(ConfigurationEnum::QUERY_LANGUAGES->listFrom($company));

            if ($languages !== []) {
                return $languages;
            }
        }

        $languages = self::parseLanguages(ConfigurationEnum::QUERY_LANGUAGES->listFrom($app));

        return $languages !== [] ? $languages : self::DEFAULT_QUERY_LANGUAGES;
    }

    /**
     * @param array<mixed> $configured
     * @return list<string>
     */
    private static function parseLanguages(array $configured): array
    {
        return array_values(array_unique(array_filter(
            array_map(
                static fn (mixed $language): string => is_string($language) ? strtolower(trim($language)) : '',
                $configured,
            ),
            static fn (string $language): bool => preg_match('/^[a-z]{2}$/', $language) === 1,
        )));
    }

    public static function for(AppInterface $app, ?CompanyInterface $company): self
    {
        return new self($app, $company);
    }

    public function enrichmentEnabled(): ?bool
    {
        return $this->resolve(ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED)[0];
    }

    public function discoveryEnabled(): ?bool
    {
        return $this->resolve(ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED)[0];
    }

    public function enrichmentExplicitlyOn(): bool
    {
        return $this->enrichmentEnabled() === true;
    }

    public function enrichmentExplicitlyOff(): bool
    {
        return $this->enrichmentEnabled() === false;
    }

    public function discoveryExplicitlyOff(): bool
    {
        return $this->discoveryEnabled() === false;
    }

    /**
     * @param string $flag the setting key, e.g. ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED->value
     */
    public function source(string $flag): string
    {
        $setting = ConfigurationEnum::tryFrom($flag);

        if (! in_array($setting, [ConfigurationEnum::PRODUCT_ENRICHMENT_ENABLED, ConfigurationEnum::PRODUCT_DISCOVERY_ENABLED], true)) {
            throw new InvalidArgumentException('Unknown product discovery flag: ' . $flag);
        }

        return $this->resolve($setting)[1];
    }

    /**
     * @return array{0: ?bool, 1: string}
     */
    private function resolve(ConfigurationEnum $setting): array
    {
        $value = self::normalize($this->company?->get($setting->value));

        if ($value !== null) {
            return [$value, self::SOURCE_COMPANY];
        }

        $value = self::normalize($this->app->get($setting->value));

        if ($value !== null) {
            return [$value, self::SOURCE_APP];
        }

        return [null, self::SOURCE_DEFAULT];
    }

    /**
     * The settings UI stores whatever the toggle sent, so a flag arrives as a bool, 0/1 or a string.
     * Anything unreadable counts as unset rather than guessing a side.
     */
    private static function normalize(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return match ((int) $value) {
                1 => true,
                0 => false,
                default => null,
            };
        }

        if (! is_string($value)) {
            return null;
        }

        return match (strtolower(trim($value))) {
            '1', 'true', 'yes', 'on' => true,
            '0', 'false', 'no', 'off' => false,
            default => null,
        };
    }
}
