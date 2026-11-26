<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WaSender;

use Baka\Contracts\AppInterface;
use Baka\Support\Str;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Communication\Connections\DataTransferObject\InboundRegistration;
use Kanvas\Communication\Connections\DataTransferObject\OwnedResource;
use Kanvas\Communication\Connections\Enums\CleanupTaskStatusEnum;
use Kanvas\Communication\Connections\Models\CleanupTask;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Services\SharedRegistry;
use Kanvas\Communication\Contracts\Concerns\HasProvisionerDefaults;
use Kanvas\Communication\Contracts\Provisioner;
use Kanvas\Connectors\WaSender\Actions\RetireLegacyWhatsAppInboundAction;
use Kanvas\Connectors\WaSender\Enums\ConfigurationEnum;
use Kanvas\Connectors\WaSender\Enums\WebhookEventEnum;
use Kanvas\Connectors\WaSender\Exceptions\WaSenderRefusedException;
use Kanvas\Connectors\WaSender\Handlers\WaSenderHandler;
use Kanvas\Connectors\WaSender\Services\SessionService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Override;
use RuntimeException;

/**
 * A WaSender session posts to one webhook URL and signs with a secret WaSender generates. Sessions
 * are managed with the app's account token; a session someone wired by hand in WaSender's dashboard
 * has no id on file, and there is nothing to read or move for it.
 */
class WaSenderProvisioner implements Provisioner
{
    use HasProvisionerDefaults;

    public const string SESSION_WEBHOOK = 'session_webhook';

    /**
     * The class is deleted; this is the name the `actions` row of a receiver not yet moved carries.
     */
    public const string LEGACY_RECEIVER_JOB = 'Kanvas\\Connectors\\WaSender\\Webhooks\\ProcessWaSenderWebhookJob';

    /**
     * Whether the company has what a send needs. The session is not called: one paused on the phone
     * is still a connection, and its sends fail with WaSender's own reason.
     */
    #[Override]
    public function probe(IntegrationsCompany $integrationCompany, AppInterface $app): ?string
    {
        try {
            new Client($app, $integrationCompany->company);
        } catch (ValidationException) {
            return 'WaSender has no ' . ConfigurationEnum::BASE_URL->value . ' on the app or no '
                . ConfigurationEnum::API_KEY->value . ' on the company.';
        }

        return null;
    }

    #[Override]
    public function legacyReceiverJobs(): array
    {
        return [self::LEGACY_RECEIVER_JOB];
    }

    #[Override]
    public function registerInbound(Connection $connection, string $inboundUrl): InboundRegistration
    {
        $sessionId = (int) ($connection->config['session_id'] ?? 0);

        if ($sessionId === 0 || empty($connection->app->get(ConfigurationEnum::PERSONAL_ACCESS_TOKEN->value))) {
            return new InboundRegistration();
        }

        $sessions = $this->sessions($connection);
        $session = $sessions->getSession($sessionId);

        SharedRegistry::assertSettable(
            $session['webhook_url'] ?? null,
            $inboundUrl,
            'WaSender session ' . $sessionId
        );

        if (($session['webhook_url'] ?? null) !== $inboundUrl || empty($session['webhook_enabled'])) {
            $sessions->updateSession($sessionId, [
                'webhook_url' => $inboundUrl,
                'webhook_enabled' => true,
                'webhook_events' => WebhookEventEnum::subscribable(),
            ]);
            $session = $sessions->getSession($sessionId);
        }

        if (($session['webhook_url'] ?? null) !== $inboundUrl) {
            throw new RuntimeException('WaSender did not keep the webhook on session ' . $sessionId);
        }

        $this->keepSecret($connection, Str::trimToNull((string) ($session['webhook_secret'] ?? '')));

        return new InboundRegistration(owned: [
            new OwnedResource(
                self::SESSION_WEBHOOK,
                $connection->account_key,
                (string) $sessionId,
                $inboundUrl
            ),
        ]);
    }

    #[Override]
    public function cleanup(CleanupTask $task): CleanupTaskStatusEnum
    {
        if ($task->resource_type !== self::SESSION_WEBHOOK) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $sessions = $this->sessions($task->connection);
        $sessionId = (int) $task->external_id;

        // A session that was deleted has no webhook left to switch off.
        try {
            $webhookUrl = $sessions->getSession($sessionId)['webhook_url'] ?? null;
        } catch (WaSenderRefusedException) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        if ($webhookUrl !== $task->expected_value) {
            return CleanupTaskStatusEnum::SUPERSEDED;
        }

        $sessions->updateSession($sessionId, ['webhook_enabled' => false]);

        return CleanupTaskStatusEnum::DONE;
    }

    #[Override]
    public function retireLegacy(Connection $connection): array
    {
        return new RetireLegacyWhatsAppInboundAction($connection)->execute();
    }

    /**
     * What the receiver already knows about the session, for a company whose number was never
     * selected on the integration.
     */
    #[Override]
    public function legacySelection(IntegrationsCompany $integrationCompany): array
    {
        // A row from before may carry no app, so the company alone bounds the lookup.
        $receiver = WaSenderHandler::receivers()
            ->fromCompany((int) $integrationCompany->companies_id)
            ->when(
                $integrationCompany->apps_id !== null,
                fn (Builder $query) => $query->fromApp((int) $integrationCompany->apps_id)
            )
            ->first();

        return $receiver !== null ? WaSenderHandler::selectionFromReceiver($receiver) : [];
    }

    protected function sessions(Connection $connection): SessionService
    {
        return new SessionService($connection->app, $connection->company);
    }

    /**
     * WaSender signs with a secret of its own making; the receiver has to hold the current one or
     * every call after a webhook change is refused.
     */
    private function keepSecret(Connection $connection, ?string $secret): void
    {
        $receiver = WaSenderHandler::receiverFor($connection->app, $connection->company);

        if ($secret === null || $receiver === null || ($receiver->configuration['webhook_secret'] ?? null) === $secret) {
            return;
        }

        $receiver->configuration = [...($receiver->configuration ?? []), 'webhook_secret' => $secret];
        $receiver->saveOrFail();
    }
}
