<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Workflows\Activities;

use Baka\Contracts\AppInterface;
use Illuminate\Database\Eloquent\Model;
use Kanvas\Connectors\Movipass\Parking\Actions\DispatchParkingPaymentConfirmationAction;
use Kanvas\Connectors\Movipass\Parking\ParkingOperatorResolver;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingMetadata;
use Kanvas\Connectors\Movipass\Parking\Support\ParkingQuotePayment;
use Kanvas\Connectors\Movipass\Support\WalkInOrder;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Passes\Gates\PassScanDecisionGate;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Workflow\Attributes\WorkflowAction;
use Kanvas\Workflow\Contracts\WorkflowActivityInterface;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\KanvasActivity;
use Override;
use Throwable;

#[WorkflowAction]
class ParkingExitDecisionActivity extends KanvasActivity implements WorkflowActivityInterface
{
    #[Override]
    public function execute(Model $booking, AppInterface $app, array $params = []): array
    {
        return $this->executeIntegration(
            entity: $booking,
            app: $app,
            integration: IntegrationsEnum::MOVIPASS,
            additionalParams: $params,
            integrationOperation: function (EventVersion $booking): array {
                $order = WalkInOrder::ofBooking($booking);

                if ($order === null) {
                    return $this->decision(false, 'Booking has no walk-in parking order');
                }

                [$allowed, $reason] = $this->evaluate($order);

                return $this->decision($allowed, $reason, $order);
            },
            company: $booking->company,
        );
    }

    private function evaluate(Order $order): array
    {
        if (! ParkingQuotePayment::isPaid($order)) {
            return [false, 'The parking quote is not paid'];
        }

        if (! ParkingMetadata::isPaymentConfirmed($order)) {
            $this->requestConfirmation($order);

            return [false, 'The payment is not confirmed with the parking operator yet'];
        }

        try {
            $authorized = ParkingOperatorResolver::forOrder($order)->authorizeExit($order);
        } catch (Throwable $exception) {
            report($exception);

            return [false, 'The parking operator could not authorize the exit'];
        }

        return $authorized ? [true, 'Exit authorized'] : [false, 'The parking operator denied the exit'];
    }

    private function requestConfirmation(Order $order): void
    {
        try {
            new DispatchParkingPaymentConfirmationAction($order)->execute();
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function decision(bool $allowed, string $reason, ?Order $order = null): array
    {
        return [
            'order' => $order?->getId(),
            'status' => 'success',
            'message' => $reason,
            PassScanDecisionGate::DECISION_KEY => [
                'allowed' => $allowed,
                'reason' => $reason,
            ],
        ];
    }
}
