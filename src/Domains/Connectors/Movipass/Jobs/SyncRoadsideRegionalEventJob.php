<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Jobs;

use Baka\Traits\KanvasJobsTrait;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\PushRoadsideProviderStateAction;
use Kanvas\Connectors\Movipass\Actions\RegisterRoadsideProviderContactAction;
use Kanvas\Connectors\Movipass\Enums\ConfigurationEnum;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Connectors\Movipass\Support\RegionalAssistance;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderCaseNumber;
use Kanvas\Connectors\Movipass\Support\RoadsideProviderOutbox;
use Kanvas\Souk\Orders\Models\Order;
use Throwable;

/**
 * Delivers one outbox event to Asistencia Regional. Their API exposes the case state and the
 * assigned contact; everything else the spec lists (coordinates, evidence, payments, incidents) has
 * no endpoint yet, so those events are recorded as skipped with that reason rather than invented.
 *
 * A failure is retried with backoff; once the retries run out the event is marked failed, the error
 * is audited, and retryRoadsideProviderSync re-queues it by hand. Default queue on purpose: the
 * existing workers already consume it.
 */
final class SyncRoadsideRegionalEventJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public function __construct(
        public readonly Apps $app,
        public readonly Order $order,
        public readonly string $eventId,
    ) {
    }

    /**
     * No-op unless the order is an Asistencia Regional case on an app wired to the provider, so the
     * state machine can call it unconditionally.
     */
    public static function dispatchFor(Order $order, string $type, array $payload): ?string
    {
        if (! RegionalAssistance::isEnabledFor($order)
            || empty($order->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_BASE_URL->value))
        ) {
            return null;
        }

        $eventId = RoadsideProviderOutbox::append($order, $type, $payload);
        self::dispatch($order->app, $order, $eventId);

        return $eventId;
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);

        $event = RoadsideProviderOutbox::find($this->order, $this->eventId);

        if ($event === null || $event['status'] === RoadsideProviderOutbox::SENT) {
            return;
        }

        if (RoadsideProviderCaseNumber::for($this->order) === null) {
            $this->skip($event, 'the case is not registered with the provider yet');

            return;
        }

        $sent = match ($event['type']) {
            'state' => $this->pushState($event['payload']),
            'contact' => new RegisterRoadsideProviderContactAction($this->order, (array) $event['payload'])->execute(),
            default => null,
        };

        if ($sent === null) {
            $this->skip($event, 'the provider API has no endpoint for ' . $event['type'] . ' events');

            return;
        }

        RoadsideProviderOutbox::mark($this->order, $this->eventId, RoadsideProviderOutbox::SENT);
        $this->order->set(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_ERROR->value, null);

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'integration',
            action: 'event_sent',
            after: ['event' => $event['type'], 'outbox_id' => $this->eventId],
        );
    }

    public function failed(Throwable $exception): void
    {
        RoadsideProviderOutbox::mark($this->order, $this->eventId, RoadsideProviderOutbox::FAILED, $exception->getMessage());
        $this->order->set(CustomFieldEnum::ROADSIDE_PROVIDER_SYNC_ERROR->value, $exception->getMessage());

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'integration',
            action: 'event_failed',
            after: ['outbox_id' => $this->eventId],
            reason: $exception->getMessage(),
            succeeded: false,
        );
    }

    /**
     * The provider's vocabulary is mapped through the state-map setting; a homologated state with no
     * entry there is something they do not track, not an error.
     */
    private function pushState(array $payload): ?bool
    {
        $state = strtolower((string) ($payload['to'] ?? ''));
        $map = $this->app->get(ConfigurationEnum::ROADSIDE_PROVIDER_STATE_MAP->value);
        $map = is_string($map) ? json_decode($map, true) : $map;

        if ($state === '' || ! is_array($map) || ! array_key_exists($state, $map)) {
            return null;
        }

        new PushRoadsideProviderStateAction($this->order, $state)->execute();

        return true;
    }

    private function skip(array $event, string $reason): void
    {
        RoadsideProviderOutbox::mark($this->order, $this->eventId, RoadsideProviderOutbox::SKIPPED, $reason);

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'integration',
            action: 'event_skipped',
            after: ['event' => $event['type'], 'outbox_id' => $this->eventId],
            reason: $reason,
        );
    }
}
