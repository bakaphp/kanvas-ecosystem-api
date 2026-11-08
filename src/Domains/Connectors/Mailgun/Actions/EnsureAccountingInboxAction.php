<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mailgun\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Kanvas\Connectors\Mailgun\Client;
use Kanvas\Connectors\Mailgun\Enums\ConfigurationEnum;
use Kanvas\Connectors\Mailgun\Enums\ReceiverConfigurationEnum;
use Kanvas\Connectors\Mailgun\Services\AccountingInboxService;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * The company accounting inbox, created once: a ScribePdfInboundWebhookJob receiver flagged
 * scribe_pdf_inbox, the address stored on it, and a Mailgun route forwarding that address to it.
 *
 * Idempotent: the receiver and its address are reused, the route is repointed (or adopted when one
 * already matches the address) instead of stacked. Without Mailgun credentials the receiver and the
 * address still exist; the route is reported missing and the next run creates it.
 */
class EnsureAccountingInboxAction
{
    public function __construct(
        private readonly AppInterface $app,
        private readonly CompanyInterface $company,
        private readonly Users $owner,
    ) {
    }

    /**
     * @return array{address: string, receiver_id: int, receiver_url: string, route_id: ?string, route_error: ?string}
     */
    public function execute(): array
    {
        $inboxes = new AccountingInboxService();
        $receiver = $inboxes->receiverFor($this->app, $this->company);
        // The address before the receiver: with no domain to receive on there is no inbox to create.
        $address = ($receiver !== null ? AccountingInboxService::addressOn($receiver) : null)
            ?? $inboxes->proposedAddressFor($this->app, $this->company);
        $receiver ??= $this->createReceiver($inboxes);

        $routeId = AccountingInboxService::routeOn($receiver);
        $routeError = null;

        try {
            $routeId = $this->ensureRoute($receiver, $address, $routeId);
        } catch (Throwable $e) {
            $routeError = $e->getMessage();
        }

        $receiver->configuration = [
            ...(array) $receiver->configuration,
            ReceiverConfigurationEnum::SCRIBE_PDF_INBOX->value => true,
            ReceiverConfigurationEnum::CAPTURE_FILES->value => true,
            ReceiverConfigurationEnum::INBOX_ADDRESS->value => $address,
            ReceiverConfigurationEnum::ROUTE_ID->value => $routeId,
        ];
        $receiver->is_active = true;
        $receiver->saveOrFail();

        return [
            'address' => $address,
            'receiver_id' => (int) $receiver->getId(),
            'receiver_url' => $receiver->getUrl(),
            'route_id' => $routeId,
            'route_error' => $routeError,
        ];
    }

    private function createReceiver(AccountingInboxService $inboxes): ReceiverWebhook
    {
        $action = $inboxes->workflowAction()
            ?? throw new ValidationException(
                'The Mailgun Accounting PDF Inbox workflow action is not registered. Run kanvas:workflow-sync-actions.'
            );

        $receiver = new ReceiverWebhook();
        $receiver->apps_id = $this->app->getId();
        $receiver->companies_id = $this->company->getId();
        $receiver->users_id = $this->owner->getId();
        $receiver->action_id = $action->getId();
        $receiver->name = 'Accounting inbox';
        $receiver->description = 'Supplier invoices and customer documents forwarded to the company accounting address';
        $receiver->configuration = [
            ReceiverConfigurationEnum::SCRIBE_PDF_INBOX->value => true,
            ReceiverConfigurationEnum::CAPTURE_FILES->value => true,
        ];
        $receiver->is_active = true;
        $receiver->run_async = true;
        $receiver->saveOrFail();

        return $receiver;
    }

    private function ensureRoute(ReceiverWebhook $receiver, string $address, ?string $routeId): string
    {
        if (Client::apiKeyFor($this->app) === '') {
            throw new ValidationException('No Mailgun API key is configured, so the route forwarding ' . $address . ' was not created.');
        }

        $signingKey = ConfigurationEnum::WEBHOOK_SIGNING_KEY->value;
        if ((string) ($this->company->get($signingKey) ?: $this->app->get($signingKey)) === '') {
            throw new ValidationException('No Mailgun webhook signing key is configured, so forwarded email would be refused.');
        }

        $client = new Client($this->app);
        $description = 'Kanvas accounting inbox — company ' . $this->company->getId();
        $existing = $routeId === null ? $client->findRouteByRecipient($address) : null;
        $targetId = $routeId ?? ($existing !== null ? (string) $existing['id'] : null);

        $route = $targetId !== null
            ? $client->updateRoute(
                $targetId,
                $address,
                $receiver->getUrl(),
                $description
            )
            : $client->createRoute($address, $receiver->getUrl(), $description);

        return (string) ($route['id'] ?? $targetId);
    }
}
