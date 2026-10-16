<?php

declare(strict_types=1);

namespace Kanvas\Insurance\Actions;

use Baka\Support\Str;
use Kanvas\Insurance\Enums\InsuranceCustomFieldEnum;
use Kanvas\Insurance\Exceptions\InsuranceValidationException;
use Kanvas\Insurance\Notifications\InsurancePolicyReminderNotification;
use Kanvas\Insurance\Services\InsurancePolicyReminders;
use Kanvas\Souk\Orders\Models\Order;

class SendInsurancePolicyReminderAction
{
    public function __construct(
        private readonly Order $order,
        private readonly string $endsAt,
        private readonly int $daysLeft,
        private readonly array $sentMarkers,
    ) {
    }

    public function execute(): void
    {
        $user = $this->order->user ?? throw new InsuranceValidationException('This order has no customer to remind.');

        $user->notify(new InsurancePolicyReminderNotification(
            $this->order,
            $this->data(),
            $this->daysLeft,
        ));

        $this->order->set(
            InsuranceCustomFieldEnum::REMINDERS_SENT->value,
            implode(',', [...$this->sentMarkers, InsurancePolicyReminders::marker($this->endsAt, $this->daysLeft)])
        );
    }

    private function data(): array
    {
        $carnet = new BuildInsuranceCarnetDataAction($this->order, withQr: false)->execute();
        $registered = (array) ($this->order->metadata['data'] ?? []);
        $vehicle = (array) $carnet['vehicle'];

        return [
            ...$carnet,
            'insurer' => Str::trimToNull($registered['insurer_name'] ?? null) ?? $carnet['insurer'],
            'plan' => Str::trimToNull($registered['plan_name'] ?? null) ?? $carnet['plan'],
            'daysLeft' => $this->daysLeft,
            'expiryPhrase' => InsurancePolicyReminders::expiryPhrase($this->daysLeft),
            'plate' => $vehicle['plate'] ?? null,
            'vehicleLabel' => Str::trimToNull(implode(' ', array_filter([
                $vehicle['description'] ?? null,
                $vehicle['plate'] ?? null,
            ]))),
            'order_id' => $this->order->getId(),
            'vehicle_variant_id' => new ReadInsuranceOrderAction($this->order)->execute()->vehicle?->getId(),
        ];
    }
}
