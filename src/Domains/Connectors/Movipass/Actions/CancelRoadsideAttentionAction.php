<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideTechnicianDeclineReasonEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * A technician drops a case after accepting it (spec v6.0 §9.2). That never cancels the user's
 * order: the case goes to REASSIGNMENT and is offered to the next eligible technician. Reason and
 * comment are both mandatory here, and the technician is not offered the case again.
 */
class CancelRoadsideAttentionAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $technician,
        private readonly RoadsideTechnicianDeclineReasonEnum $reason,
        private readonly ?string $comment,
    ) {
    }

    public function execute(): Order
    {
        $comment = Str::trimToNull($this->comment);

        if ($comment === null) {
            throw new ValidationException('A comment is required to cancel an accepted order');
        }

        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if ((int) ($assistanceCase['mechanic']['user_id'] ?? 0) !== $this->technician->getId()) {
            throw new ValidationException('You are not assigned to this order');
        }

        new TransitionRoadsideCaseStateAction(
            order: $this->order,
            to: RoadsideCaseStateEnum::REASSIGNMENT,
            actor: $this->technician,
            channel: RoadsideAuditChannelEnum::APK,
            reason: $this->reason->value . ': ' . $comment,
        )->execute();

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $assistanceCase['technician_cancellations'] = [
            ...(array) ($assistanceCase['technician_cancellations'] ?? []),
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
            action: 'attention_cancelled',
            actor: $this->technician,
            after: ['reason' => $this->reason->value],
            reason: $comment,
            channel: RoadsideAuditChannelEnum::APK,
        );

        return new CancelMechanicAssignmentAction($this->order, $this->technician, $this->order->app)->execute();
    }
}
