<?php

declare(strict_types=1);

namespace Kanvas\Connectors\TypeSafe\Services;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Connectors\TypeSafe\Enums\ConfigurationEnum;
use Kanvas\Connectors\TypeSafe\Enums\DecisionModeEnum;

/**
 * Every caller reads the app settings through here rather than directly, so "unconfigured means off"
 * is decided once instead of at each call site.
 */
final class TypeSafeConfigService
{
    /**
     * Pinned, not `jev-latest`. The alias moves when TypeSafe ships a version, and every threshold we
     * tune is tuned against one version's calibration — so following the alias would silently retune
     * live decisions. Moving this is a deliberate PR that re-runs the shadow comparison.
     */
    public const string DEFAULT_MODEL = 'jev-1.13.0';

    public function __construct(
        private readonly AppInterface $app,
    ) {
    }

    public function apiKey(): ?string
    {
        return $this->setting(ConfigurationEnum::TYPESAFE_API_KEY);
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== null;
    }

    public function model(): string
    {
        return $this->setting(ConfigurationEnum::TYPESAFE_MODEL) ?? self::DEFAULT_MODEL;
    }

    /**
     * An app with no key is OFF for everything, whatever the map says — which is exactly today's
     * behaviour for every decision, so adding the connector changes nothing until someone opts in.
     */
    public function decisionMode(string $decision): DecisionModeEnum
    {
        if (! $this->isConfigured()) {
            return DecisionModeEnum::OFF;
        }

        return DecisionModeEnum::fromSetting($this->decisions()[$decision] ?? null);
    }

    /**
     * @return array<array-key, mixed> Decision key => mode, as stored.
     */
    public function decisions(): array
    {
        $decisions = $this->app->get(ConfigurationEnum::TYPESAFE_DECISIONS->value);

        if (is_string($decisions)) {
            $decisions = json_decode($decisions, true);
        }

        return is_array($decisions) ? $decisions : [];
    }

    /**
     * Settings round-trip through json_decode, so an unset key can read back as `false` or `0` rather
     * than as a string — `trimmedStringOrNull` treats any non-string as absent, which is what we want.
     */
    private function setting(ConfigurationEnum $key): ?string
    {
        return Str::trimmedStringOrNull($this->app->get($key->value));
    }
}
