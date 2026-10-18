<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Builder;
use Kanvas\Communication\Webhooks\ProcessCommunicationWebhookJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

/**
 * One receiver per provider per tenant, all running the one communication webhook job, which reads
 * the provider from the receiver. Still one per provider because each vendor is given its own URL,
 * and Twilio signs over that exact URL. Shared by every connection the tenant has on the provider:
 * routing to a connection happens on the payload's verified identity, never on the receiver.
 */
class CommunicationReceivers
{
    public function __construct(
        protected WorkflowActionCatalog $catalog
    ) {
    }

    public function ensure(AppInterface $app, Companies $company, string $provider): ReceiverWebhook
    {
        $receiver = $this->find($app, $company, $provider);

        if ($receiver === null) {
            $action = $this->catalog->register(ProcessCommunicationWebhookJob::class);

            return ReceiverWebhook::query()->create([
                'apps_id' => $app->getId(),
                'companies_id' => $company->getId(),
                'action_id' => $action->getId(),
                'users_id' => $company->users_id,
                'name' => $action->name . ': ' . $provider,
                'description' => 'Created by Communication setup.',
                // `provider` is what the job dispatches on; `managed` marks the receivers this
                // domain created, the only ones it may ever switch off.
                'configuration' => ['communication' => ['provider' => $provider, 'managed' => true]],
                'is_active' => true,
            ]);
        }

        if (! $receiver->is_active) {
            $receiver->forceFill(['is_active' => true])->saveOrFail();
        }

        return $receiver;
    }

    public function find(AppInterface $app, Companies $company, string $provider): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($app)
            ->fromCompany($company)
            ->where('is_deleted', 0)
            ->where('configuration->communication->provider', $provider)
            ->whereHas('action', fn (Builder $query) => $query->where('model_name', ProcessCommunicationWebhookJob::class))
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }
}
