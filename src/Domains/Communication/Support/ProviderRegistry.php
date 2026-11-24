<?php

declare(strict_types=1);

namespace Kanvas\Communication\Support;

use InvalidArgumentException;
use Kanvas\Communication\Contracts\BuiltInCarrier;
use Kanvas\Communication\Contracts\CommunicationCarrier;
use Kanvas\Communication\Contracts\CommunicationProvider;
use Kanvas\Communication\Contracts\InboundSource;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;

final class ProviderRegistry
{
    /** @var array<string, class-string<CommunicationCarrier>>|null */
    private ?array $providers = null;

    /**
     * @return array<string, class-string<CommunicationCarrier>> keyed by slug
     */
    public function all(): array
    {
        if ($this->providers !== null) {
            return $this->providers;
        }

        $providers = [];

        foreach (config('communication.providers', []) as $provider) {
            if (! is_subclass_of($provider, CommunicationCarrier::class)) {
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

        foreach ($this->integrated() as $provider) {
            if (is_string($handler) && is_a($handler, $provider, true)) {
                return $provider;
            }
        }

        return null;
    }

    /**
     * @return array<string, class-string<CommunicationProvider>> the vendors a tenant integrates
     */
    public function integrated(): array
    {
        return array_filter(
            $this->all(),
            fn (string $carrier): bool => is_subclass_of($carrier, CommunicationProvider::class)
        );
    }

    /**
     * What carries the channel for a company that connected nothing for it.
     *
     * @return class-string<BuiltInCarrier>|null
     */
    public function builtInFor(ChannelTypeEnum $channel): ?string
    {
        foreach ($this->all() as $carrier) {
            if (is_subclass_of($carrier, BuiltInCarrier::class) && isset($carrier::transports()[$channel->value])) {
                return $carrier;
            }
        }

        return null;
    }

    public function provisioner(string $slug): Provisioner
    {
        return app($this->provider($slug)::provisioner());
    }

    public function inbound(string $slug): InboundSource
    {
        return app($this->provider($slug)::inbound());
    }

    /**
     * @return class-string<CommunicationCarrier>
     */
    public function get(string $slug): string
    {
        return $this->all()[$slug]
            ?? throw new InvalidArgumentException('No communication provider is registered as "' . $slug . '".');
    }

    /**
     * @return class-string<CommunicationProvider>
     */
    public function provider(string $slug): string
    {
        return $this->integrated()[$slug]
            ?? throw new InvalidArgumentException('"' . $slug . '" is not an integrated communication provider.');
    }
}
