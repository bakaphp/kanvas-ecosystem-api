<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use InvalidArgumentException;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

final class ProviderRegistry
{
    /** @var array<string, class-string<CommunicationProvider>>|null */
    private ?array $providers = null;

    /**
     * @return array<string, class-string<CommunicationProvider>> keyed by vendor slug
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];

        foreach (config('communication.providers', []) as $provider) {
            if (! is_subclass_of($provider, CommunicationProvider::class)) {
                throw new InvalidArgumentException($provider . ' is listed as a communication provider but does not implement the contract.');
            }

            $providers[$provider::slug()] = $provider;
        }

        return $this->providers = $providers;
    }

    /**
     * The provider behind an integration row, or null for any integration that does not carry
     * messages — the guard that keeps provisioning away from every other connector.
     *
     * @return class-string<CommunicationProvider>|null
     */
    public function forIntegration(IntegrationsCompany $integrationCompany): ?string
    {
        $handler = $integrationCompany->integration?->handler;

        foreach ($this->all() as $provider) {
            if (is_string($handler) && is_a($handler, $provider, true)) {
                return $provider;
            }
        }

        return null;
    }

    public function provisioner(string $slug): Provisioner
    {
        return app($this->get($slug)::provisioner());
    }

    public function inbound(string $slug): InboundSource
    {
        return app($this->get($slug)::inbound());
    }

    /**
     * @return class-string<CommunicationProvider>
     */
    public function get(string $slug): string
    {
        return $this->all()[$slug]
            ?? throw new InvalidArgumentException('No communication provider is registered as "' . $slug . '".');
    }
}
