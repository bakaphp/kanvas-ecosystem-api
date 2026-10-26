<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\WhatsApp\Providers\Meta;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Kanvas\Communication\Channels\WhatsApp\Providers\Meta\Enums\ConfigurationEnum;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Connectors\WaSender\Workflows\AgentChannelResponderActivity as WaSenderResponderActivity;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use RuntimeException;
use Throwable;

/**
 * A number's webhooks are its WABA's: subscribing our app to the WABA, with our receiver as the
 * callback, is what sends every number in it to Kanvas. So the WABA is a shared resource and the
 * number owns nothing of its own.
 */
class MetaProvisioner implements Provisioner
{
    public const string WABA_SUBSCRIPTION = 'waba_subscription';

    public function __construct(
        protected SharedRegistry $sharedRegistry
    ) {
    }

    #[Override]
    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string
    {
        $secret = $app->get(ConfigurationEnum::APP_SECRET->value);

        if (! is_string($secret) || $secret === '') {
            return 'The app has no ' . ConfigurationEnum::APP_SECRET->value . ', so no WhatsApp webhook could be verified.';
        }

        $company = $integrationCompany->company;

        try {
            Client::forCompany($app, $company)->phoneNumber((string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        } catch (Throwable $exception) {
            return 'Meta rejected the stored WhatsApp number or token: ' . $exception->getMessage();
        }

        return null;
    }

    #[Override]
    public function legacyReceiverJobs(): array
    {
        return [];
    }

    /**
     * The slug every WhatsApp conversation is filed under, WaSender's included — and a tenant
     * already answering those with WaSender's responder keeps that rule instead of getting a second.
     */
    #[Override]
    public function autoReply(): ?array
    {
        return [
            'channel_slug_pattern' => '/^wa-chat-/',
            'legacy_responder' => WaSenderResponderActivity::class,
        ];
    }

    #[Override]
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        $wabaId = (string) ($connection->config['waba_id'] ?? '');

        if ($wabaId === '') {
            throw new SetupBlockedException(
                'waba_id_missing',
                'Save the WhatsApp Business Account id on the integration so its messages can reach Kanvas.'
            );
        }

        $client = Client::forCompany($connection->app, $connection->company);

        return new InboundRegistration(shared: $this->sharedRegistry->attach(
            $connection,
            $wabaId,
            self::WABA_SUBSCRIPTION,
            $wabaId,
            function () use ($client, $connection, $wabaId, $inboundUrl): string {
                $current = $this->kanvasCallbacks($client->subscribedApps($wabaId));
                $foreign = array_values(array_diff($current, [$inboundUrl]));

                // Another Kanvas receiver already takes this WABA's messages — another workspace, or
                // a receiver since replaced. Overriding it would silently cut that one off.
                if ($foreign !== []) {
                    throw new SetupBlockedException(
                        'foreign_registration',
                        'WhatsApp Business Account ' . $wabaId . ' already sends to ' . $foreign[0] . '; it is not replaced automatically.'
                    );
                }

                if ($current === []) {
                    $client->subscribe($wabaId, $inboundUrl, MetaInbound::verifyToken($connection->app));
                }

                if (! in_array($inboundUrl, $this->kanvasCallbacks($client->subscribedApps($wabaId)), true)) {
                    throw new RuntimeException('Meta did not keep the webhook on WhatsApp Business Account ' . $wabaId);
                }

                return $inboundUrl;
            }
        ));
    }

    /**
     * Payment method and number registration are read from Meta at send time for now: a refusal
     * fails that delivery with Meta's reason.
     */
    #[Override]
    public function missingPrerequisite(Connection $connection): ?string
    {
        return null;
    }

    #[Override]
    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum
    {
        return CleanupTaskStatusEnum::SUPERSEDED;
    }

    #[Override]
    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        $dependent = $registration->dependents()->latest('id')->first();
        $connection = Connection::query()->find($dependent?->connection_id);

        if ($connection === null) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        try {
            $client = Client::forCompany($connection->app, $connection->company);
            $callbacks = $this->kanvasCallbacks($client->subscribedApps($registration->resource_key));
        } catch (RuntimeException | RequestException | InvalidArgumentException) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        if (! in_array($registration->registered_value, $callbacks, true)) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client->unsubscribe($registration->resource_key);

        return CleanupTaskStatusEnum::DONE;
    }

    #[Override]
    public function legacySelection(IntegrationsCompany $integrationCompany): array
    {
        return [];
    }

    /**
     * @param list<array<string, mixed>> $subscriptions
     *
     * @return list<string>
     */
    private function kanvasCallbacks(array $subscriptions): array
    {
        $callbacks = [];

        foreach ($subscriptions as $subscription) {
            $callback = (string) ($subscription['override_callback_uri'] ?? '');

            if (SharedRegistry::isKanvasReceiverUrl($callback)) {
                $callbacks[] = $callback;
            }
        }

        return array_values(array_unique($callbacks));
    }
}
