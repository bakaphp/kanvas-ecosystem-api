<?php

declare(strict_types=1);

namespace Kanvas\Communication\Messages\DataTransferObject;

use Illuminate\Database\Eloquent\Collection;
use Kanvas\Communication\Messages\Actions\RetryDeliveryAction;
use Kanvas\Communication\Messages\Enums\ChannelTypeEnum;
use Kanvas\Communication\Messages\Enums\DeliveryStatusEnum;
use Kanvas\Communication\Messages\Models\Delivery;
use Kanvas\Communication\Messages\Models\Request;

/**
 * What a caller gets back from a send: the request and one delivery per channel. Accepted means a
 * provider took the message, never that it arrived.
 */
final readonly class Operation
{
    public function __construct(public Request $request)
    {
    }

    public function id(): int
    {
        return $this->request->getId();
    }

    /**
     * @return Collection<int, Delivery>
     */
    public function deliveries(): Collection
    {
        return $this->request->deliveries()->orderBy('id')->get();
    }

    public function delivery(ChannelTypeEnum $channel): ?Delivery
    {
        return $this->request->deliveries()->where('channel', $channel->value)->orderBy('id')->first();
    }

    /**
     * @return string pending while any channel is still on its way out; then accepted when every
     *                channel reached its provider, failed when none did, partial otherwise
     */
    public function status(): string
    {
        $deliveries = $this->deliveries();

        $inFlight = $deliveries->contains(
            fn (Delivery $delivery): bool => in_array($delivery->status, [DeliveryStatusEnum::PENDING, DeliveryStatusEnum::SENDING], true)
        );
        $reached = $deliveries->filter(fn (Delivery $delivery): bool => $delivery->status->reachedTheProvider())->count();

        return match (true) {
            $inFlight => 'pending',
            $reached === 0 => 'failed',
            $reached === $deliveries->count() => 'accepted',
            default => 'partial',
        };
    }

    public function retry(ChannelTypeEnum $channel): self
    {
        $delivery = $this->delivery($channel);

        if ($delivery !== null) {
            new RetryDeliveryAction($delivery)->execute();
        }

        return $this;
    }

    /**
     * Only what failed. An accepted delivery is never sent again from here — that would be a second
     * message, not a retry.
     */
    public function retryFailed(): self
    {
        foreach ($this->deliveries() as $delivery) {
            new RetryDeliveryAction($delivery)->execute();
        }

        return $this;
    }
}
