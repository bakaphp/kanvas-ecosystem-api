<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Alanube\Webhooks;

use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Alanube\Enums\ConfigurationEnum;
use Kanvas\Connectors\Alanube\Services\AlanubeWebhookSecretService;
use Kanvas\NervousSystem\Ledger\Actions\AppendEventAction;
use Kanvas\NervousSystem\Ledger\DataTransferObject\Event as EventData;
use Kanvas\NervousSystem\Ledger\Models\Event;
use Kanvas\Workflow\Jobs\ProcessWebhookJob;
use Kanvas\Workflow\Models\ReceiverWebhook;
use Override;

/**
 * Shared by the four Alanube receivers. Alanube does not sign deliveries, so every one must carry the
 * company's secret header; a request without it is refused before anything is stored or queued.
 *
 * The payload shapes of Alanube's webhooks are not documented, so each job treats the delivery as a nudge:
 * it takes the id and reads the record back from the API before acting. That also makes ordering and
 * re-delivery harmless — the jobs always act on current state, and each is idempotent.
 */
abstract class AlanubeWebhookJob extends ProcessWebhookJob
{
    public const string SOURCE_DOMAIN = 'Scribe';
    private const string TEST_MESSAGE = 'Test message';

    #[Override]
    public static function authenticateRequest(Request $request, ReceiverWebhook $receiver): bool
    {
        /** @var Companies $company */
        $company = $receiver->company;
        $presented = $request->headers->get(ConfigurationEnum::WEBHOOK_SECRET_HEADER);

        if (new AlanubeWebhookSecretService($company)->matches(is_string($presented) ? $presented : null)) {
            return true;
        }

        Log::warning('Alanube webhook refused: missing or wrong secret header', [
            'receiver_id' => $receiver->getId(),
            'companies_id' => $receiver->companies_id,
        ]);

        return false;
    }

    /**
     * Alanube POSTs `{"message": "Test message"}` with our headers before it saves a webhook configuration and
     * refuses to save unless it gets a 2xx (no redirects, 10s). It carries no work, so it never rides the queue.
     */
    #[Override]
    public static function handshakeResponse(Request $request, ReceiverWebhook $receiver): ?array
    {
        return $request->input('message') === self::TEST_MESSAGE && count($request->all()) === 1
            ? ['message' => 'ok']
            : null;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function payload(): array
    {
        return (array) $this->webhookRequest->payload;
    }

    /**
     * The record id, wherever the delivery puts it: at the top level or wrapped in `document` / `data`.
     */
    protected function payloadValue(string $key): ?string
    {
        $payload = $this->payload();

        foreach ([$payload, $payload['document'] ?? null, $payload['data'] ?? null] as $node) {
            if (is_array($node) && isset($node[$key]) && is_scalar($node[$key]) && (string) $node[$key] !== '') {
                return (string) $node[$key];
            }
        }

        return null;
    }

    protected function app(): Apps
    {
        /** @var Apps */
        return $this->receiver->app;
    }

    protected function company(): Companies
    {
        /** @var Companies */
        return $this->receiver->company;
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function recordLedgerEvent(
        string $eventType,
        ?string $correlationId,
        array $payload,
        ?object $entity = null
    ): Event {
        return new AppendEventAction(
            new EventData(
                app: $this->app(),
                company: $this->company(),
                sourceDomain: self::SOURCE_DOMAIN,
                eventType: $eventType,
                sourceEntityType: $entity !== null ? $entity::class : null,
                sourceEntityId: $entity !== null && method_exists($entity, 'getId') ? (int) $entity->getId() : null,
                actorType: 'System',
                payload: $payload,
                correlationId: $correlationId,
                occurredAt: Carbon::now(),
            ),
        )->execute();
    }

    protected function ledgerEventExists(string $eventType, string $correlationId): bool
    {
        return Event::query()
            ->where('apps_id', $this->receiver->apps_id)
            ->where('companies_id', $this->receiver->companies_id)
            ->where('event_type', $eventType)
            ->where('correlation_id', $correlationId)
            ->exists();
    }
}
