<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Mercury\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Kanvas\Connectors\Mercury\Services\MercuryWebhookService;
use Kanvas\Connectors\Mercury\Webhooks\ProcessMercuryWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * Provisions the Kanvas receiver, then tells Mercury where to deliver.
 *
 * Order matters: the receiver must exist before Mercury is told the URL, or the first delivery lands on a
 * 404 — and Mercury does not retry 4xx.
 *
 * `run_async: true` is not optional. Mercury requires a 2xx within 5 SECONDS; re-fetching the transaction and
 * running the matcher inline would blow that budget, Mercury would mark the delivery failed, and we'd take a
 * duplicate on retry for work we'd already done.
 *
 * Idempotent: a company that already has a webhook keeps it. Re-registering would mint a new signing secret
 * and silently invalidate the one we hold, so every subsequent delivery would fail verification — and a 401
 * gets no retry.
 */
class RegisterMercuryWebhookAction
{
    public function __construct(
        public readonly AppInterface $app,
        public readonly CompanyInterface $company,
        public readonly UserInterface $user,
        protected readonly ?MercuryWebhookService $webhookService = null,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        $service = $this->webhookService ?? new MercuryWebhookService($this->app, $this->company);

        $receiver = $this->existingReceiver();

        if ($receiver === null) {
            $receiver = new CreateReceiverWebhookAction(
                new ReceiverWebhookData(
                    app: $this->app,
                    company: $this->company,
                    user: $this->user,
                    action: $this->workflowAction(),
                    name: 'Mercury Bank Feed',
                    description: 'Transactions and balances from Mercury.',
                    configuration: [],
                    is_active: true,
                    run_async: true,
                ),
            )->execute();
        }

        if ($service->registeredWebhookId() === null) {
            $service->register($this->receiverUrl($receiver));
        }

        return $receiver;
    }

    private function existingReceiver(): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->whereHas(
                'action',
                fn ($query) => $query->where('model_name', ProcessMercuryWebhookJob::class),
            )
            ->first();
    }

    private function workflowAction(): WorkflowAction
    {
        $action = WorkflowAction::query()
            ->where('model_name', ProcessMercuryWebhookJob::class)
            ->first();

        if ($action === null) {
            throw new ValidationException(
                'The Mercury webhook action is not registered. Run `kanvas:workflow-sync-actions` (it runs on '
                . 'deploy) so the job is discoverable, then connect the integration again.'
            );
        }

        return $action;
    }

    private function receiverUrl(ReceiverWebhook $receiver): string
    {
        return rtrim((string) config('app.url'), '/') . '/receiver/' . $receiver->uuid;
    }
}
