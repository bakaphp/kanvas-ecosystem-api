<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Enums\ConfigurationEnum;

/**
 * Which tenants a provider is provisioned for. A provider that predates this domain already has an
 * integration on every tenant, and the sweep would move all their numbers' webhooks in one pass; those
 * providers are held back until the app, or the company, opts in.
 */
final class ProviderRollout
{
    /**
     * @param class-string<CommunicationProvider> $provider
     */
    public function allows(string $provider, AppInterface $app, CompanyInterface $company): bool
    {
        $slug = $provider::slug();

        if (! in_array($slug, config('communication.opt_in', []), true)) {
            return true;
        }

        return in_array($slug, $this->optedIn($company), true) || in_array($slug, $this->optedIn($app), true);
    }

    /**
     * @return list<string>
     */
    private function optedIn(AppInterface|CompanyInterface $owner): array
    {
        $value = $owner->get(ConfigurationEnum::PROVIDERS->value);

        if (is_string($value)) {
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }

        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $slug): string => strtolower(trim((string) $slug)),
            $value
        )));
    }
}
