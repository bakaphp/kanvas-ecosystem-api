<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Handlers\MailgunHandler;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Services\DeliveryWebhookReceiverService;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\AgentInboxWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\AgentProcessEmailWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\EmailDeliveryWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\EmailOpenedWebhookJob;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Workflows\AgentChannelResponderActivity;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\DataTransferObject\OwnedResource;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use RuntimeException;
use Throwable;

/**
 * An address receives through its own Mailgun route, which is the address's alone; the domain's
 * event webhooks (delivered, failed, opened, …) are one URL per domain and event, shared by every
 * address on it — so the route is owned and the domain's events are a shared resource.
 */
class MailgunProvisioner implements Provisioner
{
    public const string ROUTE = 'mailgun_route';
    public const string DOMAIN_EVENTS = 'domain_events';

    /**
     * The delivery events the legacy receiver takes, plus `opened`: one receiver for both.
     */
    public const array EVENTS = [...DeliveryWebhookReceiverService::EVENTS, 'opened'];

    public function __construct(
        protected SharedRegistry $sharedRegistry = new SharedRegistry()
    ) {
    }

    #[Override]
    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string
    {
        $domain = MailgunHandler::sendingDomain($app, $integrationCompany->company);

        if ($domain === null) {
            return 'No Mailgun domain is configured for this company.';
        }

        try {
            $this->client($app)->getDomain($domain);
        } catch (Throwable $exception) {
            return 'Mailgun rejected the stored domain or API key: ' . $exception->getMessage();
        }

        return null;
    }

    #[Override]
    public function legacyReceiverJobs(): array
    {
        return [
            AgentProcessEmailWebhookJob::class,
            AgentInboxWebhookJob::class,
            EmailDeliveryWebhookJob::class,
            EmailOpenedWebhookJob::class,
        ];
    }

    #[Override]
    public function autoReply(): ?array
    {
        return [
            'channel_slug_pattern' => '/^email-/',
            'legacy_responder' => AgentChannelResponderActivity::class,
        ];
    }

    /**
     * The address's route forwards to $inboundUrl, and so do the domain's events. A route or a
     * webhook someone else made is never replaced; one of our own receivers is moved.
     */
    #[Override]
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        $client = $this->client($connection->app);
        $address = $connection->account_key;
        $domain = (string) ($connection->config['domain'] ?? Str::after($address, '@'));

        $route = $client->findRouteByRecipient($address);
        $current = $route !== null ? Client::routeForwardUrl($route) : null;

        SharedRegistry::assertSettable($current, $inboundUrl, 'Mailgun route for ' . $address);

        $description = 'Kanvas communication inbound for ' . $address;

        if ($route === null) {
            $route = $client->createRoute($address, $inboundUrl, $description);
        } elseif ($current !== $inboundUrl) {
            $route = $client->updateRoute((string) $route['id'], $address, $inboundUrl, $description);
        }

        $routeId = (string) ($route['id'] ?? '');
        $readBack = $routeId !== '' ? $client->findRouteById($routeId) : null;

        if ($readBack === null || Client::routeForwardUrl($readBack) !== $inboundUrl) {
            throw new RuntimeException('Mailgun did not keep the route for ' . $address);
        }

        return new InboundRegistration(
            owned: [new OwnedResource(
                self::ROUTE,
                $address,
                $routeId,
                $inboundUrl
            )],
            shared: $this->registerDomainEvents(
                $client,
                $connection,
                $domain,
                $inboundUrl
            )
        );
    }

    #[Override]
    public function missingPrerequisite(Connection $connection): ?string
    {
        return null;
    }

    #[Override]
    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum
    {
        if ($task->resource_type !== self::ROUTE) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client = $this->client($task->connection->app);
        $route = $client->findRouteById((string) $task->external_id);

        if ($route === null || Client::routeForwardUrl($route) !== $task->expected_value) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client->deleteRoute((string) $task->external_id);

        return CleanupTaskStatusEnum::DONE;
    }

    #[Override]
    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        $dependent = $registration->dependents()->latest('id')->first();
        $connection = Connection::query()->find($dependent?->connection_id);

        if ($connection === null) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client = $this->client($connection->app);
        $domain = $registration->resource_key;
        $released = false;

        foreach (self::EVENTS as $event) {
            try {
                $urls = $client->webhookUrls($domain, $event);
            } catch (ValidationException) {
                return CleanupTaskStatusEnum::SUPERSEDED;
            }

            if ($urls === [$registration->registered_value]) {
                $client->deleteWebhook($domain, $event);
                $released = true;
            }
        }

        return $released ? CleanupTaskStatusEnum::DONE : CleanupTaskStatusEnum::SUPERSEDED;
    }

    /**
     * The address a company sends from is company configuration, which accounts() already reads.
     */
    #[Override]
    public function legacySelection(IntegrationsCompany $integrationCompany): array
    {
        return [];
    }

    protected function client(AppInterface $app): Client
    {
        return new Client($app);
    }

    private function registerDomainEvents(
        Client $client,
        Connection $connection,
        string $domain,
        string $inboundUrl
    ): SharedRegistration {
        return $this->sharedRegistry->attach(
            $connection,
            $domain,
            self::DOMAIN_EVENTS,
            $domain,
            function () use ($client, $domain, $inboundUrl): string {
                foreach (self::EVENTS as $event) {
                    $current = $client->webhookUrls($domain, $event);

                    foreach ($current as $url) {
                        SharedRegistry::assertSettable($url, $inboundUrl, 'Mailgun ' . $event . ' webhook for ' . $domain);
                    }

                    if ($current !== [$inboundUrl]) {
                        $client->createOrUpdateWebhook($domain, $event, $inboundUrl);
                    }

                    if ($client->webhookUrls($domain, $event) !== [$inboundUrl]) {
                        throw new RuntimeException('Mailgun did not keep the ' . $event . ' webhook on ' . $domain);
                    }
                }

                return $inboundUrl;
            }
        );
    }
}
