<?php

declare(strict_types=1);

namespace Kanvas\Connectors\CardNet\Ztrans\Services;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\CardNet\Ztrans\Webhook\CardNetZtransRedirectWebhookJob;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Souk\Payments\Models\Payments;
use Kanvas\Workflow\Actions\CreateReceiverWebhookAction;
use Kanvas\Workflow\DataTransferObject\ReceiverWebhook as ReceiverWebhookData;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;

class RedirectReceiverService
{
    public function ensure(Apps $app, Companies $company): ReceiverWebhook
    {
        return $this->find($app, $company) ?? $this->create($app, $company);
    }

    public function redirectUrl(Order $order, Payments $payment): string
    {
        $receiver = $this->ensure($order->app, $order->company);

        return $receiver->getUrl() . '?payment_id=' . $payment->id;
    }

    private function find(Apps $app, Companies $company): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->notDeleted()
            ->forJob(CardNetZtransRedirectWebhookJob::class)
            ->first();
    }

    private function create(Apps $app, Companies $company): ReceiverWebhook
    {
        $action = WorkflowAction::query()->where('model_name', CardNetZtransRedirectWebhookJob::class)->first()
            ?? throw new ValidationException(
                'The CardNet Ztrans redirect action is not registered. Run `kanvas:workflow-sync-actions` and connect again.'
            );

        return new CreateReceiverWebhookAction(
            new ReceiverWebhookData(
                app: $app,
                company: $company,
                user: $company->user,
                action: $action,
                name: 'CardNet Ztrans 3DS redirect',
                description: 'Landing page the card issuer redirects to after the 3DS challenge.',
                is_active: true,
                run_async: false,
            ),
        )->execute();
    }
}
