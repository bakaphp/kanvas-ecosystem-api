<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Meta;

use Baka\Contracts\AppInterface;
use Illuminate\Http\Client\RequestException;
use InvalidArgumentException;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Concerns\HasProvisionerDefaults;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Connectors\Meta\Enums\ConfigurationEnum;
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
    use HasProvisionerDefaults;

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
        $wabaId = (string) $company->get(ConfigurationEnum::WABA_ID->value);

        // The account, not one of its numbers: a number Meta dropped must not fail the others. A
        // company set up before the WABA was kept has only its number to ask about.
        try {
            $client = Client::forCompany($app, $company);
            $wabaId !== ''
                ? $client->phoneNumbers($wabaId)
                : $client->phoneNumber((string) $company->get(ConfigurationEnum::PHONE_NUMBER_ID->value));
        } catch (Throwable $exception) {
            return 'Meta rejected the stored WhatsApp account or token: ' . $exception->getMessage();
        }

        return null;
    }

    /**
     * Every WABA posts to the shared callback rather than to $inboundUrl, the app's receiver: the
     * callback hands each app's numbers to that receiver.
     */
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
        $callback = MetaInbound::callbackUrl();

        return new InboundRegistration(shared: $this->sharedRegistry->attach(
            $connection,
            $wabaId,
            self::WABA_SUBSCRIPTION,
            $wabaId,
            function () use ($client, $wabaId, $callback): string {
                $current = $this->kanvasCallbacks($client->subscribedApps($wabaId));
                $foreign = array_values(array_diff($current, [$callback]));

                // Another Kanvas receiver already takes this WABA's messages — another workspace, or
                // a receiver since replaced. Overriding it would silently cut that one off.
                if ($foreign !== []) {
                    throw new SetupBlockedException(
                        'foreign_registration',
                        'WhatsApp Business Account ' . $wabaId . ' already sends to ' . $foreign[0] . '; it is not replaced automatically.'
                    );
                }

                // The shared callback is verified with the platform's token, not the tenant's.
                if ($current === []) {
                    $client->subscribe($wabaId, $callback, MetaInbound::verifyToken(CommunicationReceivers::platformApp()));
                }

                if (! in_array($callback, $this->kanvasCallbacks($client->subscribedApps($wabaId)), true)) {
                    throw new RuntimeException('Meta did not keep the webhook on WhatsApp Business Account ' . $wabaId);
                }

                return $callback;
            }
        ));
    }

    #[Override]
    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        $connection = $registration->latestConnection();

        if ($connection === null) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        try {
            $client = Client::forCompany($connection->app, $connection->company);
            $callbacks = $this->kanvasCallbacks($client->subscribedApps($registration->resource_key));
        } catch (RuntimeException | InvalidArgumentException) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        } catch (RequestException $exception) {
            // Meta being down or throttling says nothing about the subscription: that is retried.
            // A refusal (a revoked token, an account that is gone) leaves nothing of ours to remove.
            if ($exception->response->serverError() || $exception->response->tooManyRequests()) {
                throw $exception;
            }

            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        if (! in_array($registration->registered_value, $callbacks, true)) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client->unsubscribe($registration->resource_key);

        return CleanupTaskStatusEnum::DONE;
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
