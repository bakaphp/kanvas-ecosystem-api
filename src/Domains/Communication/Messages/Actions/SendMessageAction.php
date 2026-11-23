<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\Actions;

use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Kanvas\Apps\Models\Apps;
use Kanvas\Communication\Messages\DataTransferObject\Content;
use Kanvas\Communication\Messages\DataTransferObject\DeliveryRoute;
use Kanvas\Communication\Messages\DataTransferObject\Operation;
use Kanvas\Communication\Messages\DataTransferObject\Recipient;
use Kanvas\Communication\Messages\DataTransferObject\Sender;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Enums\OperationKindEnum;
use Kanvas\Communication\Messages\Jobs\DispatchDeliveryJob;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Messages\Models\Request;
use Kanvas\Communication\Support\EligibilityResolver;
use Kanvas\Communication\Support\TransportManager;
use Kanvas\Companies\Models\Companies;
use Kanvas\Social\Messages\Models\Message;
use LogicException;

/**
 * Persists a request and one delivery per channel, then hands each delivery to the queue. Nothing
 * here calls a provider: a send is a row first, so what was asked survives a crash before the
 * vendor call, and a failed channel is a visible delivery instead of an exception the caller lost.
 */
final class SendMessageAction
{
    /**
     * @param list<DeliveryRoute> $routes
     * @param bool $sync dispatches inline — for a chat reply whose caller waits on the outcome
     */
    public function __construct(
        private readonly Apps $app,
        private readonly Companies $company,
        private readonly Recipient $recipient,
        private readonly Content $content,
        private readonly array $routes,
        private readonly ?Sender $sender = null,
        private readonly ?string $idempotencyKey = null,
        private readonly ?string $tag = null,
        private readonly ?Message $replyTo = null,
        private readonly bool $sync = false,
        private readonly ?Message $filedAs = null,
        private readonly bool $fileMessage = true,
        private readonly bool $honorsConsent = true
    ) {
        // Neither is recorded on the request, so a queued delivery could not know.
        if ((! $fileMessage || ! $honorsConsent) && ! $sync) {
            throw new LogicException('Only a sync send can leave filing to its caller or go out past a stop request.');
        }
    }

    public function execute(): Operation
    {
        $existing = $this->requestWithSameKey();

        if ($existing !== null) {
            return new Operation($existing);
        }

        try {
            $request = DB::connection('workflow')->transaction(function (): Request {
                $request = $this->createRequest();

                foreach ($this->routes as $route) {
                    $this->createDelivery($request, $route);
                }

                return $request;
            });
        } catch (UniqueConstraintViolationException $exception) {
            // Two callers with the same key raced past the lookup; the index picked the winner.
            return new Operation($this->requestWithSameKey() ?? throw $exception);
        }

        $pending = $request->deliveries()->where('status', DeliveryStatusEnum::PENDING->value)->get();

        // Inline means inline: dispatchSync() still goes through the queue manager, so a faked or
        // misconfigured queue would leave a caller waiting on a delivery that never ran.
        foreach ($pending as $delivery) {
            $this->sync
                ? new DispatchDeliveryAction($delivery, $this->fileMessage, $this->honorsConsent)->execute()
                : DispatchDeliveryJob::dispatch($delivery);
        }

        return new Operation($request);
    }

    /**
     * The only dedupe there is. Two requests with the same text are two messages unless the caller
     * said, with a key, that they are one.
     */
    private function requestWithSameKey(): ?Request
    {
        if ($this->idempotencyKey === null) {
            return null;
        }

        return Request::query()
            ->fromApp($this->app)
            ->fromCompany($this->company->getId())
            ->where('idempotency_key', $this->idempotencyKey)
            ->first();
    }

    private function createRequest(): Request
    {
        return Request::create([
            'apps_id' => $this->app->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => $this->sender?->isUser() ? $this->sender->id : null,
            'kind' => $this->replyTo !== null ? OperationKindEnum::REPLY : OperationKindEnum::SEND,
            'people_id' => $this->recipient->people?->getId(),
            'content' => $this->content->toArray(),
            'sender' => $this->sender?->toArray(),
            'channels' => array_map(fn (DeliveryRoute $route): string => $route->channel->value, $this->routes),
            'idempotency_key' => $this->idempotencyKey,
            'tag' => $this->tag,
            'reply_to_message_id' => $this->replyTo?->getId(),
            'filed_message_id' => $this->filedAs?->getId(),
            'campaign_recipient_id' => $this->content->email?->campaignRecipientId,
        ]);
    }

    private function createDelivery(Request $request, DeliveryRoute $route): Delivery
    {
        $connection = $route->connection;

        $delivery = Delivery::create([
            'apps_id' => $request->apps_id,
            'companies_id' => $request->companies_id,
            'request_id' => $request->getId(),
            // A message the caller already filed is this delivery's from the start, so a send
            // that never leaves is written on it instead of looking like it was said.
            'message_id' => $request->filed_message_id,
            'connection_id' => $connection?->getId(),
            'channel' => $route->channel,
            'provider' => $connection?->provider,
            'to_address' => $route->address,
            'status' => DeliveryStatusEnum::PENDING,
            'activation_version' => $connection?->activation_version ?? 0,
            'last_event_at' => now(),
        ]);

        $unavailable = $connection?->unavailableReason();

        $failure = match (true) {
            $connection === null => 'no_connection',
            ! new TransportManager()->supports($connection->provider, $route->channel) => 'channel_not_supported',
            $unavailable !== null => $unavailable,
            $route->address === null => 'no_address',
            default => null,
        };

        if ($failure !== null) {
            $delivery->finishWithoutSending(DeliveryStatusEnum::FAILED, $failure);

            return $delivery;
        }

        $refusal = $this->honorsConsent
            ? new EligibilityResolver()->reasonNotToContact($this->recipient, $route->channel, $route->address)
            : null;

        if ($refusal !== null) {
            $delivery->finishWithoutSending(DeliveryStatusEnum::SKIPPED, $refusal);
        }

        return $delivery;
    }
}
