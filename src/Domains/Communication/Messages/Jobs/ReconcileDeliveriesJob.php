<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\Actions\RecordDeliveryEventAction;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Communication\Support\TransportManager;
use Throwable;

/**
 * Settles deliveries whose outcome was lost — a worker that died mid-call, a provider that timed
 * out — by asking the provider, and closes the ones no provider can report on any more. It never
 * sends: a lost outcome is looked up, not retried.
 */
final class ReconcileDeliveriesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $this->settleLostOutcomes();
        $this->closeBeyondRetention();
    }

    private function settleLostOutcomes(): void
    {
        Delivery::query()
            ->fromApp($this->app)
            ->whereIn('status', [DeliveryStatusEnum::SENDING->value, DeliveryStatusEnum::UNKNOWN->value])
            ->whereNull('events_closed_at')
            ->where('updated_at', '<', now()->subMinutes((int) config('communication.reconcile_after_minutes')))
            ->each(function (Delivery $delivery): void {
                try {
                    $this->settle($delivery);
                } catch (Throwable $exception) {
                    report($exception);
                }
            });
    }

    private function settle(Delivery $delivery): void
    {
        $observed = $delivery->external_id !== null && $delivery->provider !== null
            ? new TransportManager()->for($delivery->provider, $delivery->channel)->lookup($delivery)
            : null;

        if ($observed !== null) {
            new RecordDeliveryEventAction(
                delivery: $delivery,
                status: $observed->status,
                providerStatus: (string) $observed->providerStatus,
                payload: ['source' => 'reconciliation'],
                errorCode: $observed->errorCode,
                error: $observed->error
            )->execute();

            return;
        }

        // Still `sending` this long after it was claimed means the worker never came back.
        if ($delivery->status === DeliveryStatusEnum::SENDING) {
            $delivery->forceFill([
                'status' => DeliveryStatusEnum::UNKNOWN,
                'error_code' => 'outcome_lost',
            ])->saveOrFail();
        }
    }

    /**
     * Past the provider's window no event can arrive, so the delivery stops holding anything open.
     * One that never got an outcome is closed as unknown, and said so in the log: a resource is
     * never freed on missing data without a trace of why.
     */
    private function closeBeyondRetention(): void
    {
        foreach (new ProviderRegistry()->all() as $slug => $provider) {
            Delivery::query()
                ->fromApp($this->app)
                ->where('provider', $slug)
                ->whereNull('events_closed_at')
                ->whereNotIn('status', [DeliveryStatusEnum::PENDING->value, DeliveryStatusEnum::SENDING->value])
                ->where('last_event_at', '<', now()->sub($provider::eventRetention()->window))
                ->each(function (Delivery $delivery): void {
                    $delivery->forceFill(['events_closed_at' => now()])->saveOrFail();

                    if ($delivery->status === DeliveryStatusEnum::UNKNOWN) {
                        Log::warning('Communication delivery closed without a known outcome', [
                            'delivery_id' => $delivery->getId(),
                            'provider' => $delivery->provider,
                            'external_id' => $delivery->external_id,
                            'reason' => 'retention_window_elapsed',
                        ]);
                    }
                });
        }
    }
}
