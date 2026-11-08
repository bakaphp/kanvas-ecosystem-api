<?php

declare(strict_types=1);

namespace Kanvas\Connectors\WebPos\Actions;

use Baka\Users\Contracts\UserInterface;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\WebPos\Webhooks\WebPosResultWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

/**
 * WebPOS configures its result webhook by hand (no API), so setup only provisions the Kanvas receiver and
 * hands back the URL to give WebPOS. Idempotent: one receiver per company.
 */
class EnsureWebPosResultReceiverAction
{
    public function __construct(
        public readonly Apps $app,
        public readonly Companies $company,
        public readonly UserInterface $user,
    ) {
    }

    public function execute(): ReceiverWebhook
    {
        $existing = ReceiverWebhook::query()
            ->fromApp($this->app)
            ->fromCompany($this->company)
            ->notDeleted()
            ->forJob(WebPosResultWebhookJob::class)
            ->first();

        if ($existing !== null) {
            return $existing;
        }

        $action = WorkflowAction::query()->where('model_name', WebPosResultWebhookJob::class)->first();

        if ($action === null) {
            throw new ValidationException(
                'The WebPOS result webhook action is not registered. Run `kanvas:workflow-sync-actions` (it runs on deploy).'
            );
        }

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $this->app,
                company: $this->company,
                user: $this->user,
                action: $action,
                name: 'WebPOS e-CF Results',
                description: 'DGII results for e-CFs issued through WebPOS.',
                configuration: [],
                is_active: true,
                run_async: true,
            ),
        )->execute();
    }

    public static function url(ReceiverWebhook $receiver): string
    {
        return rtrim((string) config('app.url'), '/') . '/receiver/' . $receiver->uuid;
    }
}
