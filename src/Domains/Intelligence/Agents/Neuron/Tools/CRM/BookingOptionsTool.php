<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\ScheduleRules;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

/**
 * What the business offers for booking: the appointment/service types + their default
 * duration, plus who the appointment is assigned to. Lets the receptionist offer the right
 * kind of appointment (and pass the right duration to get_user_availability / create_calendar_event)
 * instead of guessing.
 *
 * v1 limitation surfaced in the response: create_calendar_event / get_user_availability route to
 * the lead's OWNER. The staff roster is returned for context; booking with a different staff member
 * requires reassigning the lead or handing off to a human.
 */
#[AgentTool(name: 'Booking Options')]
class BookingOptionsTool extends Tool
{
    use ResolvesLeadForTool;

    private const DEFAULT_DURATION_MINUTES = 30;
    private const STAFF_LIMIT = 50;

    public function __construct()
    {
        parent::__construct(
            name: 'get_booking_options',
            description: 'List what the business can book: the appointment/service types it offers and their default duration, '
                . 'plus who the appointment is assigned to. Call this BEFORE proposing an appointment so you offer a real service type '
                . 'and pass the right duration to get_user_availability and create_calendar_event. '
                . 'Note: bookings are assigned to the lead owner; the staff list is context only.',
        );
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead in scope. Resolves the company, its services, and the assigned owner.',
                required: true,
            ),
        ];
    }

    public function __invoke(int $lead_id): array
    {
        $result = $this->resolveLeadOrError($lead_id);
        if (is_array($result)) {
            return $result;
        }
        $lead = $result;

        $company = $lead->company;
        $owner = $lead->owner;

        $serviceTypes = EventType::query()
            ->where('apps_id', $lead->apps_id)
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->orderBy('name')
            ->pluck('name')
            ->filter(fn (?string $name): bool => $name !== null && trim($name) !== '')
            ->values()
            ->all();

        $defaultDuration = ScheduleRules::query()
            ->where('apps_id', $lead->apps_id)
            ->where('companies_id', $company->getId())
            ->where('is_deleted', 0)
            ->whereNotNull('slot_duration_min')
            ->where('slot_duration_min', '>', 0)
            ->min('slot_duration_min');

        $staff = $company->users()
            ->limit(self::STAFF_LIMIT)
            ->get()
            ->map(fn (Users $user): array => [
                'user_id' => $user->getId(),
                'name' => $this->displayName($user),
            ])
            ->all();

        return [
            'status' => 'success',
            'lead_id' => $lead_id,
            'service_types' => $serviceTypes,
            'has_service_types' => $serviceTypes !== [],
            'default_duration_minutes' => $defaultDuration !== null ? (int) $defaultDuration : self::DEFAULT_DURATION_MINUTES,
            'assigned_owner' => $owner !== null
                ? ['user_id' => $owner->getId(), 'name' => $this->displayName($owner)]
                : null,
            'staff' => $staff,
            'note' => $serviceTypes === []
                ? 'No specific appointment types are configured — treat it as a general appointment. Bookings route to the assigned owner.'
                : 'Bookings route to the assigned owner; to book with a different staff member, reassign the lead or hand off to a human.',
        ];
    }

    private function displayName(Users $user): string
    {
        $name = trim($user->firstname . ' ' . $user->lastname);
        if ($name !== '') {
            return $name;
        }

        return (string) ($user->displayname ?? $user->email ?? ('user #' . $user->getId()));
    }
}
