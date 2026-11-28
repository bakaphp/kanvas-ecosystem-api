<?php

declare(strict_types=1);

namespace Kanvas\Connectors\VinSolution\Actions;

use Baka\Support\Str;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentClient;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentData;
use Kanvas\Connectors\VinSolution\Appointments\AppointmentLeadContext;
use Kanvas\Connectors\VinSolution\Enums\ConfigurationEnum;
use Kanvas\Connectors\VinSolution\Enums\CustomFieldEnum;
use Kanvas\Connectors\VinSolution\Session\SessionTokenService;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Leads\Models\Lead;

/**
 * Books the VinSolutions appointment for a Kanvas Event and stores its id on the Event, which is what
 * keeps a retry from booking it twice.
 */
class ScheduleAppointmentFromEventAction
{
    public function __construct(
        protected Event $event,
        protected Lead $lead,
        protected array $params = [],
        protected ?AppointmentClient $client = null,
        protected ?SessionTokenService $session = null,
    ) {
    }

    public function execute(): string
    {
        if (! empty($existingId = $this->event->get(CustomFieldEnum::APPOINTMENT_ID->value))) {
            return (string) $existingId;
        }

        $company = $this->lead->company;
        $context = AppointmentLeadContext::fromLead($this->lead);

        if ($context->dealerId === null) {
            throw new ValidationException('Company is not linked to a VinSolutions dealer');
        }

        if ($context->leadId === null) {
            throw new ValidationException('Lead does not have a VinSolutions lead id');
        }

        if ($context->customerId === null) {
            throw new ValidationException('Lead people is not linked to a VinSolutions contact');
        }

        if ($context->assignedUserId === null) {
            throw new ValidationException('Lead has no VinSolutions assigned user');
        }

        $startsAt = $this->event->startsAt()
            ?? throw new ValidationException('Event has no start date to schedule in VinSolutions');

        $endsAt = $this->event->latestVersion()?->end_at?->copy()->utc()
            ?? $startsAt->copy()->addMinutes(ConfigurationEnum::appointmentDurationMinutes($company));

        $data = new AppointmentData(
            startsAt: $startsAt,
            endsAt: $endsAt,
            dealerId: $context->dealerId,
            leadId: $context->leadId,
            customerId: $context->customerId,
            assignedUserId: $context->assignedUserId,
            purpose: (string) ($this->params['purpose'] ?? '1'),
            description: Str::trimToNull($this->params['description'] ?? null)
                ?? Str::trimToNull($this->event->description)
                ?? $this->event->name,
        );

        $client = $this->client ?? new AppointmentClient();
        $session = $this->session ?? new SessionTokenService($this->event->app, $company);

        $appointmentId = $client->createSalesAppointment($session->getAccessToken(), $data);

        $this->event->set(CustomFieldEnum::APPOINTMENT_ID->value, $appointmentId);

        return $appointmentId;
    }
}
