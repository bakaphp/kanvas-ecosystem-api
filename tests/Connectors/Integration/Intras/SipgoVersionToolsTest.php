<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Neuron\Tools\EventRegistrationsTool;
use Kanvas\Connectors\Intras\Neuron\Tools\EventVersionTool;
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
use Kanvas\Guild\Customers\Enums\ContactTypeEnum;
use Kanvas\Guild\Customers\Models\People;
use Spatie\LaravelData\DataCollection;
use Tests\TestCase;
use Tests\Traits\AssertsToolRunKeys;

/**
 * The request that exposed the gap: "https://erp.example.test/events-versions/7160/participants, tráeme la
 * lista de participantes". The number in that link is a SIPGO id, every event tool wanted a Kanvas id,
 * and the two ranges overlap. These tools take the link.
 */
final class SipgoVersionToolsTest extends TestCase
{
    use AssertsToolRunKeys;

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();
    }

    public function testTheVersionBehindASipgoLinkAnswersInSipgoTerms(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $version = $this->createVersion('SESION DE INMERSION ESTRATEGICA EN IA', $legacyId, maxCapacity: 25);
        $this->register(
            $version,
            'Sergio',
            'Kuret Lazala',
            legacyParticipantId: $legacyId + 1
        );

        $result = $this->tool(new EventVersionTool())(referencia: 'https://erp.example.test/events-versions/' . $legacyId . '/participants');

        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $this->assertSame($version->getId(), $result['version_id']);
        $this->assertSame('VE' . $legacyId, $result['codigo']);
        $this->assertArrayNotHasKey('enlace_sipgo', $result, 'no link without the INTRAS_BASE_URL app setting; the host never lives in code');
        $this->assertStringStartsWith('SESION DE INMERSION ESTRATEGICA EN IA', $result['evento']['nombre']);
        $this->assertSame(25, $result['cupos']);
        $this->assertSame(1, $result['inscritos']);
        $this->assertSame(24, $result['disponibles']);
        $this->assertCount(1, $result['fechas']);
        $this->assertStringContainsString('VE' . $legacyId, $result['note']);
    }

    public function testABareNumberAndACodeReachTheSameVersion(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $version = $this->createVersion('BARE NUMBER', $legacyId);

        $byNumber = $this->tool(new EventVersionTool())(referencia: (string) $legacyId);
        $byCode = $this->tool(new EventVersionTool())(referencia: 'VE' . $legacyId);
        $byKanvasId = $this->tool(new EventVersionTool())(version_id: $version->getId());

        $this->assertSame($version->getId(), $byNumber['version_id']);
        $this->assertSame($version->getId(), $byCode['version_id']);
        $this->assertSame($version->getId(), $byKanvasId['version_id']);
    }

    public function testASipgoNumberIsNeverReadAsAKanvasId(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $this->createVersion('OVERLAP', $legacyId);

        $result = $this->tool(new EventVersionTool())(version_id: $legacyId);

        $this->assertSame('error', $result['status']);
        $this->assertStringContainsString('referencia', $result['message']);
    }

    public function testAnUnknownLinkAndAnEventLinkTalkBackInsteadOfGuessing(): void
    {
        $tool = $this->tool(new EventVersionTool());

        $missing = $tool(referencia: 'VE' . random_int(9_000_000, 9_999_999));
        $this->assertSame('error', $missing['status']);
        $this->assertStringContainsString('No reintentes', $missing['message']);

        $event = $tool(referencia: 'https://erp.example.test/events/3576/edit');
        $this->assertSame('error', $event['status']);
        $this->assertStringContainsString('intras_event_search', $event['message']);

        $garbage = $tool(referencia: 'HELLO WELLNESS');
        $this->assertSame('error', $garbage['status']);

        $nothing = $tool();
        $this->assertSame('error', $nothing['status']);
    }

    public function testRegistrationsCarryThePaCodeAndTheCargoWithItsSource(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $version = $this->createVersion('INSCRITOS', $legacyId);
        $this->register(
            $version,
            'Johanna',
            'Rodriguez Guzman',
            legacyParticipantId: 80239,
            position: 'Subgerente de Negocios',
            email: 'jorodriguez@parval.com.do',
        );
        $this->register(
            $version,
            'Laura',
            'Ortega Aquino',
            legacyParticipantId: 72761,
            position: 'Analista',
            metadata: ['empresa' => 'SEMMA', 'posicion' => 'Subgerente de Gestión Humana'],
        );

        $result = $this->tool(new EventRegistrationsTool())(referencia: 'https://erp.example.test/events-versions/' . $legacyId . '/participants');

        $this->assertSame('success', $result['status'], $result['message'] ?? '');
        $this->assertSame('VE' . $legacyId, $result['codigo']);
        $this->assertSame(2, $result['total']);

        $rows = array_column($result['inscritos'], null, 'pa');

        $this->assertSame('Subgerente de Negocios', $rows['PA80239']['cargo']);
        $this->assertFalse($rows['PA80239']['cargo_de_la_inscripcion']);
        $this->assertSame('jorodriguez@parval.com.do', $rows['PA80239']['email']);

        $this->assertSame('Subgerente de Gestión Humana', $rows['PA72761']['cargo'], 'the inscription snapshot wins over the person');
        $this->assertSame('SEMMA', $rows['PA72761']['empresa']);
        $this->assertTrue($rows['PA72761']['cargo_de_la_inscripcion']);

        $this->assertStringContainsString('ficha del ejecutivo', $result['note']);
    }

    public function testRegistrationsHonourTheLimitAndSayWhatIsLeft(): void
    {
        $legacyId = random_int(9_000_000, 9_999_999);
        $version = $this->createVersion('LIMITE', $legacyId);
        $this->register($version, 'Uno', 'Uno');
        $this->register($version, 'Dos', 'Dos');

        $result = $this->tool(new EventRegistrationsTool())(version_id: $version->getId(), limit: 1);

        $this->assertSame(2, $result['total']);
        $this->assertSame(1, $result['devueltos']);
        $this->assertStringContainsString('1 de 2', $result['note']);
    }

    public function testTheToolsKeyTheirRunBudgetByInputs(): void
    {
        $this->assertRunKeyFollowsInputs($this->tool(new EventVersionTool()));
        $this->assertRunKeyFollowsInputs($this->tool(new EventRegistrationsTool()));
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
        return $tool->withContext($this->kanvasApp, $this->company, static::$cachedUser);
    }

    private function createVersion(string $name, int $legacyId, int $maxCapacity = 50): EventVersion
    {
        new Setup($this->kanvasApp, static::$cachedUser, $this->company)->run();

        $response = $this->graphQL('
            mutation($input: EventInput!) {
                createEvent(input: $input) { id versions { data { id } } }
            }
        ', ['input' => [
            'name' => $name . ' ' . uniqid(),
            'description' => 'SIPGO tools test',
            'category_id' => EventCategory::fromCompany($this->company)->fromApp($this->kanvasApp)->first()->getId(),
            'type_id' => EventType::fromCompany($this->company)->fromApp($this->kanvasApp)->first()->getId(),
            'dates' => [['date' => now()->addMonth()->toDateString(), 'start_time' => '09:00', 'end_time' => '17:00']],
        ]])->assertSuccessful();

        /** @var EventVersion $version */
        $version = EventVersion::findOrFail((int) $response->json('data.createEvent.versions.data.0.id'));
        $version->metadata = array_merge($version->metadata ?? [], ['max_capacity' => $maxCapacity]);
        $version->saveQuietly();
        $version->set(CustomFieldEnum::INTRAS_EVENT_VERSION_ID->value, $legacyId);

        return $version;
    }

    /**
     * @param array<string, mixed> $metadata
     */
    private function register(
        EventVersion $version,
        string $firstname,
        string $lastname,
        ?int $legacyParticipantId = null,
        ?string $position = null,
        ?string $email = null,
        array $metadata = []
    ): People {
        $contacts = $email === null ? [] : [[
            'value' => $email,
            'contacts_types_id' => ContactTypeEnum::EMAIL->value,
            'weight' => 0,
        ]];

        $people = new CreatePeopleAction(new PeopleData(
            app: $this->kanvasApp,
            branch: static::$cachedUser->getCurrentBranch(),
            user: static::$cachedUser,
            firstname: $firstname,
            lastname: $lastname . ' ' . uniqid(),
            contacts: Contact::collect($contacts, DataCollection::class),
            address: Address::collect([], DataCollection::class),
        ))->execute();

        if ($legacyParticipantId !== null) {
            $people->set(CustomFieldEnum::INTRAS_PARTICIPANT_ID->value, $legacyParticipantId);
        }

        if ($position !== null) {
            $people->set('position', $position);
        }

        $participant = Participant::create([
            'apps_id' => $this->kanvasApp->getId(),
            'companies_id' => $this->company->getId(),
            'users_id' => static::$cachedUser->getId(),
            'people_id' => $people->getId(),
            'theme_area_id' => ThemeArea::fromCompany($this->company)->fromApp($this->kanvasApp)->first()->getId(),
        ]);

        EventVersionParticipant::create([
            'event_version_id' => $version->getId(),
            'participant_id' => $participant->getId(),
            'participant_type_id' => ParticipantType::fromCompany($this->company)->fromApp($this->kanvasApp)->first()->getId(),
            'ticket_price' => 0,
            'discount' => 0,
            'metadata' => $metadata === [] ? null : $metadata,
        ]);

        return $people;
    }
}
