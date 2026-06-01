<?php

declare(strict_types=1);

namespace Kanvas\Intelligence\Agents\Neuron\Tools\CRM;

use Carbon\Carbon;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Event\Events\Models\Event;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Intelligence\Agents\Attributes\AgentTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Traits\ResolvesLeadForTool;
use Kanvas\Intelligence\Enums\ConfigurationEnum;
use NeuronAI\Tools\PropertyType;
use NeuronAI\Tools\Tool;
use NeuronAI\Tools\ToolProperty;
use Override;

#[AgentTool(name: 'Lead Reference')]
class LeadRefTool extends Tool
{
    use ResolvesLeadForTool;

    public function __construct()
    {
        parent::__construct(
            'get_lead_ref',
            'Get the full reference data of the lead including personal info, owner,
             company, contacts (emails, phones), address, and photo.
             Call this once at the start of the conversation to know who you are talking to. Do not call it again.',
        );

        // $this->setMaxRuns(1);
    }

    #[Override]
    protected function properties(): array
    {
        return [
            new ToolProperty(
                name: 'lead_id',
                type: PropertyType::INTEGER,
                description: 'The ID of the lead provided in the conversation context.',
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
        /** @var \Kanvas\Companies\Models\Companies|null $company */
        $company = $lead->company;
        $people = $lead->people;

        $additional_context_information = $lead->get(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value);

        return [
            'lead_id' => $lead->id,
            'lead_uuid' => $lead->uuid,
            'title' => $lead->title,
            'description' => $lead->description,
            'status' => $lead->status()->first()?->name,
            'source' => $lead->source()->first()?->name,
            'type' => $lead->type()->first()?->name,
            'pipeline' => $lead->pipeline()->first()?->name,
            'stage' => $lead->stage()->first()?->name,
            'is_handed_off' => (bool) ($lead->get(ConfigurationEnum::AGENT_HAND_OFF->value) ?? false),
            'owner' => $lead->owner ? [
                'id' => $lead->owner->id,
                'name' => trim($lead->owner->firstname . ' ' . $lead->owner->lastname),
                'email' => $lead->owner->email,
            ] : null,
            'vehicle_interest' => $additional_context_information,
            'people' => $people ? [
                'id' => $people->id,
                'name' => $people->getName(),
                'firstname' => $people->firstname,
                'middlename' => $people->middlename,
                'lastname' => $people->lastname,
                'dob' => $people->dob?->format('Y-m-d'),
                'photo' => $people->getPhoto()?->url,

                'contacts' => $people->contacts()->with('type')->get()->map(fn ($contact) => [
                    'type' => $contact->type?->name,
                    'value' => $contact->value,
                    'is_opt_out' => (bool) $contact->is_opt_out,
                ])->toArray(),

                'addresses' => $people->address()->get()->map(fn ($address) => [
                    'address' => $address->address,
                    'address_2' => $address->address_2,
                    'city' => $address->city,
                    'state' => $address->state,
                    'country' => $address->country?->name,
                    'zip' => $address->zip,
                    'is_default' => (bool) $address->is_default,
                ])->toArray(),
            ] : null,

            'company' => $company ? [
                'id' => $company->id,
                'uuid' => $company->uuid,
                'name' => $company->name,
                'email' => $company->email,
                'phone' => $company->phone,
                'website' => $company->website,
                'address' => $company->address,
                'address_2' => $company->address_2,
                'city' => $company->city,
                'state' => $company->state,
                'country' => $company->country,
                'zip' => $company->zip,
                'zipcode' => $company->zipcode,
                'country_code' => $company->country_code,
                'timezone' => $company->timezone,
                'language' => $company->language,
                'is_active' => (bool) $company->is_active,
                'photo' => $company->getPhoto()?->url,
            ] : null,

            'appointments' => $this->buildAppointmentsList($lead),
        ];
    }

    /**
     * Split the lead's events into upcoming vs past so the agent doesn't
     * confuse a 6-week-old completed demo with a still-pending one. Status
     * (scheduled / completed / cancelled / …) comes from event_status and
     * is surfaced so the LLM can reason about state without inferring from
     * the date alone.
     *
     * @return array{upcoming: list<array<string, mixed>>, past: list<array<string, mixed>>}
     */
    private function buildAppointmentsList(Lead $lead): array
    {
        $tz = $lead->company?->timezone ?? 'UTC';
        $now = Carbon::now($tz);

        $events = Event::query()
            ->where('resources_id', $lead->getId())
            ->where('resources_type', Lead::class)
            ->where('is_deleted', 0)
            ->with([
                'versions' => fn ($q) => $q->where('is_deleted', 0)->orderByDesc('id')->limit(1),
                'versions.dates' => fn ($q) => $q->where('is_deleted', 0)
                    ->orderBy('event_date')
                    ->orderBy('start_time'),
                'eventStatus',
            ])
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        $upcoming = [];
        $past = [];

        foreach ($events as $event) {
            $date = $event->versions->first()?->dates->first();

            $row = [
                'event_id' => $event->getId(),
                'uuid' => $event->uuid,
                'name' => $event->name,
                'description' => $event->description,
                'meeting_link' => $event->meeting_link,
                'date' => $date?->event_date?->format('Y-m-d'),
                'start_time' => $date?->start_time,
                'end_time' => $date?->end_time,
                'status' => $event->eventStatus?->name,
                'event_status_id' => $event->event_status_id,
            ];

            if ($date === null || $date->event_date === null || $date->start_time === null) {
                // No schedule attached — treat as past so the LLM doesn't surface it as live.
                $past[] = $row;

                continue;
            }

            try {
                $eventStart = Carbon::parse(
                    $date->event_date->format('Y-m-d') . ' ' . $date->start_time,
                    $tz,
                );
            } catch (\Throwable) {
                $past[] = $row;

                continue;
            }

            if ($eventStart->greaterThanOrEqualTo($now)) {
                $upcoming[] = $row;
            } else {
                $past[] = $row;
            }
        }

        // Order upcoming soonest-first; past most-recent-first.
        usort($upcoming, fn (array $a, array $b): int => strcmp(
            (string) ($a['date'] . ' ' . $a['start_time']),
            (string) ($b['date'] . ' ' . $b['start_time']),
        ));

        return [
            'upcoming' => $upcoming,
            'past' => array_slice($past, 0, 5),
        ];
    }
}
