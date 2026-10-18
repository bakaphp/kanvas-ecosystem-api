<?php

declare(strict_types=1);

namespace Kanvas\Communication\Connections\Actions;

use Illuminate\Support\Facades\Cache;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Connections\Models\Connection;
use Kanvas\Communication\Connections\Models\SharedRegistration;
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

    private function releaseSharedRegistrations(): int
    {
        $released = 0;

        $candidates = SharedRegistration::query()
            ->where('status', SharedRegistration::ACTIVE)
            ->whereHas('dependents', fn ($query) => $query->where('apps_id', $this->app->getId()))
            ->whereDoesntHave('liveDependents')
            ->get();

        foreach ($candidates as $registration) {
            $released += (int) Cache::lock($registration->lockKey(), 120)->block(30, function () use ($registration): bool {
                $registration->refresh();
                $connectionIds = $registration->dependents()->pluck('connection_id');

                if ($registration->status !== SharedRegistration::ACTIVE
                    || $registration->liveDependents()->exists()
                    || $this->hasOutstandingDeliveries($connectionIds->all())
                ) {
                    return false;
                }

                try {
                    $outcome = app(ProviderRegistry::class)->provisioner($registration->provider)->releaseShared($registration);
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

        return $released;
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
            ->where('is_deleted', 0)
            ->where('configuration->communication->managed', true)
            ->get();

        foreach ($receivers as $receiver) {
            $provider = (string) ($receiver->configuration['communication']['provider'] ?? '');
            $connections = Connection::query()
                ->where('apps_id', $receiver->apps_id)
                ->where('companies_id', $receiver->companies_id)
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
