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
use Kanvas\Connectors\Movipass\Actions\OfferRoadsideCaseAction;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Closes one offer round. If the case is still waiting for a technician and no newer round has
 * started (a rejection already re-offers), the silence is recorded and the case goes out again.
 */
final class ExpireRoadsideOfferJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use KanvasJobsTrait;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Apps $app,
        public readonly Order $order,
        public readonly int $round,
    ) {
    }

    public function handle(): void
    {
        $this->overwriteAppService($this->app);
        $this->order->refresh();

        $assistanceCase = $this->order->metadata['assistance_case'] ?? [];
        $stillWaiting = in_array($this->order->orderStatus?->slug, [
            MovipassOrderStatusEnum::REQUEST_SUBMITTED->slug(),
            MovipassOrderStatusEnum::AWAITING_OPERATOR->slug(),
        ], true);

        if (! $stillWaiting || (int) ($assistanceCase['offer']['round'] ?? 0) !== $this->round) {
            return;
        }

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'technician',
            action: 'offer_expired',
            after: ['round' => $this->round, 'unanswered_ids' => $assistanceCase['offer']['offered_ids'] ?? []],
        );

        new OfferRoadsideCaseAction($this->order, $this->order->user)->execute();
    }
}
