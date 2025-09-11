<?php

declare(strict_types=1);

namespace Kanvas\Guild\Customers\DataTransferObject;

use Kanvas\Locations\Models\Cities;
use Kanvas\Locations\Models\Countries;
use Kanvas\Locations\Models\States;
use Spatie\LaravelData\Data;

class Address extends Data
{
    public function __construct(
        public readonly string $address,
        public readonly ?string $address_2 = null,
        public readonly ?string $city = null,
        public readonly ?string $county = null,
        public readonly ?string $state = null,
        public readonly ?string $country = null,
        public readonly ?string $zip = null,
        public readonly bool $is_default = true,
        public readonly ?int $city_id = null,
        public readonly ?int $state_id = null,
        public readonly ?int $country_id = null,
        public readonly ?int $address_type_id = null,
        public readonly ?float $duration = 0.0,
        public readonly ?int $id = null
    ) {
    }

    public static function fromArray(array $data): self
    {
        // Resolve state_id and country_id from state name/code
        $stateId = null;
        $countryId = null;

        if (! empty($data['state'])) {
            $state = States::where('code', $data['state'])
                ->orWhere('name', $data['state'])
                ->first();

            if ($state) {
                $stateId = $state->id;
                $countryId = $state->countries_id;
            }
        }

        // Override with explicit state_id if provided
        if (! empty($data['state_id'])) {
            $stateId = (int) $data['state_id'];

            // Get country from state if not already set
            if (! $countryId && $stateId) {
                $state = States::find($stateId);
                $countryId = $state?->countries_id;
            }
        }

        // Resolve city_id from city name
        $cityId = null;
        if (! empty($data['city'])) {
            $cityQuery = Cities::where('name', $data['city']);

            // If we have a state_id, filter by state
            if ($stateId) {
                $cityQuery->where('states_id', $stateId);
            }

            $city = $cityQuery->first();
            $cityId = $city?->id;
        }

        // Override with explicit city_id if provided
        if (! empty($data['city_id'])) {
            $cityId = (int) $data['city_id'];
        }

        // Resolve country_id from country name/code if not set from state
        if (! $countryId && ! empty($data['country'])) {
            $country = Countries::where('code', strtolower($data['country']))
                ->orWhere('name', $data['country'])
                ->first();
            $countryId = $country?->id;
        }

        // Override with explicit country_id if provided
        if (! empty($data['country_id'])) {
            $countryId = (int) $data['country_id'];
        }

        // Default to US (230) if no country found
        if (! $countryId && ! empty($data['countries_id'])) {
            $countryId = (int) $data['countries_id'];
        }

        $countryId = $countryId ?? 230; // Default to US

        return new self(
            address: $data['address'] ?? '',
            address_2: $data['address_2'] ?? $data['address_line2'] ?? null,
            city: $data['city'] ?? null,
            county: $data['county'] ?? null,
            state: $data['state'] ?? null,
            country: $data['country'] ?? null,
            zip: $data['zip'] ?? $data['zip_code'] ?? $data['postal_code'] ?? null,
            is_default: (bool) ($data['is_default'] ?? true),
            city_id: $cityId,
            state_id: $stateId,
            country_id: $countryId,
            address_type_id: $data['address_type_id'] ?? null,
            duration: isset($data['duration']) ? (float) $data['duration'] : 0.0,
            id: isset($data['id']) ? (int) $data['id'] : null
        );
    }

    /**
     * Convert to array for model creation/update
     */
    public function toModelArray(): array
    {
        return [
            'address' => $this->address,
            'address_2' => $this->address_2,
            'city' => $this->city,
            'county' => $this->county,
            'state' => $this->state,
            'country' => $this->country,
            'zip' => $this->zip,
            'is_default' => $this->is_default,
            'city_id' => $this->city_id,
            'state_id' => $this->state_id,
            'countries_id' => $this->country_id, // Note: using countries_id for model
            'address_type_id' => $this->address_type_id,
            'duration' => $this->duration,
        ];
    }

    /**
     * Get full formatted address string
     */
    public function getFormattedAddress(): string
    {
        $parts = array_filter([
            $this->address,
            $this->address_2,
            $this->city,
            $this->state,
            $this->zip,
            $this->country,
        ]);

        return implode(', ', $parts);
    }

    /**
     * Check if address has complete location data
     */
    public function hasCompleteLocationData(): bool
    {
        return ! empty($this->address) &&
               ! empty($this->city) &&
               ! empty($this->state) &&
               ! empty($this->zip);
    }

    /**
     * Get state model if state_id is set
     */
    public function getState(): ?States
    {
        return $this->state_id ? States::find($this->state_id) : null;
    }

    /**
     * Get city model if city_id is set
     */
    public function getCity(): ?Cities
    {
        return $this->city_id ? Cities::find($this->city_id) : null;
    }

    /**
     * Get country model if country_id is set
     */
    public function getCountry(): ?Countries
    {
        return $this->country_id ? Countries::find($this->country_id) : null;
    }

    /**
     * Check if this is a US address
     */
    public function isUSAddress(): bool
    {
        return $this->country_id === 230 ||
               strtolower($this->country ?? '') === 'us' ||
               strtolower($this->country ?? '') === 'usa' ||
               strtolower($this->country ?? '') === 'united states';
    }

    /**
     * Validate ZIP code format for US addresses
     */
    public function hasValidZipCode(): bool
    {
        if (! $this->isUSAddress() || empty($this->zip)) {
            return true; // Skip validation for non-US or empty ZIP
        }

        // US ZIP code validation (5 digits or 5+4 format)
        return preg_match('/^\d{5}(-\d{4})?$/', $this->zip) === 1;
    }
}
