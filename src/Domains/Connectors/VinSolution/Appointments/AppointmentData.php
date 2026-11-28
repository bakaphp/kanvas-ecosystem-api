<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Appointments;

use Carbon\CarbonInterface;

/**
 * Times are sent as UTC with milliseconds (2026-11-30T16:00:00.000), whatever zone the Carbon
 * instances arrive in.
 */
final readonly class AppointmentData
{
    private const WIRE_FORMAT = 'Y-m-d\TH:i:s.v';

    public function __construct(
        public CarbonInterface $startsAt,
        public CarbonInterface $endsAt,
        public string $dealerId,
        public string $leadId,
        public string $customerId,
        public string $assignedUserId,
        public string $purpose = '1',
        public string $locationType = 'DEALER_ADDRESS',
        public string $description = 'Sales appointment',
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiPayload(): array
    {
        return [
            'startDateUtc' => $this->startsAt->clone()->utc()->format(self::WIRE_FORMAT),
            'endDateUtc' => $this->endsAt->clone()->utc()->format(self::WIRE_FORMAT),
            'purpose' => $this->purpose,
            'dealerId' => $this->dealerId,
            'leadId' => $this->leadId,
            'customerId' => $this->customerId,
            'assignedUserId' => $this->assignedUserId,
            'location' => ['type' => $this->locationType],
            'description' => $this->description,
        ];
    }
}
