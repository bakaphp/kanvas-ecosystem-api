<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Actions;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Contracts\HashTableInterface;
use Illuminate\Support\Facades\Cache;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Connectors\Stripe\Webhooks\StripePaymentLinkWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Kanvas\Workflow\Models\WorkflowAction;
use Stripe\StripeClient;
use Throwable;

/**
 * One receiver and one Stripe endpoint per Stripe account, not per company: Stripe caps an account
 * at 16 endpoints, and every company on the app-level key shares that account. A shared receiver is
 * flagged so the webhook resolves the company from the paying lead instead of the receiver.
 */
class RegisterStripePaymentLinkWebhookAction
{
    protected StripeAccount $account;
    protected StripeClient $stripe;

    public function __construct(
        protected AppInterface $app,
        protected ?Companies $company = null,
        ?StripeClient $stripe = null
    ) {
        $this->account = StripeAccount::resolve($app, $company);
        $this->stripe = $stripe ?? $this->account->client();
    }

    public function execute(): ReceiverWebhook
    {
        return $this->registeredReceiver() ?? Cache::lock($this->lockKey(), 30)->block(
            10,
            fn () => $this->registeredReceiver() ?? $this->register()
        );
    }

    protected function register(): ReceiverWebhook
    {
        $receiverCompany = $this->receiverCompany();

        $receiver = ReceiverWebhook::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $receiverCompany->getId(),
            'users_id' => $receiverCompany->user->getId(),
            'action_id' => WorkflowAction::where('model_name', StripePaymentLinkWebhookJob::class)->firstOrFail()->getId(),
            'name' => 'Stripe Payment Link Webhook',
            'description' => 'Marks a get-deposit engagement submitted when its Stripe payment link is paid.',
            'is_active' => true,
        ]);

        try {
            $endpoint = $this->stripe->webhookEndpoints->create([
                'url' => $receiver->getUrl(),
                'enabled_events' => ['checkout.session.completed'],
                'description' => 'Kanvas payment links',
                'metadata' => [
                    'kanvas_receiver_uuid' => (string) $receiver->uuid,
                    'apps_id' => $this->app->getId(),
                ],
            ]);
        } catch (Throwable $e) {
            $receiver->softDelete();

            throw $e;
        }

        // Stripe returns the signing secret on create only.
        $receiver->configuration = [
            ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value => $endpoint->secret,
            ConfigurationEnum::STRIPE_WEBHOOK_ENDPOINT_ID->value => $endpoint->id,
            ConfigurationEnum::STRIPE_SHARED_APP_ACCOUNT->value => $this->account->sharedAppAccount,
        ];
        $receiver->saveOrFail();

        $this->accountOwner()->set(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value, $receiver->getId());

        return $receiver;
    }

    protected function registeredReceiver(): ?ReceiverWebhook
    {
        $receiverId = $this->accountOwner()->get(ConfigurationEnum::PAYMENT_LINK_RECEIVER_ID->value);

        if (! $receiverId) {
            return null;
        }

        return ReceiverWebhook::where('id', (int) $receiverId)->notDeleted()->first();
    }

    /**
     * A shared receiver only anchors on a company — the webhook resolves the real one from the lead —
     * so any company will do, and not every app has a main company configured.
     */
    protected function receiverCompany(): CompanyInterface
    {
        return $this->company ?? $this->app->getAppCompany();
    }

    protected function accountOwner(): HashTableInterface
    {
        return $this->account->sharedAppAccount ? $this->app : $this->company;
    }

    protected function lockKey(): string
    {
        return 'stripe-payment-link-webhook:' . ($this->account->sharedAppAccount
            ? 'app-' . $this->app->getId()
            : 'company-' . $this->company->getId());
    }
}
