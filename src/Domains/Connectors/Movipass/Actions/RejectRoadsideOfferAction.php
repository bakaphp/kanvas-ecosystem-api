<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideTechnicianDeclineReasonEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * A technician turns an offer down before accepting (spec v6.0 §9.2). The rejection is kept with
 * its reason, the technician is not offered this case again, and once nobody from the round is left
 * the case goes straight back out instead of waiting for the round to expire.
 */
class RejectRoadsideOfferAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $technician,
        private readonly RoadsideTechnicianDeclineReasonEnum $reason,
        private readonly ?string $comment = null,
    ) {
    }

    public function execute(): void
    {
        $comment = Str::trimToNull($this->comment);

        if ($this->reason === RoadsideTechnicianDeclineReasonEnum::OTHER && $comment === null) {
            throw new ValidationException('A comment is required when the reason is "other"');
        }

        if ($this->order->orderStatus?->slug !== MovipassOrderStatusEnum::AWAITING_OPERATOR->slug()) {
            throw new ValidationException('This order is no longer waiting for a technician');
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $notified = array_map('intval', (array) ($assistanceCase['notified_mechanic_ids'] ?? []));

        if (! in_array($this->technician->getId(), $notified, true)) {
            throw new ValidationException('This order was not offered to you');
        }

        $remaining = array_values(array_diff($notified, [$this->technician->getId()]));
        $assistanceCase['notified_mechanic_ids'] = $remaining;
        $assistanceCase['rejected_mechanic_ids'] = array_values(array_unique([
            ...(array) ($assistanceCase['rejected_mechanic_ids'] ?? []),
            $this->technician->getId(),
        ]));
        $assistanceCase['rejections'] = [
            ...(array) ($assistanceCase['rejections'] ?? []),
            [
                'technician_id' => $this->technician->getId(),
                'reason' => $this->reason->value,
                'comment' => $comment,
                'at' => Carbon::now()->toISOString(),
            ],
        ];
        $this->saveAssistanceCase($this->order, $assistanceCase);

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'technician',
            action: 'offer_rejected',
            actor: $this->technician,
            after: ['reason' => $this->reason->value],
            reason: $comment,
            channel: RoadsideAuditChannelEnum::APK,
        );

        if ($remaining === []) {
            new OfferRoadsideCaseAction($this->order, $this->technician)->execute();
        }
    }
}
