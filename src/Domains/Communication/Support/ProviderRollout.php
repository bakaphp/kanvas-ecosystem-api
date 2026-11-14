<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Support\Arr;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Enums\ConfigurationEnum;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

/**
 * Which tenants a provider is provisioned for. A provider that predates this domain already has an
 * integration on every tenant, and the sweep would move all their numbers' webhooks in one pass; those
 * providers are held back until the app, or the company, opts in.
 */
final class ProviderRollout
{
    /**
     * The provider this domain provisions the integration with, or null when it leaves the row
     * alone: it carries no messages, it belongs to another app, or its provider is still held back
     * for the tenant.
     *
     * @return class-string<CommunicationProvider>|null
     */
    public function providerFor(IntegrationsCompany $integrationCompany, Apps $app): ?string
    {
        $provider = new ProviderRegistry()->forIntegration($integrationCompany);

        if ($provider === null || (int) $integrationCompany->apps_id !== $app->getId()) {
            return null;
        }

        return $this->allows($provider, $app, $integrationCompany->company) ? $provider : null;
    }

    /**
     * The company's integrations this domain provisions.
     *
     * @return array<class-string<CommunicationProvider>, IntegrationsCompany> by provider
     */
    public function managed(Apps $app, Companies $company): array
    {
        $managed = [];

        $integrations = IntegrationsCompany::query()
            ->fromApp($app)
            ->fromCompany($company->getId())
            ->notDeleted()
            ->with('integration')
            ->get();

        foreach ($integrations as $integrationCompany) {
            $provider = $this->providerFor($integrationCompany, $app);

            if ($provider !== null) {
                $managed[$provider] = $integrationCompany;
            }
        }

        return $managed;
    }

    /**
     * Whether this domain provisions that integration for the company, so nothing else should point
     * the vendor's webhook anywhere.
     */
    public function manages(IntegrationsEnum $integration, Apps $app, Companies $company): bool
    {
        return array_any(
            array_keys($this->managed($app, $company)),
            fn (string $provider): bool => $provider::integration() === $integration
        );
    }

    /**
     * @param class-string<CommunicationProvider> $provider
     */
    private function allows(string $provider, AppInterface $app, CompanyInterface $company): bool
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
        return array_map(strtolower(...), Arr::fromSettingList($owner->get(ConfigurationEnum::PROVIDERS->value)));
    }
}
