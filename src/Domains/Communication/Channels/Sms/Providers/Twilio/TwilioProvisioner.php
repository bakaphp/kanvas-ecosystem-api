<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Sms\Providers\Twilio;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Services\A2pRegistration;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioMessageStatusWebhookJob;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Webhooks\ProcessTwilioWebhookJob;
use Kanvas\Communication\Channels\Sms\Providers\Twilio\Workflows\AgentChannelResponderActivity;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\DataTransferObject\OwnedResource;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Exceptions\SetupBlockedException;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use RuntimeException;
use Throwable;
use Twilio\Rest\Client as TwilioClient;

/**
 * A number answers to its own `smsUrl` unless it sits in a Messaging Service, where the service's
 * `inboundRequestUrl` decides for every number in it — so a service is a shared resource and a
 * plain number is not.
 */
class TwilioProvisioner implements Provisioner
{
    public const string NUMBER_SMS_URL = 'twilio_number_sms_url';
    public const string MESSAGING_SERVICE = 'messaging_service';

    public function __construct(
        protected SharedRegistry $sharedRegistry = new SharedRegistry()
    ) {
    }

    #[Override]
    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string
    {
        try {
            $account = $this->client($integrationCompany->company, $app)->api->v2010->account->fetch();
        } catch (Throwable $exception) {
            return 'Twilio rejected the stored credentials: ' . $exception->getMessage();
        }

        return $account->status === 'active' ? null : 'Twilio account is ' . $account->status;
    }

    #[Override]
    public function legacyReceiverJobs(): array
    {
        return [ProcessTwilioWebhookJob::class, ProcessTwilioMessageStatusWebhookJob::class];
    }

    #[Override]
    public function autoReply(): ?array
    {
        return [
            'channel_slug_pattern' => '/^twilio-/',
            'legacy_responder' => AgentChannelResponderActivity::class,
        ];
    }

    #[Override]
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        $client = $this->client($connection->company, $connection->app);
        $serviceSid = Str::trimToNull($connection->config['messaging_service_sid'] ?? null);

        if ($serviceSid !== null) {
            return new InboundRegistration(shared: $this->registerOnService(
                $client,
                $connection,
                $serviceSid,
                $inboundUrl
            ));
        }

        $numberSid = $this->numberSid($client, $connection);
        $number = $client->incomingPhoneNumbers($numberSid)->fetch();

        SharedRegistry::assertSettable($number->smsUrl, $inboundUrl, 'Twilio number ' . $connection->account_key);

        if ($number->smsUrl !== $inboundUrl) {
            $client->incomingPhoneNumbers($numberSid)
                ->update(['smsUrl' => $inboundUrl, 'smsMethod' => 'POST']);
        }

        if ($client->incomingPhoneNumbers($numberSid)->fetch()->smsUrl !== $inboundUrl) {
            throw new RuntimeException('Twilio did not keep the inbound URL on ' . $connection->account_key);
        }

        return new InboundRegistration(owned: [
            new OwnedResource(
                self::NUMBER_SMS_URL,
                $connection->account_key,
                $numberSid,
                $inboundUrl
            ),
        ]);
    }

    #[Override]
    public function missingPrerequisite(Connection $connection): ?string
    {
        $from = Str::trimToNull($connection->config['messaging_service_sid'] ?? null) === null
            ? $connection->account_key
            : null;
        $status = A2pRegistration::blockingStatus($connection->company, $from);

        return $status !== null ? 'a2p_registration_' . $status : null;
    }

    #[Override]
    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum
    {
        if ($task->resource_type !== self::NUMBER_SMS_URL) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $connection = $task->connection;
        $client = $this->client($connection->company, $connection->app);
        $number = $client->incomingPhoneNumbers((string) $task->external_id)->fetch();

        if ($number->smsUrl !== $task->expected_value) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $client->incomingPhoneNumbers((string) $task->external_id)->update(['smsUrl' => '']);

        return CleanupTaskStatusEnum::DONE;
    }

    #[Override]
    public function releaseShared(SharedRegistration $registration): CleanupTaskStatusEnum
    {
        $connection = $registration->latestConnection();

        if ($connection === null) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $service = $this->client($connection->company, $connection->app)
            ->messaging
            ->v1
            ->services($registration->resource_key);

        if ($service->fetch()->inboundRequestUrl !== $registration->registered_value) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $service->update(['inboundRequestUrl' => '']);

        return CleanupTaskStatusEnum::DONE;
    }

    /**
     * The number a company sends from is company configuration, which accounts() already reads;
     * nothing has to be derived onto the row.
     */
    #[Override]
    public function legacySelection(IntegrationsCompany $integrationCompany): array
    {
        return [];
    }

    protected function client(Companies $company, AppInterface $app): TwilioClient
    {
        return Client::getInstanceByCompanyOrApp($company, $app);
    }

    private function registerOnService(
        TwilioClient $client,
        Connection $connection,
        string $serviceSid,
        string $inboundUrl
    ): SharedRegistration {
        return $this->sharedRegistry->attach(
            $connection,
            (string) $client->getAccountSid(),
            self::MESSAGING_SERVICE,
            $serviceSid,
            function () use ($client, $serviceSid, $inboundUrl): string {
                $service = $client->messaging->v1->services($serviceSid);
                $current = $service->fetch()->inboundRequestUrl;

                SharedRegistry::assertSettable($current, $inboundUrl, 'Twilio Messaging Service ' . $serviceSid);

                if ($current !== $inboundUrl) {
                    $service->update(['inboundRequestUrl' => $inboundUrl, 'inboundMethod' => 'POST']);
                }

                if ($service->fetch()->inboundRequestUrl !== $inboundUrl) {
                    throw new RuntimeException('Twilio did not keep the inbound URL on Messaging Service ' . $serviceSid);
                }

                return $inboundUrl;
            }
        );
    }

    private function numberSid(TwilioClient $client, Connection $connection): string
    {
        $sid = Str::trimToNull($connection->config['sid'] ?? null);

        if ($sid !== null) {
            return $sid;
        }

        $numbers = $client->incomingPhoneNumbers->read(['phoneNumber' => $connection->account_key], 1);

        if ($numbers === []) {
            throw new SetupBlockedException(
                'number_not_on_account',
                $connection->account_key . ' is not a number on this Twilio account.'
            );
        }

        return $numbers[0]->sid;
    }
}
