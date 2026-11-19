<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\RoadsideServiceTypeEnum;
use Kanvas\Connectors\Movipass\Support\RoadsideIntakeQuestionnaire;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;

/**
 * Spec v6.0 §6.1: without the terms accepted the payment is not enabled, and the order only exists
 * once a payment is approved. The quote action already refused a case the intake rules stop; this
 * covers what the app collects after the price is shown.
 */
class AssertRoadsideCaseIsPayableAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
    ) {
    }

    public function execute(): void
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        if (($assistanceCase['terms_accepted'] ?? false) !== true) {
            throw new ValidationException('The terms and the service disclaimer must be accepted before paying');
        }

        $service = RoadsideServiceTypeEnum::tryFrom((string) ($assistanceCase['service_type'] ?? ''));
        $photos = array_filter((array) ($assistanceCase['photos'] ?? []));

        if ($service !== null && $photos === [] && RoadsideIntakeQuestionnaire::requiresPhotos($service)) {
            throw new ValidationException(sprintf(
                'The %s service requires at least one photo of the case before paying',
                $service->label(),
            ));
        }
    }
}
