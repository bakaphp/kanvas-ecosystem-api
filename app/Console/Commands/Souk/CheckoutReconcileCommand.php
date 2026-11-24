<?php

declare(strict_types=1);

namespace App\Console\Commands\Souk;

use App\Console\Commands\Souk\Concerns\IteratesTaxEnabledApps;
use Baka\Support\Str;
use Illuminate\Console\Command;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Stripe\Support\StripeAccount;
use Kanvas\Souk\Taxes\Actions\CompleteCheckoutFromSnapshotAction;
use Kanvas\Souk\Taxes\Actions\ReleaseCheckoutHoldsAction;
use Kanvas\Souk\Taxes\Enums\GatewayAttemptStatusEnum;
use Kanvas\Souk\Taxes\Models\OrderTaxSnapshot;
use Throwable;

use function Sentry\captureMessage;

class CheckoutReconcileCommand extends Command
{
    use IteratesTaxEnabledApps;

    protected $signature = 'souk:checkout-reconcile {app_id?}';

    protected $description = 'Reconcile stale checkout snapshots against the gateway';

    private const int STALE_MINUTES = 10;

    private const int CLEANUP_STALE_MINUTES = 30;

    private const int MAX_CLEANUP_RETRIES = 3;

    public function handle(): void
    {
        $this->eachTaxEnabledApp(function (Apps $app): void {
            $this->reconcileStaleGatewaySnapshots($app);
            $this->retryCleanupPending($app);
        });
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
        $account = StripeAccount::resolve($app, $snapshot->checkoutCompany());

        if (Str::trimToNull((string) $account->secretKey) === null) {
            $this->reconcileWalletOnlySnapshot($snapshot);

            return;
        }

        try {
            $intent = $account->client()->paymentIntents->retrieve((string) $snapshot->payment_intent_id);
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
