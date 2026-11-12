<?php

declare(strict_types=1);

namespace Kanvas\Communication\Channels\Email\Providers\Mailgun\Services;

use Baka\Contracts\AppInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Client;
use Kanvas\Communication\Channels\Email\Providers\Mailgun\Webhooks\EmailDeliveryWebhookJob;
use Kanvas\Companies\Models\Companies;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Services\WorkflowActionCatalog;

/**
 * One delivery receiver per app and sending domain, because Mailgun keeps one URL per domain and
 * event: every company sending on that domain shares it, and EmailDeliveryWebhookJob takes the
 * company from the delivery, never from the receiver. Its company is only whoever set it up first.
 * Set up by hand (kanvas:mailgun-setup-delivery-webhooks), never from a send.
 */
class DeliveryWebhookReceiverService
{
    /**
     * Mailgun's webhook ids; a failure is reported under one of the two `*_fail` ids by severity.
     * `accepted` is left out: a send files its delivery accepted itself, so the event adds nothing.
     */
    public const array EVENTS = [
        'delivered',
        'permanent_fail',
        'temporary_fail',
        'complained',
        'unsubscribed',
    ];

    public function __construct(
        protected WorkflowActionCatalog $catalog = new WorkflowActionCatalog()
    ) {
    }

    public function find(AppInterface $app, string $domain): ?ReceiverWebhook
    {
        return ReceiverWebhook::query()
            ->fromApp($app)
            ->forJob(EmailDeliveryWebhookJob::class)
            ->notDeleted()
            ->where('configuration->mailgun->domain', strtolower($domain))
            ->orderByDesc('is_active')
            ->orderBy('id')
            ->first();
    }

    /**
     * Creates the receiver if the domain has none and points the domain's delivery events at it.
     * Running it again repoints them, which is how a domain moved by hand is set back. A receiver
     * someone switched off stays off: that is a decision, not a gap to fill.
     */
    public function ensure(
        AppInterface $app,
        Companies $company,
        string $domain,
        ?int $usersId = null
    ): ReceiverWebhook {
        $domain = strtolower($domain);
        $client = new Client($app);

        return Cache::lock('mailgun-delivery-webhooks:' . $app->getId() . ':' . $domain, 60)->block(
            30,
            function () use ($app, $company, $domain, $usersId, $client): ReceiverWebhook {
                $receiver = $this->find($app, $domain) ?? $this->create($app, $company, $domain, $usersId);

                if (! $receiver->is_active) {
                    return $receiver;
                }

                foreach (self::EVENTS as $event) {
                    $client->createOrUpdateWebhook($domain, $event, $receiver->getUrl());
                }

                $configuration = $receiver->configuration ?? [];
                $configuration['mailgun']['registered_at'] = now()->toIso8601String();
                $receiver->configuration = $configuration;
                $receiver->saveOrFail();

                return $receiver;
            }
        );
    }

    private function create(
        AppInterface $app,
        Companies $company,
        string $domain,
        ?int $usersId
    ): ReceiverWebhook {
        $action = $this->catalog->register(EmailDeliveryWebhookJob::class);

        return ReceiverWebhook::query()->create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'action_id' => $action->getId(),
            'users_id' => $usersId ?? $company->users_id,
            'name' => 'Mailgun Email Delivery Status: ' . $domain,
            'description' => 'Receives Mailgun\'s delivery events for ' . $domain . ' and records them on the outbound email.',
            'configuration' => ['mailgun' => ['domain' => $domain]],
            'is_active' => true,
        ]);
    }
}
