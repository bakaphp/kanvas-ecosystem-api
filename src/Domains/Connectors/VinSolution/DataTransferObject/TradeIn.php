<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\DataTransferObject;

use Baka\Support\Str;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\Connectors\SalesAssist\DataTransferObject\TradeIn as SalesAssistTradeIn;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Social\Messages\Models\Message;
use Spatie\LaravelData\Data;

class TradeIn extends Data
{
    public function __construct(
        public readonly ?string $vin,
        public readonly ?string $year,
        public readonly ?string $make,
        public readonly ?string $model,
        public readonly ?string $trim,
        public readonly ?string $exteriorColor,
        public readonly ?string $interiorColor,
        public readonly ?string $bodyStyle,
        public readonly ?string $engineName,
        public readonly ?string $transmission,
        public readonly ?string $driveTrain,
        public readonly ?string $doors,
        public readonly int $mileage,
        public readonly float $value,
        public readonly string $condition,
        public readonly ?string $description,
        public readonly float $payOff,
    ) {
    }

    public static function fromMultiple(Message $message, Lead $lead): self
    {
        // The attribute, not getMessage(): PushPurchaseVehicleAction overwrites it in memory first.
        $messageData = (array) $message->message;
        $form = SalesAssistTradeIn::fromMessageData($messageData['data'] ?? []);
        $description = Str::trimToNull($lead->getFiles()->pluck('url')->implode(' '));

        if (($messageData['verb'] ?? '') === ActionEnum::PAYOFF_FORM->value) {
            return new self(
                vin: null,
                year: null,
                make: null,
                model: null,
                trim: null,
                exteriorColor: null,
                interiorColor: null,
                bodyStyle: null,
                engineName: null,
                transmission: null,
                driveTrain: null,
                doors: null,
                mileage: 0,
                value: 0.0,
                condition: 'UNKNOWN',
                description: $description,
                payOff: (float) ($messageData['data']['form']['ten_day_payoff']['value'] ?? 0),
            );
        }

        return new self(
            vin: Str::trimToNull($form->vin),
            year: $form->year > 0 ? (string) $form->year : null,
            make: Str::trimToNull($form->make),
            model: Str::trimToNull($form->model),
            trim: Str::trimToNull($form->trim),
            exteriorColor: Str::trimToNull($form->exteriorColor),
            interiorColor: Str::trimToNull($form->interiorColor),
            bodyStyle: Str::trimToNull($form->bodyStyle),
            engineName: Str::trimToNull($form->engine),
            transmission: Str::trimToNull($form->transmission),
            driveTrain: Str::trimToNull($form->driveTrain),
            doors: Str::trimToNull($form->doors),
            mileage: $form->mileage,
            value: $form->value,
            condition: 'UNKNOWN',
            description: $description,
            payOff: $form->payoff,
        );
    }

    /**
     * Convert to array format expected by VinSolution API
     */
    public function toVinSolutionArray(): array
    {
        return [
            'vin' => $this->vin,
            'year' => $this->year,
            'make' => $this->make,
            'model' => $this->model,
            'trim' => $this->trim,
            'exteriorColor' => $this->exteriorColor,
            'interiorColor' => $this->interiorColor,
            'bodyStyle' => $this->bodyStyle,
            'engineName' => $this->engineName,
            'transmission' => $this->transmission,
            'driveTrain' => $this->driveTrain,
            'doors' => $this->doors,
            'mileage' => $this->mileage,
            'value' => $this->value,
            'condition' => $this->condition,
            'description' => $this->description,
            'payOff' => $this->payOff,
        ];
    }

    /**
     * Check if this is a payoff-only trade-in
     */
    public function isPayoffOnly(): bool
    {
        return $this->vin === null &&
               $this->make === null &&
               $this->model === null &&
               $this->payOff > 0;
    }

    /**
     * Check if this trade-in has complete vehicle information
     */
    public function hasCompleteVehicleInfo(): bool
    {
        return ! empty($this->vin) &&
               ! empty($this->make) &&
               ! empty($this->model) &&
               ! empty($this->year);
    }

    /**
     * Get formatted mileage string with commas
     */
    public function getFormattedMileage(): string
    {
        return number_format($this->mileage);
    }

    /**
     * Get formatted value as currency string
     */
    public function getFormattedValue(): string
    {
        return '$' . number_format($this->value, 2);
    }

    /**
     * Get formatted payoff as currency string
     */
    public function getFormattedPayoff(): string
    {
        return '$' . number_format($this->payOff, 2);
    }
}
