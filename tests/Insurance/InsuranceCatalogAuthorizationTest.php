<?php

declare(strict_types=1);

namespace Tests\Insurance;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\AccessControlList\Enums\RolesEnums;
use Kanvas\Apps\Models\Apps;
use Kanvas\Insurance\Actions\CreateInsurancePlanAction;
use Kanvas\Insurance\Actions\CreateInsurerAction;
use Kanvas\Insurance\DataTransferObject\InsurancePlan as InsurancePlanData;
use Kanvas\Insurance\DataTransferObject\Insurer as InsurerData;
use Kanvas\Insurance\Models\InsurancePlan;
use Kanvas\Insurance\Models\Insurer;
use Kanvas\Users\Models\Users;
use Silber\Bouncer\BouncerFacade as Bouncer;
use Tests\TestCase;

class InsuranceCatalogAuthorizationTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'commerce'];

    private function insurerOfAdminCompany(): Insurer
    {
        return new CreateInsurerAction(
            InsurerData::from(app(Apps::class), auth()->user()->getCurrentCompany(), ['name' => 'Tenant A ' . uniqid()])
        )->execute();
    }

    private function planOf(Insurer $insurer): InsurancePlan
    {
        return new CreateInsurancePlanAction(
            InsurancePlanData::from($insurer, ['name' => 'Plan A', 'type' => 'law'])
        )->execute();
    }

    private function actAsUserOfAnotherCompany(bool $admin): Users
    {
        $adminUser = auth()->user();
        $user = $this->createUser();

        $scopes = [
            RolesEnums::getScope(app(Apps::class), global: true),
            RolesEnums::getScope(app(Apps::class), $user->getCurrentCompany()),
        ];

        foreach ($scopes as $scope) {
            Bouncer::scope()->to($scope);

            if ($admin) {
                Bouncer::assign(RolesEnums::ADMIN->value)->to($user);

                continue;
            }

            Bouncer::retract(RolesEnums::ADMIN->value)->from($user);
            Bouncer::retract(RolesEnums::OWNER->value)->from($user);
        }

        Bouncer::refresh();

        $this->assertNotSame($adminUser->getCurrentCompany()->getId(), $user->getCurrentCompany()->getId());
        $this->actingAs($user, 'api');

        return $user;
    }

    public function testNonAdminCannotUseAnyCatalogOperation(): void
    {
        $insurer = $this->insurerOfAdminCompany();
        $plan = $this->planOf($insurer);
        $user = $this->actAsUserOfAnotherCompany(admin: false);
        $this->assertFalse($user->isAdmin());

        $operations = [
            ['mutation { createInsurer(input: {name: "X"}) { id } }', []],
            ['mutation($id: ID!) { updateInsurer(id: $id, input: {name: "X"}) { id } }', ['id' => $insurer->getId()]],
            ['mutation($id: ID!) { deleteInsurer(id: $id) }', ['id' => $insurer->getId()]],
            ['mutation($id: ID!) { createInsurancePlan(input: {insurer_id: $id, name: "X", type: LAW}) { id } }', ['id' => $insurer->getId()]],
            ['mutation($id: ID!) { updateInsurancePlan(id: $id, input: {name: "X"}) { id } }', ['id' => $plan->getId()]],
            ['mutation($id: ID!) { deleteInsurancePlan(id: $id) }', ['id' => $plan->getId()]],
            ['query { insurers { data { id } } }', []],
            ['query { insurancePlans { data { id } } }', []],
        ];

        foreach ($operations as [$query, $variables]) {
            $response = $this->graphQL($query, $variables);

            $this->assertStringContainsString('not authorized', (string) $response->json('errors.0.message'), $query);
        }

        $this->assertSame('Plan A', $plan->fresh()->name);
        $this->assertFalse((bool) $insurer->fresh()->is_deleted);
    }

    public function testAdminOfAnotherCompanyCannotTouchForeignCatalogRows(): void
    {
        $insurer = $this->insurerOfAdminCompany();
        $plan = $this->planOf($insurer);
        $user = $this->actAsUserOfAnotherCompany(admin: true);
        $this->assertTrue($user->isAdmin());

        $operations = [
            ['mutation($id: ID!) { updateInsurer(id: $id, input: {name: "X"}) { id } }', ['id' => $insurer->getId()]],
            ['mutation($id: ID!) { deleteInsurer(id: $id) }', ['id' => $insurer->getId()]],
            ['mutation($id: ID!) { createInsurancePlan(input: {insurer_id: $id, name: "X", type: LAW}) { id } }', ['id' => $insurer->getId()]],
            ['mutation($id: ID!) { updateInsurancePlan(id: $id, input: {name: "X"}) { id } }', ['id' => $plan->getId()]],
            ['mutation($id: ID!) { deleteInsurancePlan(id: $id) }', ['id' => $plan->getId()]],
        ];

        foreach ($operations as [$query, $variables]) {
            $response = $this->graphQL($query, $variables);

            $this->assertNull($response->json('data.' . array_key_first((array) $response->json('data'))), $query . $response->getContent());
            $this->assertNotEmpty($response->json('errors'), $query);
            $this->assertStringNotContainsString('not authorized', (string) $response->json('errors.0.message'), $query);
        }

        $insurers = $this->graphQL('query { insurers { data { id } } }')->assertSuccessful();
        $plans = $this->graphQL('query { insurancePlans { data { id } } }')->assertSuccessful();

        $this->assertNotContains((string) $insurer->getId(), array_column($insurers->json('data.insurers.data'), 'id'));
        $this->assertNotContains((string) $plan->getId(), array_column($plans->json('data.insurancePlans.data'), 'id'));
        $this->assertSame('Plan A', $plan->fresh()->name);
        $this->assertFalse((bool) $insurer->fresh()->is_deleted);
    }
}
