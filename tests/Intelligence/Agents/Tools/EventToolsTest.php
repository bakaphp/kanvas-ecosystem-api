<?php

declare(strict_types=1);

namespace Tests\Intelligence\Agents\Tools;

use Carbon\Carbon;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Event\Events\Models\EventCategory;
use Kanvas\Event\Events\Models\EventType;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Event\Participants\Models\Participant;
use Kanvas\Event\Participants\Models\ParticipantType;
use Kanvas\Event\Support\Setup;
use Kanvas\Event\Themes\Models\ThemeArea;
use Kanvas\Guild\Customers\Actions\CreatePeopleAction;
use Kanvas\Guild\Customers\DataTransferObject\Address;
use Kanvas\Guild\Customers\DataTransferObject\Contact;
use Kanvas\Guild\Customers\DataTransferObject\People as PeopleData;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\GetCalendarTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\GetEventReportTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\GetEventsTrackingTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\GetEventTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\GetEventVersionTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\GetOrgActivityTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\ListEventParticipantsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\ListEventsTool;
use Kanvas\Intelligence\Agents\Neuron\Tools\Events\ListParticipantTypesTool;
use Kanvas\Users\Models\Users;
use NeuronAI\Tools\HasRunKey;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;

final class EventToolsTest extends TestCase
{
    private Apps $currentApp;
    private Companies $currentCompany;
    private Users $actingUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->actingUser = auth()->user();
        $this->currentApp = app(Apps::class);
        $this->currentCompany = $this->actingUser->getCurrentCompany();
    }

    public function test_read_and_detail_tools(): void
    {
        $name = 'AgentEventUniq' . uniqid();
        $eventDate = Carbon::now()->addWeeks(2);
        [$eventId, $version] = $this->createEvent($name, $eventDate, 3);

        $list = $this->tool(new ListEventsTool())->__invoke(search: $name);
        $this->assertSame($eventId, collect($list['events'])->firstWhere('name', $name)['id'] ?? null);

        $detail = $this->tool(new GetEventTool())->__invoke(event_id: $eventId);
        $this->assertSame($name, $detail['name']);
        $this->assertNotEmpty($detail['versions']);

        $versionDetail = $this->tool(new GetEventVersionTool())->__invoke(version_id: (int) $version->getId());
        $this->assertSame((int) $version->getId(), $versionDetail['version_id']);
        $this->assertNotEmpty($versionDetail['dates']);

        $participants = $this->tool(new ListEventParticipantsTool())->__invoke(version_id: (int) $version->getId());
        $this->assertSame(3, $participants['count']);

        $calendar = $this->tool(new GetCalendarTool())->__invoke(
            from: $eventDate->copy()->subDay()->toDateString(),
            to: $eventDate->copy()->addDay()->toDateString(),
        );
        $this->assertNotNull(collect($calendar['editions'])->firstWhere('version_id', (int) $version->getId()));

        $types = $this->tool(new ListParticipantTypesTool())->__invoke();
        $this->assertGreaterThanOrEqual(1, $types['count']);
    }

    public function test_report_tools(): void
    {
        $name = 'AgentReportEventUniq' . uniqid();
        $eventDate = Carbon::now()->addWeeks(3);
        [, $version] = $this->createEvent($name, $eventDate, 5, maxCapacity: 20);
        $versionId = (int) $version->getId();

        $tracking = $this->tool(new GetEventsTrackingTool())->__invoke(weeks_ahead: 8);
        $this->assertNotNull(collect($tracking['events'])->firstWhere('event_version_id', $versionId));

        $track = $this->tool(new GetEventReportTool())->__invoke(version_id: $versionId, report: 'inscription_track');
        $this->assertSame(5, collect($track['by_type'])->sum('count'));

        $objective = $this->tool(new GetEventReportTool())->__invoke(version_id: $versionId, report: 'inscriptions_vs_objective');
        $this->assertCount(5, $objective['weeks']);
        $this->assertSame(20, $objective['goal']);

        $concentration = $this->tool(new GetEventReportTool())->__invoke(version_id: $versionId, report: 'participant_concentration');
        $this->assertArrayHasKey('by_organization', $concentration);

        $activity = $this->tool(new GetOrgActivityTool())->__invoke();
        $this->assertArrayHasKey('organizations', $activity);
    }

    public function test_unknown_ids_return_errors(): void
    {
        $this->assertArrayHasKey('error', $this->tool(new GetEventTool())->__invoke(event_id: 999999999));
        $this->assertArrayHasKey('error', $this->tool(new GetEventVersionTool())->__invoke(version_id: 999999999));
    }

    /**
     * Sweeping a calendar means calling these once per edition, so each must key its run budget by inputs —
     * otherwise the 11th DISTINCT event in a turn trips NeuronAI's per-tool-name cap and aborts the whole
     * turn (Sentry KANVAS-ECOSYSTEM-64Q).
     */
    public function test_per_event_tools_key_their_run_budget_by_inputs(): void
    {
        $tools = [
            new GetEventTool(),
            new GetEventVersionTool(),
            new ListEventParticipantsTool(),
            new GetEventReportTool(),
        ];

        foreach ($tools as $tool) {
            $this->assertInstanceOf(HasRunKey::class, $tool, $tool->getName() . ' must key its run budget by inputs.');

            $tool->setInputs(['event_id' => 1, 'version_id' => 1, 'report' => 'inscription_track']);
            $keyOne = $tool->getRunKey();

            $tool->setInputs(['event_id' => 2, 'version_id' => 2, 'report' => 'inscription_track']);
            $keyTwo = $tool->getRunKey();

            $tool->setInputs(['event_id' => 1, 'version_id' => 1, 'report' => 'inscription_track']);
            $keyOneAgain = $tool->getRunKey();

            $this->assertNotEquals($keyOne, $keyTwo, $tool->getName() . ': distinct events must not share a run budget.');
            $this->assertEquals($keyOneAgain, $keyOne, $tool->getName() . ': identical calls must collapse so a loop is still capped.');
        }
    }

    /**
     * @template T of object
     *
     * @param T $tool
     *
     * @return T
     */
    private function tool(object $tool): object
    {
        return $tool->withContext($this->currentApp, $this->currentCompany, $this->actingUser);
    }

    /**
     * @return array{0: int, 1: EventVersion}
     */
    private function createEvent(string $name, Carbon $eventDate, int $participantCount, int $maxCapacity = 50): array
    {
        new Setup($this->currentApp, $this->actingUser, $this->currentCompany)->run();

        $input = [
            'name' => $name,
            'description' => 'Agent tool test event',
            'category_id' => EventCategory::fromCompany($this->currentCompany)->fromApp($this->currentApp)->first()->getId(),
            'type_id' => EventType::fromCompany($this->currentCompany)->fromApp($this->currentApp)->first()->getId(),
            'dates' => [
                ['date' => $eventDate->toDateString(), 'start_time' => '10:00', 'end_time' => '12:00'],
            ],
        ];

        $response = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) { id versions { data { id } } }
            }
        ', ['input' => $input])->assertSuccessful();

        $eventId = (int) $response->json('data.createEvent.id');
        $versionId = (int) $response->json('data.createEvent.versions.data.0.id');

        /** @var EventVersion $version */
        $version = EventVersion::find($versionId);
        $version->start_at = $eventDate->toDateTimeString();
        $version->metadata = array_merge($version->metadata ?? [], ['max_capacity' => $maxCapacity]);
        $version->saveQuietly();

        $participantType = ParticipantType::fromCompany($this->currentCompany)->fromApp($this->currentApp)->first();
        $themeArea = ThemeArea::fromCompany($this->currentCompany)->fromApp($this->currentApp)->first();

        for ($i = 0; $i < $participantCount; $i++) {
            $people = new CreatePeopleAction(new PeopleData(
                app: $this->currentApp,
                branch: $this->actingUser->getCurrentBranch(),
                user: $this->actingUser,
                firstname: 'Attendee' . $i,
                lastname: 'Uniq' . uniqid(),
                contacts: Contact::collect([], DataCollection::class),
                address: Address::collect([], DataCollection::class),
            ))->execute();

            $participant = Participant::create([
                'apps_id' => $this->currentApp->getId(),
                'companies_id' => $this->currentCompany->getId(),
                'users_id' => $this->actingUser->getId(),
                'people_id' => $people->getId(),
                'theme_area_id' => $themeArea->getId(),
            ]);

            EventVersionParticipant::create([
                'event_version_id' => $versionId,
                'participant_id' => $participant->getId(),
                'participant_type_id' => $participantType->getId(),
                'ticket_price' => 0,
                'discount' => 0,
            ]);
        }

        return [$eventId, $version->fresh()];
    }
}
