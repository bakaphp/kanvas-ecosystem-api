<?php

declare(strict_types=1);

namespace Kanvas\Connectors\Movipass\Actions;

use Illuminate\Support\Carbon;
use Kanvas\Connectors\Movipass\Concerns\InteractsWithAssistanceCase;
use Kanvas\Connectors\Movipass\Enums\MovipassOrderStatusEnum;
use Kanvas\Connectors\Movipass\Enums\RoadsideAuditChannelEnum;
use Kanvas\Connectors\Movipass\Events\RefreshActiveAssistanceEvent;
use Kanvas\Connectors\Movipass\Notifications\RoadsideAssistanceStatusNotification;
use Kanvas\Connectors\Movipass\Services\RoadsideAuditTrail;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Souk\Orders\Models\Order;
use Kanvas\Users\Models\Users;

/**
 * The technician types the user's 4-digit PIN on site; only a valid one starts the service. Every
 * attempt, right or wrong, is kept on the case and in the audit trail (spec v6.0 §16: "PIN
 * incorrecto — no inicia el servicio y queda intento registrado").
 */
class StartRoadsideServiceWithPinAction
{
    use InteractsWithAssistanceCase;

    public function __construct(
        private readonly Order $order,
        private readonly string $pin,
        private readonly ?Users $technician = null,
        private readonly RoadsideAuditChannelEnum $channel = RoadsideAuditChannelEnum::APK,
    ) {
    }

    public function execute(): void
    {
        $assistanceCase = $this->assistanceCaseFrom($this->order);

        // The workflow path has no acting technician: it starts the case's own.
        if ($this->technician !== null) {
            $this->assertAssignedTo($assistanceCase, $this->technician);
        }

        $attemptedAt = Carbon::now()->toISOString();

        try {
            new ValidateRoadsideAssistancePinAction(order: $this->order, pin: $this->pin)->execute();
        } catch (ValidationException $exception) {
            $assistanceCase['pin_validation_error'] = $exception->getMessage();
            $assistanceCase['pin_validation_attempted_at'] = $attemptedAt;
            $assistanceCase['pin_attempts'] = [
                ...(array) ($assistanceCase['pin_attempts'] ?? []),
                ['at' => $attemptedAt, 'valid' => false, 'technician_id' => $this->technician?->getId()],
            ];
            unset($assistanceCase['pin_attempt']);
            $this->saveAssistanceCase($this->order, $assistanceCase);

            RoadsideAuditTrail::forOrder(
                order: $this->order,
                module: 'order',
                action: 'pin_rejected',
                actor: $this->technician,
                reason: $exception->getMessage(),
                channel: $this->channel,
                succeeded: false,
            );

            throw $exception;
        }

        $orderUser = $this->resolveCaseUser($this->order->users_id);

        if (! $orderUser instanceof Users) {
            throw new ValidationException('Order user ' . (int) $this->order->users_id . ' could not be resolved');
        }

        $mechanicId = (int) ($assistanceCase['mechanic']['user_id'] ?? 0);
        if ($mechanicId > 0) {
            RefreshActiveAssistanceEvent::dispatch($this->order, $mechanicId);
        }

        $assistanceCase['pin_validated_at'] = $attemptedAt;
        $assistanceCase['status'] = MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug();
        $assistanceCase['status_updated_at'] = $attemptedAt;
        $assistanceCase['pin_attempts'] = [
            ...(array) ($assistanceCase['pin_attempts'] ?? []),
            ['at' => $attemptedAt, 'valid' => true, 'technician_id' => $this->technician?->getId()],
        ];
        unset($assistanceCase['pin_attempt'], $assistanceCase['pin_validation_error']);
        $this->saveAssistanceCase($this->order, $assistanceCase);

        $this->order->transitionToStatus($orderUser, MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug());

        RoadsideAuditTrail::forOrder(
            order: $this->order,
            module: 'order',
            action: 'pin_validated',
            actor: $this->technician,
            channel: $this->channel,
        );

        $this->notifySafely($orderUser, new RoadsideAssistanceStatusNotification(
            $this->order,
            'Service started',
            'The mechanic has started the roadside assistance service.',
            MovipassOrderStatusEnum::SERVICE_IN_PROGRESS->slug(),
        ));
    }
}
