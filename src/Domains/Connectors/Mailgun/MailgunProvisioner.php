<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\DataTransferObject\OwnedResource;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Concerns\HasProvisionerDefaults;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Connectors\Mailgun\Actions\RetireLegacyEmailInboundAction;
use Kanvas\Connectors\Mailgun\Handlers\MailgunHandler;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Models\ReceiverWebhook;
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
    use HasProvisionerDefaults;

    public const string ROUTE = 'mailgun_route';
    public const string DOMAIN_EVENTS = 'domain_events';

    /**
     * Every domain event the communication receiver records on a delivery.
     */
    public const array EVENTS = ['delivered', 'permanent_fail', 'temporary_fail', 'complained', 'unsubscribed', 'opened'];

    public function __construct(protected SharedRegistry $sharedRegistry = new SharedRegistry())
    {
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
        return RetireLegacyEmailInboundAction::RECEIVER_JOBS;
    }

    /**
     * The address's route forwards to $inboundUrl, and so do the domain's events unless a receiver
     * of another company on the domain already takes them. A route or a webhook someone else made
     * is never replaced; one of our own receivers is moved.
     */
    #[Override]
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        $client = $this->client($connection->app);
        $address = $connection->account_key;
        $domain = MailgunHandler::domainOf($connection);

        $route = $client->findRouteByRecipient($address);
        $current = $route !== null ? Client::routeForwardUrl($route) : null;

        SharedRegistry::assertSettable($current, $inboundUrl, 'Mailgun route for ' . $address);

        $description = 'Kanvas communication inbound for ' . $address;

        if ($route === null) {
            $route = $client->createRoute($address, $inboundUrl, $description);
        } elseif ($current !== $inboundUrl) {
            $route = $client->updateRoute(
                (string) $route['id'],
                $address,
                $inboundUrl,
                $description
            );
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

        if (! $client->deleteRoute((string) $task->external_id)) {
            throw new RuntimeException('Mailgun did not delete route ' . $task->external_id);
        }

        return CleanupTaskStatusEnum::DONE;
    }

    #[Override]
    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        $connection = $registration->latestConnection();

        if ($connection === null) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client = $this->client($connection->app);
        $domain = $registration->resource_key;
        $released = false;

        foreach (self::EVENTS as $event) {
            $urls = $client->webhookUrls($domain, $event);

            if ($urls === [$registration->registered_value]) {
                if (! $client->deleteWebhook($domain, $event)) {
                    throw new RuntimeException('Mailgun did not delete the ' . $event . ' webhook for ' . $domain);
                }

                $released = true;
            }
        }

        return $released ? CleanupTaskStatusEnum::DONE : CleanupTaskStatusEnum::SUPERSEDED;
    }

    #[Override]
    public function retireLegacy(Connection $connection): array
    {
        return new RetireLegacyEmailInboundAction($connection)->execute();
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
            function (?SharedRegistration $active) use ($client, $domain, $inboundUrl): string {
                $webhooks = [];

                foreach (self::EVENTS as $event) {
                    $webhooks[$event] = $client->webhookUrls($domain, $event);
                }

                $eventsUrl = $this->receiverStillServing($active, $webhooks) ?? $inboundUrl;

                foreach ($webhooks as $event => $current) {
                    foreach ($current as $url) {
                        SharedRegistry::assertSettable($url, $eventsUrl, 'Mailgun ' . $event . ' webhook for ' . $domain);
                    }

                    if ($current !== [$eventsUrl]) {
                        $client->createOrUpdateWebhook($domain, $event, $eventsUrl);
                    }

                    if ($client->webhookUrls($domain, $event) !== [$eventsUrl]) {
                        throw new RuntimeException('Mailgun did not keep the ' . $event . ' webhook on ' . $domain);
                    }
                }

                return $eventsUrl;
            }
        );
    }

    /**
     * Mailgun keeps one URL per domain and event, and the receiver behind it files each event on
     * the company of its delivery: the first company to register serves every company on the
     * domain. Its URL is kept for as long as Mailgun still sends there and that receiver is on;
     * moving it to each company that provisions would make them take the domain from each other.
     *
     * @param array<string, list<string>> $webhooks
     */
    private function receiverStillServing(?SharedRegistration $active, array $webhooks): ?string
    {
        $registered = Str::trimToNull($active?->registered_value);

        if ($registered === null || ! in_array($registered, array_merge(...array_values($webhooks)), true)) {
            return null;
        }

        $isOn = ReceiverWebhook::query()
            ->where('uuid', Str::afterLast($registered, '/'))
            ->where('is_active', true)
            ->notDeleted()
            ->exists();

        return $isOn ? $registered : null;
    }
}
