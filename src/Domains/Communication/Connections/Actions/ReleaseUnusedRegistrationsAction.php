<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
use Kanvas\Communication\Connections\Services\CommunicationReceivers;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Support\ProviderRegistry;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Throwable;

/**
 * A shared vendor resource and a receiver outlive their last connection for as long as a delivery
 * through them can still report: a status that arrives after the disconnect has to reach its
 * delivery. Two sets are checked, under the resource's lock, across every integration and
 * workspace that ever depended on it — live connections, and deliveries whose events are not
 * closed. Only when both are empty does anything get unregistered.
 */
final class ReleaseUnusedRegistrationsAction
{
    public function __construct(
        private readonly Apps $app
    ) {
    }

    /**
     * @return array{registrations: int, receivers: int} what was released
     */
    public function execute(): array
    {
        return [
            'registrations' => $this->releaseSharedRegistrations(),
            'receivers' => $this->deactivateReceivers(),
        ];
    }

    /**
     * Now, for a disconnect whose credentials are about to be deleted: what these connections leave
     * unused is released while the vendor can still be reached, at the cost of a status still on its
     * way, which no longer has anywhere to arrive.
     *
     * @param list<int> $connectionIds
     */
    public function releaseLeftBy(array $connectionIds): int
    {
        if ($connectionIds === []) {
            return 0;
        }

        $candidates = SharedRegistration::query()
            ->where('status', SharedRegistration::ACTIVE)
            ->whereHas('dependents', fn (Builder $query) => $query->whereIn('connection_id', $connectionIds))
            ->whereDoesntHave('liveDependents')
            ->get();

        return $candidates->sum(fn (SharedRegistration $registration): int => (int) $this->release($registration, waitForDeliveries: false));
    }

    private function releaseSharedRegistrations(): int
    {
        $candidates = SharedRegistration::query()
            ->where('status', SharedRegistration::ACTIVE)
            ->whereHas('dependents', fn (Builder $query) => $query->where('apps_id', $this->app->getId()))
            ->whereDoesntHave('liveDependents')
            ->get();

        return $candidates->sum(fn (SharedRegistration $registration): int => (int) $this->release($registration, waitForDeliveries: true));
    }

    private function release(SharedRegistration $registration, bool $waitForDeliveries): bool
    {
        return Cache::lock($registration->lockKey(), 120)->block(30, function () use ($registration, $waitForDeliveries): bool {
            $registration->refresh();

            if ($registration->status !== SharedRegistration::ACTIVE
                || $registration->liveDependents()->exists()
                || ($waitForDeliveries && $this->hasOutstandingDeliveries($registration->dependents()->pluck('connection_id')->all()))
            ) {
                return false;
            }

            try {
                $outcome = new ProviderRegistry()->provisioner($registration->provider)->releaseShared($registration);
            } catch (Throwable $exception) {
                report($exception);

                return false;
            }

            if (! $outcome->isFinished()) {
                return false;
            }

            $registration->forceFill([
                'status' => SharedRegistration::RELEASED,
                'released_at' => now(),
            ])->saveOrFail();

            return true;
        });
    }

    /**
     * Only receivers this domain created. One a person made by hand may serve numbers the tenant
     * never selected, so it is never switched off from here.
     */
    private function deactivateReceivers(): int
    {
        $deactivated = 0;

        $receivers = ReceiverWebhook::query()
            ->fromApp($this->app)
            ->where('is_active', true)
            ->notDeleted()
            ->where('configuration->communication->managed', true)
            ->get();

        $receiversService = new CommunicationReceivers();

        foreach ($receivers as $receiver) {
            $provider = (string) ($receiver->configuration['communication']['provider'] ?? '');
            // An app-wide receiver serves every workspace's numbers, so any of them keeps it on.
            $connections = Connection::query()
                ->fromApp($this->app)
                ->when(! $receiversService->sharesAppReceiver($provider), fn (Builder $query) => $query->where('companies_id', $receiver->companies_id))
                ->where('provider', $provider);

            if ((clone $connections)->live()->exists()
                || $this->hasOutstandingDeliveries((clone $connections)->pluck('id')->all())
            ) {
                continue;
            }

            $receiver->forceFill(['is_active' => false])->saveOrFail();
            $deactivated++;
        }

        return $deactivated;
    }

    /**
     * @param list<int> $connectionIds
     */
    private function hasOutstandingDeliveries(array $connectionIds): bool
    {
        return $connectionIds !== []
            && Delivery::query()->whereIn('connection_id', $connectionIds)->whereNull('events_closed_at')->exists();
    }
}
