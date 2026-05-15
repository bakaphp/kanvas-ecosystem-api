<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Stripe\Handlers;

use Illuminate\Support\Facades\Log;
use Kanvas\Connectors\Contracts\BaseIntegration;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Enums\StatusEnum;
use Kanvas\Workflow\Integrations\Models\IntegrationsCompany;
use Kanvas\Workflow\Integrations\Models\Status;
use Kanvas\Workflow\Models\Integrations;
use Override;
use Stripe\Balance;
use Stripe\Exception\AuthenticationException;
use Stripe\Stripe;
use Throwable;

class StripeHandler extends BaseIntegration
{
    #[Override]
    public function setup(): bool
    {
        $stripeSecretKey = $this->data['stripe_secret_key'] ?? null;

        if (empty($stripeSecretKey)) {
            throw new ValidationException('Stripe secret key is required.');
        }

        // Validate credentials before persisting anything.
        Stripe::setApiKey($stripeSecretKey);

        try {
            Balance::retrieve();
        } catch (AuthenticationException $e) {
            throw new ValidationException('Invalid Stripe API key: ' . $e->getMessage());
        }

        // Invariant: Cashier-side code still reads from $app->get(STRIPE_SECRET_KEY). The
        // processor reads from $company->get(STRIPE_SECRET_KEY). Both must stay in sync at
        // setup time. Do NOT introduce a fallback from the processor to $app->get() —
        // that would break tenant isolation (Req 1 AC 5).
        $this->app->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $stripeSecretKey);
        $this->company->set(ConfigurationEnum::STRIPE_SECRET_KEY->value, $stripeSecretKey);

        $stripePublishableKey = $this->data['stripe_publishable_key'] ?? null;
        $stripeWebhookSecret = $this->data['stripe_webhook_secret'] ?? null;

        if (! empty($stripePublishableKey)) {
            $this->company->set(ConfigurationEnum::STRIPE_PUBLISHABLE_KEY->value, $stripePublishableKey);
        }

        if (! empty($stripeWebhookSecret)) {
            $this->company->set(ConfigurationEnum::STRIPE_WEBHOOK_SECRET->value, $stripeWebhookSecret);
        }

        $this->activateIntegration();

        return true;
    }

    private function activateIntegration(): void
    {
        // Phase 8 will add the integrations seed row + narrow this catch. Today the row may
        // not exist yet; swallow + log so setup succeeds, then re-tighten once the seed lands.
        try {
            $integration = Integrations::where('name', IntegrationsEnum::STRIPE->value)->first();

            if ($integration === null) {
                Log::warning('StripeHandler: integrations row for "stripe" not found — skipping status flip. Run the IntegrationsSeeder to seed it.');

                return;
            }

            $activeStatus = Status::getDefaultStatusByName(StatusEnum::ACTIVE->value);

            IntegrationsCompany::firstOrCreate(
                [
                    'companies_id' => $this->company->getId(),
                    'integrations_id' => $integration->getId(),
                ],
                [
                    'status_id' => $activeStatus->getId(),
                    'region_id' => $this->region->getId(),
                ]
            )->update(['status_id' => $activeStatus->getId()]);
        } catch (Throwable $e) {
            Log::warning('StripeHandler: could not flip IntegrationsCompany status to ACTIVE — ' . $e->getMessage());
        }
    }
}
