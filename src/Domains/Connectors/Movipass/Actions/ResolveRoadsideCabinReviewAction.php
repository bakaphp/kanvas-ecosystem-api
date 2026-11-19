<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideCaseStateEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideNotProceedReasonEnum;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The cabin's decision on a case the intake held back (no police report, injuries, a second
 * battery...). Approving sends it to the technicians; declining closes it as not authorized and
 * flags the paid amount for a refund, which Finance processes outside this flow.
 */
class ResolveRoadsideCabinReviewAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly Users $operator,
        private readonly bool $approved,
        private readonly ?string $reason = null,
    ) {
    }

    public function execute(): Order
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $reason = Str::trimToNull($this->reason);

        if (($assistanceCase['state'] ?? null) !== RoadsideCaseStateEnum::CABIN_REVIEW->value) {
            throw new ValidationException('This case is not waiting for a cabin review');
        }

        if (! $this->approved && $reason === null) {
            throw new ValidationException('A reason is required to decline the case');
        }

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'order',
            action: $this->approved ? 'cabin_review_approved' : 'cabin_review_declined',
            actor: $this->operator,
            reason: $reason,
            channel: RoadsideAuditChannelEnum::WEB,
        );

        if ($this->approved) {
            new OfferRoadsideCaseAction($this->order, $this->operator, RoadsideAuditChannelEnum::WEB)
                ->execute();

            return $this->order->refresh();
        }

        new TransitionRoadsideCaseStateAction(
            order: $this->order,
            to: RoadsideCaseStateEnum::CANCELLED,
            actor: $this->operator,
            channel: RoadsideAuditChannelEnum::WEB,
            reason: $reason,
        )->execute();

        $assistanceCase = $this->assistanceCaseFrom($this->order);
        $assistanceCase['refund_required'] = true;
        $this->saveAssistanceCase($this->order, $assistanceCase);

        return new MarkAssistanceNotAuthorizedAction(
            order: $this->order,
            user: $this->operator,
            reason: RoadsideNotProceedReasonEnum::REJECTED,
            notes: $reason,
        )->execute();
    }
}
