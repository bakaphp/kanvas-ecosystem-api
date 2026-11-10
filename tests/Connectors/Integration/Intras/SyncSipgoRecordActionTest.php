<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Intras;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Companies\Support\CompanyScope;
use Kanvas\Connectors\Intras\Actions\SyncSipgoRecordAction;
use Kanvas\Connectors\Intras\Enums\CustomFieldEnum;
use Kanvas\Connectors\Intras\Enums\PeopleIntrasSyncApprovalTypeEnum;
use Kanvas\Connectors\Intras\Support\SipgoReference;
use Kanvas\Event\Events\Models\EventVersion;
use Kanvas\Event\Events\Models\EventVersionParticipant;
use Kanvas\Exceptions\ValidationException;
use Kanvas\Guild\Customers\Models\People;
use PHPUnit\Framework\Attributes\Group;
use Tests\Connectors\Integration\Intras\Concerns\SeedsSipgoEventGraph;
use Tests\TestCase;

#[Group('serial')]
final class SyncSipgoRecordActionTest extends TestCase
{
    use DatabaseTransactions;
    use SeedsSipgoEventGraph;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence', 'social', 'event', 'workflow'];

    private Apps $kanvasApp;
    private Companies $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->kanvasApp = app(Apps::class);
        $this->company = static::$cachedUser->getCurrentCompany();

        $this->createSipgoSchema();
        $this->pointIntrasAtScratchDatabase();
        $this->prepareKanvasForSipgoEvents($this->kanvasApp, $this->company);
    }

    protected function tearDown(): void
    {
        $this->company->del(CustomFieldEnum::INTRAS_AGENCY_ID->value);
        $this->restoreIntrasSettings();

        parent::tearDown();
    }

    public function testThePlanCollectsTheVersionGraphWithoutWriting(): void
    {
        $versionId = $this->legacyId();
        $this->seedSipgoVersion($versionId, [
            80239 => ['first' => 'Johanna', 'last' => 'Rodriguez Guzman', 'position' => 'Subgerente de Negocios', 'company' => 11608],
            72761 => ['first' => 'Laura', 'last' => 'Ortega Aquino', 'position' => 'Subgerente de Gestión Humana', 'company' => 11608],
        ]);

        $plan = $this->action('VE' . $versionId)->plan();

        $this->assertSame([$versionId], $plan->versionIds);
        $this->assertEqualsCanonicalizing([80239, 72761], $plan->participantIds);
        $this->assertSame([11608], $plan->companyIds);
        $this->assertSame(2, $plan->registrationCount);
        $this->assertSame(6, $plan->rows());
        $this->assertNull(SipgoReference::parse('VE' . $versionId)->resolve($this->kanvasApp, $this->company), 'planning writes nothing');
    }

    public function testThePlanRefusesAnotherAgencysVersionAndAnUnknownOne(): void
    {
        $foreign = $this->legacyId();
        $this->seedSipgoVersion($foreign, [], agencyId: 2);

        try {
            $this->action('VE' . $foreign)->plan();
            $this->fail('another agency\'s version must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('otra agencia', $e->getMessage());
        }

        try {
            $this->action('VE' . $this->legacyId())->plan();
            $this->fail('an unknown version must be refused');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('no existe', $e->getMessage());
        }
    }

    public function testASyncLandsTheVersionItsPeopleAndItsRegistrationsAndStampsTheTime(): void
    {
        $versionId = $this->legacyId();
        $this->seedSipgoVersion($versionId, [
            80239 => ['first' => 'Johanna', 'last' => 'Rodriguez Guzman', 'position' => 'Subgerente de Negocios', 'company' => 11608],
            72761 => ['first' => 'Laura', 'last' => 'Ortega Aquino', 'position' => 'Subgerente de Gestión Humana', 'company' => 11608],
        ]);

        $result = $this->action('VE' . $versionId)->execute();

        $this->assertSame(1, $result['versiones']);
        $this->assertSame(2, $result['ejecutivos']);
        $this->assertSame(2, $result['inscripciones']);
        $this->assertSame(1, $result['empresas']);
        $this->assertTrue($result['encontrado_en_kanvas']);

        /** @var EventVersion $version */
        $version = SipgoReference::parse('VE' . $versionId)->resolve($this->kanvasApp, CompanyScope::ownCompanyOnly($this->kanvasApp, $this->company));
        $this->assertNotNull($version);
        $this->assertSame(25, $version->getMaxCapacity());
        $this->assertNotEmpty($version->get(CustomFieldEnum::INTRAS_SYNCED_AT->value), 'the root is stamped with the sync time');
        $this->assertSame(2, EventVersionParticipant::query()->where('event_version_id', $version->getId())->notDeleted()->count());

        $johanna = $this->person(80239);
        $this->assertSame('Johanna', $johanna->firstname);
        $this->assertSame('Subgerente de Negocios', $johanna->get('position'));
    }

    public function testASecondSyncPicksUpACorrectedNameAndPosition(): void
    {
        $versionId = $this->legacyId();
        $this->seedSipgoVersion($versionId, [
            80239 => ['first' => 'johanna', 'last' => 'rodriguez guzman', 'position' => 'Encargada de RRHH', 'company' => 11608],
        ]);
        $this->action('VE' . $versionId)->execute();
        $this->assertSame('Encargada de RRHH', $this->person(80239)->get('position'));

        $this->sipgo()->table('participants')->where('id', 80239)->update([
            'first_name' => 'Johanna',
            'last_name' => 'Rodríguez Guzmán',
            'position' => 'Subgerente de Negocios',
        ]);

        $this->action('VE' . $versionId)->execute();

        $johanna = $this->person(80239);
        $this->assertSame('Johanna', $johanna->firstname);
        $this->assertSame('Rodríguez Guzmán', $johanna->lastname);
        $this->assertSame('Subgerente de Negocios', $johanna->get('position'));
        $this->assertSame(1, People::query()->fromApp($this->kanvasApp)->fromCompany($this->company)->where('firstname', 'Johanna')->where('lastname', 'Rodríguez Guzmán')->count(), 'refreshed in place, not duplicated');
    }

    public function testAParticipantRootCoversTheirEditionsInThisAgency(): void
    {
        $first = $this->legacyId();
        $second = $this->legacyId();
        $this->seedSipgoVersion($first, [80239 => ['first' => 'Johanna', 'last' => 'Rodriguez', 'company' => 11608]]);
        $this->seedSipgoVersion($second, [80239 => ['first' => 'Johanna', 'last' => 'Rodriguez', 'company' => 11608]], name: 'OTRA EDICION');

        $plan = $this->action('PA80239')->plan();

        $this->assertSame([80239], $plan->participantIds);
        $this->assertEqualsCanonicalizing([$first, $second], $plan->versionIds);
        $this->assertSame(2, $plan->registrationCount);
        $this->assertSame([11608], $plan->companyIds);
    }

    public function testAPersonWithAPendingIntrasApprovalIsLeftAlone(): void
    {
        $versionId = $this->legacyId();
        $this->seedSipgoVersion($versionId, [
            80239 => ['first' => 'Johanna', 'last' => 'Rodriguez', 'position' => 'Subgerente de Negocios', 'company' => 11608],
            72761 => ['first' => 'Laura', 'last' => 'Ortega', 'position' => 'Analista', 'company' => 11608],
        ]);
        $this->action('VE' . $versionId)->execute();
        $this->seedPeoplePolicy();

        $johanna = $this->person(80239);
        $this->assertNotNull(
            $johanna->requestApproval(PeopleIntrasSyncApprovalTypeEnum::UPDATE->value, ['items' => []], static::$cachedUser),
            'precondition: an Intras approval is pending for Johanna'
        );

        $this->sipgo()->table('participants')->whereIn('id', [80239, 72761])->update(['position' => 'Cambiado en SIPGO']);

        $result = $this->action('VE' . $versionId)->execute();

        $this->assertSame(['PA80239'], $result['omitidos_por_aprobacion']);
        $this->assertSame('Subgerente de Negocios', $this->person(80239)->get('position'), 'her pending Kanvas edit is not overwritten');
        $this->assertSame('Cambiado en SIPGO', $this->person(72761)->get('position'), 'everyone else refreshes');
    }

    private function action(string $reference): SyncSipgoRecordAction
    {
        return new SyncSipgoRecordAction(
            $this->kanvasApp,
            $this->company,
            static::$cachedUser,
            SipgoReference::parse($reference)
        );
    }

    private function person(int $legacyId): People
    {
        /** @var People|null $people */
        $people = SipgoReference::parse('PA' . $legacyId)->resolve($this->kanvasApp, CompanyScope::ownCompanyOnly($this->kanvasApp, $this->company));
        $this->assertNotNull($people, 'PA' . $legacyId . ' was imported');

        return $people->refresh();
    }

    private function legacyId(): int
    {
        return random_int(9_000_000, 9_999_999);
    }
}
