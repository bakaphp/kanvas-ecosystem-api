<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Apps\Models\Settings;
use Kanvas\Connectors\Stripe\Enums\ConfigurationEnum as StripeConfigurationEnum;
use Kanvas\Souk\Taxes\Actions\CompleteCheckoutFromSnapshotAction;
use Kanvas\Souk\Taxes\Actions\ReleaseCheckoutHoldsAction;
use Kanvas\Souk\Taxes\Enums\ConfigurationEnum as TaxConfigurationEnum;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Stripe\StripeClient;
use Throwable;

use function Sentry\captureMessage;

class CheckoutReconcileCommand extends Command
{
    use KanvasJobsTrait;

    protected $signature = 'souk:checkout-reconcile {app_id?}';

    protected $description = 'Reconcile stale checkout snapshots against the gateway';

    private const int STALE_MINUTES = 10;

    private const int CLEANUP_STALE_MINUTES = 30;

    private const int MAX_CLEANUP_RETRIES = 3;

    public function handle(): void
    {
        $appsId = $this->argument('app_id');

        if ($appsId) {
            $this->processApp((int) $appsId);

            return;
        }

        $appsIds = Settings::where([
            'name' => TaxConfigurationEnum::TAX_ENGINE_ENABLED->value,
            'value' => '1',
        ])->select('apps_id')->get()->pluck('apps_id');

        $this->info("Checking {$appsIds->count()} apps for stale snapshots");

        foreach ($appsIds as $appId) {
            $this->processApp((int) $appId);
        }
    }

    protected function processApp(int $appsId): void
    {
        $app = Apps::getById($appsId);
        $this->overwriteAppService($app);

        $this->reconcileStaleGatewaySnapshots($app);
        $this->retryCleanupPending($app);
    }

    private function reconcileStaleGatewaySnapshots(Apps $app): void
    {
        $terminalStatuses = [
            GatewayAttemptStatusEnum::COMPLETED->value,
            GatewayAttemptStatusEnum::VOIDED->value,
            GatewayAttemptStatusEnum::FAILED->value,
            GatewayAttemptStatusEnum::NEEDS_RECONCILE->value,
        ];

        $cutoff = now()->subMinutes(self::STALE_MINUTES);

        $snapshots = OrderTaxSnapshot::query()
            ->fromApp($app)
            ->whereNotNull('payment_intent_id')
            ->whereNotIn('gateway_attempt_status', $terminalStatuses)
            ->where('updated_at', '<', $cutoff)
            ->get();

        $this->info("App {$app->name}: found {$snapshots->count()} stale gateway snapshots");

        foreach ($snapshots as $snapshot) {
            $this->reconcileSnapshot($snapshot, $app);
        }
    }

    private function reconcileSnapshot(OrderTaxSnapshot $snapshot, Apps $app): void
    {
        $secretKey = (string) $app->get(StripeConfigurationEnum::STRIPE_SECRET_KEY->value);

        if ($secretKey === '') {
            $this->reconcileWalletOnlySnapshot($snapshot);

            return;
        }

        try {
            $stripe = new StripeClient($secretKey);
            $intent = $stripe->paymentIntents->retrieve((string) $snapshot->payment_intent_id);
        } catch (Throwable $e) {
            captureMessage("CheckoutReconcile: failed to retrieve PaymentIntent {$snapshot->payment_intent_id} for snapshot {$snapshot->uuid}: {$e->getMessage()}");

            return;
        }

        match ($intent->status) {
            'succeeded' => $this->handleSucceeded($snapshot, $app),
            'canceled' => $this->handleCanceled($snapshot),
            default => null,
        };
    }

    private function reconcileWalletOnlySnapshot(OrderTaxSnapshot $snapshot): void
    {
        if ($snapshot->order_id !== null) {
            OrderTaxSnapshot::query()
                ->where('id', $snapshot->id)
                ->update(['gateway_attempt_status' => GatewayAttemptStatusEnum::COMPLETED->value]);
        }
    }

    private function handleSucceeded(OrderTaxSnapshot $snapshot, Apps $app): void
    {
        try {
            new CompleteCheckoutFromSnapshotAction(
                $snapshot,
                (string) $snapshot->payment_intent_id,
                $app,
            )->execute();

            OrderTaxSnapshot::query()
                ->where('id', $snapshot->id)
                ->update(['gateway_attempt_status' => GatewayAttemptStatusEnum::COMPLETED->value]);

            $this->info("Reconciled succeeded snapshot {$snapshot->uuid}");
        } catch (Throwable $e) {
            captureMessage("CheckoutReconcile: CompleteCheckoutFromSnapshotAction failed for snapshot {$snapshot->uuid}: {$e->getMessage()}");
        }
    }

    private function handleCanceled(OrderTaxSnapshot $snapshot): void
    {
        try {
            new ReleaseCheckoutHoldsAction($snapshot)->execute();

            $this->info("Voided canceled snapshot {$snapshot->uuid}");
        } catch (Throwable $e) {
            captureMessage("CheckoutReconcile: void failed for snapshot {$snapshot->uuid}: {$e->getMessage()}");
        }
    }

    private function retryCleanupPending(Apps $app): void
    {
        $cutoff = now()->subMinutes(self::CLEANUP_STALE_MINUTES);

        $snapshots = OrderTaxSnapshot::query()
            ->fromApp($app)
            ->where('gateway_attempt_status', GatewayAttemptStatusEnum::CLEANUP_PENDING->value)
            ->where('updated_at', '<', $cutoff)
            ->get();

        $this->info("App {$app->name}: found {$snapshots->count()} cleanup_pending snapshots");

        foreach ($snapshots as $snapshot) {
            $this->retryRelease($snapshot);
        }
    }

    private function retryRelease(OrderTaxSnapshot $snapshot): void
    {
        $retries = (int) ($snapshot->metadata['reconcile_retries'] ?? 0);

        if ($retries >= self::MAX_CLEANUP_RETRIES) {
            OrderTaxSnapshot::query()
                ->where('id', $snapshot->id)
                ->update(['gateway_attempt_status' => GatewayAttemptStatusEnum::NEEDS_RECONCILE->value]);

            captureMessage("CheckoutReconcile: snapshot {$snapshot->uuid} exceeded max cleanup retries, marked needs_reconcile");

            return;
        }

        try {
            new ReleaseCheckoutHoldsAction($snapshot)->execute();

            $this->info("Released cleanup_pending snapshot {$snapshot->uuid} (attempt " . ($retries + 1) . ')');
        } catch (Throwable $e) {
            captureMessage("CheckoutReconcile: release failed for cleanup_pending snapshot {$snapshot->uuid}: {$e->getMessage()}");
        }

        $metadata = is_array($snapshot->metadata) ? $snapshot->metadata : [];
        $metadata['reconcile_retries'] = $retries + 1;

        OrderTaxSnapshot::query()
            ->where('id', $snapshot->id)
            ->update(['metadata' => json_encode($metadata)]);
    }
}
