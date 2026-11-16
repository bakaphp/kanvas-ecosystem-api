<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Microsoft\Actions;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Jobs\OAuthCallbackJob;
use Kanvas\Connectors\Microsoft\Support\MicrosoftTokenStore;
use Kanvas\Users\Models\Users;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * The receiver a Microsoft consent starts from: one per company for its own connection, and one for
 * the app's shared OneDrive on the company its owner saved from. Reused on every save so a connect
 * URL already handed out keeps working.
 */
class EnsureMicrosoftOAuthReceiverAction
{
    private const string PROVIDER = 'microsoft';

    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Users $user,
        private readonly MicrosoftTokenStore $store,
        private readonly ?string $redirectUrl = null,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        $action = OAuthCallbackJob::workflowAction();

        $existing = $this->existing($action);

        if ($existing !== null) {
            $existing->configuration = $this->withRedirect(
                is_array($existing->configuration) ? $existing->configuration : []
            );
            $existing->saveOrFail();

            return $existing;
        }

        $configuration = ['oauth_provider' => self::PROVIDER];
        $connection = $this->store->receiverConnection();

        if ($connection !== null) {
            $configuration[MicrosoftTokenStore::RECEIVER_CONNECTION] = $connection;
        }

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                action: $action,
                name: $connection === null
                    ? 'Microsoft OAuth · ' . $this->company->name
                    : 'OneDrive OAuth · ' . $this->app->name,
                description: $connection === null
                    ? 'Connects the company\'s Microsoft account: its Outlook and its own OneDrive.'
                    : 'Connects the OneDrive every company of the app shares.',
                configuration: $this->withRedirect($configuration),
                run_async: false,
            )
        )->execute();
    }

    /**
     * @param array<string, mixed> $configuration
     * @return array<string, mixed>
     */
    private function withRedirect(array $configuration): array
    {
        if ($this->redirectUrl !== null) {
            $configuration['redirect_url'] = $this->redirectUrl;
        } else {
            unset($configuration['redirect_url']);
        }

        return $configuration;
    }

    /**
     * Matched in PHP rather than by JSON path in SQL, so a receiver whose configuration is not valid
     * JSON cannot fail the lookup for every other receiver in the company.
     */
    private function existing(WorkflowAction $action): ?ReceiverWebhook
    {
        $connection = $this->store->receiverConnection();

        return ReceiverWebhook::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->where('action_id', $action->getId())
            ->where('is_deleted', 0)
            ->get()
            ->first(function (ReceiverWebhook $receiver) use ($connection): bool {
                $configuration = is_array($receiver->configuration) ? $receiver->configuration : [];

                return ($configuration['oauth_provider'] ?? null) === self::PROVIDER
                    && ($configuration[MicrosoftTokenStore::RECEIVER_CONNECTION] ?? null) === $connection;
            });
    }
}
