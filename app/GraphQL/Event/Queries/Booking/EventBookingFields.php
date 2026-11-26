<?php

declare(strict_types=1);

namespace App\GraphQL\Event\Queries\Booking;

use Illuminate\Support\Carbon;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Services\ResourceTimezoneService;
use Kanvas\Inventory\Products\Models\Products;
use Kanvas\Inventory\Variants\Models\Variants;

class EventBookingFields
{
    private const string LOCAL_FORMAT = 'Y-m-d H:i:s';

    public function passCode(EventVersion $booking): ?string
    {
        $encrypted = $booking->getAttribute('pass_code_encrypted');

        return $encrypted === null ? null : decrypt($encrypted);
    }

    public function guest(EventVersion $booking): ?string
    {
        return $booking->participants->first()?->people?->getName();
    }

    public function arrivalHour(EventVersion $booking): ?string
    {
        return $booking->metadata['arrival_hour'] ?? null;
    }

    public function plate(EventVersion $booking): ?string
    {
        return $booking->metadata['vehiclePlate'] ?? null;
    }

    public function lot(EventVersion $booking): ?string
    {
        $name = $this->lotProduct($booking)?->name;

        return $name === null ? null : (string) $name;
    }

    public function date(EventVersion $booking): ?string
    {
        return $booking->timeSlot?->start_at->copy()->setTimezone($this->timezone($booking))->toDateString();
    }

    public function status(EventVersion $booking): string
    {
        return $booking->getAttribute('admitted_at_utc') === null ? 'booked' : 'admitted';
    }

    public function admittedAt(EventVersion $booking): ?string
    {
        $admittedAt = $booking->getAttribute('admitted_at_utc');

        return $admittedAt === null
            ? null
            : Carbon::parse($admittedAt, config('app.timezone'))
                ->setTimezone($this->timezone($booking))
                ->format(self::LOCAL_FORMAT);
    }

    public function admittedVia(EventVersion $booking): ?string
    {
        return $booking->getAttribute('admitted_source');
    }

    private function lotProduct(EventVersion $booking): ?Products
    {
        $resource = $booking->timeSlot?->resource;

        return match (true) {
            $resource instanceof Variants => $resource->product,
            $resource instanceof Products => $resource,
            default => null,
        };
    }

    private function timezone(EventVersion $booking): string
    {
        if ($booking->getAttribute('lot_timezone') === null) {
            $booking->setAttribute('lot_timezone', ResourceTimezoneService::resolve($this->lotProduct($booking)));
        }

        return $booking->getAttribute('lot_timezone');
    }
}
