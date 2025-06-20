<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\DataTransferObject;

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
        $messageData = $message->message;
        $formData = $messageData['data']['form'] ?? [];
        $files = $lead->getFiles();
        $filesLinks = '';

        // Concatenate file URLs
        if (count($files) > 0) {
            foreach ($files as $file) {
                $filesLinks .= $file->url . ' ';
            }
            $filesLinks = trim($filesLinks);
        }

        // Clean up mileage - remove commas and convert to int
        $mileageRaw = $formData['mileage'] ?? '0';
        $mileage = (int) str_replace(',', '', (string) $mileageRaw);

        // Format payoff amount
        $payoffRaw = $formData['payoff_amount'] ?? '';
        $payoff = (float) $payoffRaw > 0 ? (float) number_format((float) $payoffRaw, 2, '.', '') : 0.0;

        // Format value
        $valueRaw = $formData['value'] ?? '';
        $value = (float) $valueRaw > 0 ? (float) number_format((float) $valueRaw, 2, '.', '') : 0.0;

        // Handle payoff-form specific logic
        if ($messageData['verb'] === 'payoff-form') {
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
                description: $filesLinks ?: null,
                payOff: (float) ($formData['ten_day_payoff']['value'] ?? 0),
            );
        }

        return new self(
            vin: $formData['vin'] ?? null,
            year: $formData['year'] ?? null,
            make: $formData['make'] ?? null,
            model: $formData['model'] ?? null,
            trim: isset($formData['trim']) ? substr((string) $formData['trim'], 0, 50) : null,
            exteriorColor: $formData['ext_color'] ?? null,
            interiorColor: $formData['int_color'] ?? null,
            bodyStyle: $formData['body_style'] ?? null,
            engineName: $formData['engine'] ?? null,
            transmission: $formData['trans'] ?? null,
            driveTrain: $formData['drive_train'] ?? null,
            doors: $formData['doors'] ?? null,
            mileage: $mileage,
            value: $value,
            condition: 'UNKNOWN',
            description: $filesLinks ?: null,
            payOff: $payoff,
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
