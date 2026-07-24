<?php

declare(strict_types=1);

namespace Tests\Connectors\Movipass;

use App\GraphQL\Connector\Movipass\Builders\MechanicsBuilder;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Movipass\Actions\SetMechanicServiceTypeAction;
use Kanvas\Connectors\Movipass\Enums\CustomFieldEnum;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class MechanicServiceTypeTest extends TestCase
{
    protected Apps $apps;
    protected Users $authUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apps = app(Apps::class);
        $this->authUser = auth()->user();
    }

    public function testActionPersistsAndTrimsServiceType(): void
    {
        new SetMechanicServiceTypeAction($this->authUser, '  ASISTENCIA VIAL  ')->execute();

        $this->assertSame(
            'ASISTENCIA VIAL',
            $this->authUser->get(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value)
        );

        new SetMechanicServiceTypeAction($this->authUser, 'ASISTENCIA VIAL MOTORIZADO')->execute();

        $this->assertSame(
            'ASISTENCIA VIAL MOTORIZADO',
            $this->authUser->get(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value)
        );
    }

    public function testMutationSetsServiceTypeOnMechanic(): void
    {
        $response = $this->graphQL('
            mutation SetServiceType($id: ID!, $serviceType: String!) {
                setMechanicServiceType(id: $id, service_type: $serviceType) {
                    id
                }
            }
        ', [
            'id' => (string) $this->authUser->getId(),
            'serviceType' => 'ASISTENCIA VIAL',
        ], [], [
            'X-Kanvas-App' => $this->apps->key,
        ]);

        $response->assertSuccessful();

        $this->assertSame(
            (string) $this->authUser->getId(),
            $response->json('data.setMechanicServiceType.id')
        );
        $this->assertSame(
            'ASISTENCIA VIAL',
            $this->authUser->fresh()->get(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value)
        );
    }

    public function testMechanicsBuilderFiltersByServiceType(): void
    {
        $filtered = new MechanicsBuilder()->build(null, ['service_type' => 'ASISTENCIA VIAL MOTORIZADO']);

        $this->assertStringContainsString('user_config', $filtered->toSql());
        $this->assertContains(CustomFieldEnum::MECHANIC_SERVICE_TYPE->value, $filtered->getBindings());
        $this->assertContains('ASISTENCIA VIAL MOTORIZADO', $filtered->getBindings());

        $unfiltered = new MechanicsBuilder()->build(null, []);

        $this->assertNotContains(
            CustomFieldEnum::MECHANIC_SERVICE_TYPE->value,
            $unfiltered->getBindings()
        );
    }
}
