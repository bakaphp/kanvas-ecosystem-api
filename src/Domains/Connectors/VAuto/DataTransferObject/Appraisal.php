<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VAuto\DataTransferObject;

use Baka\Support\Str;
use Kanvas\Connectors\SalesAssist\DataTransferObject\TradeIn;
use Kanvas\Connectors\SalesAssist\Services\MessageNoteService;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Spatie\LaravelData\Data;

class Appraisal extends Data
{
    public function __construct(
        public readonly string $vin,
        public readonly string $modelYear,
        public readonly string $make,
        public readonly string $model,
        public readonly string $series,
        public readonly int $odometer,
        public readonly float $lienPayoff,
        public readonly string $photoLinks,
        public readonly string $customerFirstName,
        public readonly string $customerLastName,
        public readonly string $customerPhone,
    ) {
    }

    public static function fromMultiple(TradeIn $form, Message $message, Lead $lead): self
    {
        $people = $lead->people;

        return new self(
            vin: $form->vin,
            modelYear: $form->year > 0 ? (string) $form->year : '',
            make: $form->make,
            model: $form->model,
            series: $form->trim !== '' ? $form->trim : $form->bodyStyle,
            odometer: $form->mileage,
            lienPayoff: $form->payoff,
            photoLinks: (string) new MessageNoteService($message)->generateFileLinks(),
            customerFirstName: (string) ($people?->firstname ?? ''),
            customerLastName: (string) ($people?->lastname ?? ''),
            customerPhone: (string) ($people?->getAllPhones()->first()?->value ?? ''),
        );
    }

    /**
     * The `CreateAppraisal` request body. `ShouldDeDupOnVin` stays off: a customer who re-submits
     * the same car later wants a fresh appraisal, and the connector dedups per message itself.
     */
    public function toVAutoArray(string $entityId, string $userName): array
    {
        return [
            'EntityContext' => [
                'EntityId' => $entityId,
                'User' => ['UserName' => $userName],
            ],
            'Appraisal' => [
                'Make' => $this->make,
                'Model' => $this->model,
                'ModelYear' => $this->modelYear,
                'Odometer' => $this->odometer,
                'Series' => $this->series,
                'Vin' => $this->vin,
                'VehicleDescription' => $this->vehicleDescription(),
                'LienPayoff' => $this->formattedLienPayoff() ?? 0,
                'Customer' => [
                    'FirstName' => $this->customerFirstName,
                    'LastName' => $this->customerLastName,
                    'Phone' => $this->customerPhone,
                ],
            ],
            'ShouldDeDupOnVin' => 0,
        ];
    }

    public function vehicleDescription(): string
    {
        $parts = [];

        if (Str::trimToNull($this->photoLinks) !== null) {
            $parts[] = 'Trade-in photos : ' . $this->photoLinks;
        }

        if ($this->formattedLienPayoff() !== null) {
            $parts[] = 'Payoff Amount : ' . $this->formattedLienPayoff();
        }

        return implode(' ', $parts);
    }

    public function formattedLienPayoff(): ?string
    {
        return $this->lienPayoff > 0 ? number_format($this->lienPayoff, 2, '.', '') : null;
    }
}
