<?php

declare(strict_types=1);

namespace Tests\Guild\Integration\Organizations;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Auth\Actions\RegisterUsersAppAction;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Organizations\Actions\AddApproverToOrganizationAction;
use Kanvas\Guild\Organizations\Models\Organization;
use Kanvas\Guild\Organizations\Models\OrganizationApprover;
use Kanvas\Users\Models\Users;
use Tests\TestCase;

final class OrganizationApproverMutationTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'crm'];

    private const ADD_MUTATION = '
        mutation addOrganizationApprover($input: OrganizationApproverInput!) {
            addOrganizationApprover(input: $input) {
                id
                user { id email }
                organization { id name }
            }
        }
    ';

    private const REMOVE_MUTATION = '
        mutation removeOrganizationApprover($input: OrganizationApproverInput!) {
            removeOrganizationApprover(input: $input)
        }
    ';

    public function testAddApproverByUserId(): void
    {
        $organization = $this->seedOrganization('Graph Add By Id Corp');
        $approver = $this->seedUser('graph-by-id');

        $this->graphQL(self::ADD_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'users_id' => (string) $approver->getId(),
            ],
        ])->assertSuccessful()->assertJson([
            'data' => [
                'addOrganizationApprover' => [
                    'user' => ['email' => $approver->email],
                ],
            ],
        ]);

        $this->assertSame([$approver->email], OrganizationApprover::emailsFor($organization));
    }

    public function testAddApproverByEmailReusesAnExistingUser(): void
    {
        $organization = $this->seedOrganization('Graph Add By Email Corp');
        $approver = $this->seedUser('graph-by-email');

        $this->graphQL(self::ADD_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'email' => $approver->email,
            ],
        ])->assertSuccessful()->assertJson([
            'data' => [
                'addOrganizationApprover' => [
                    'user' => ['id' => (string) $approver->getId()],
                ],
            ],
        ]);
    }

    public function testAddApproverByUnknownEmailCreatesAMinimalUser(): void
    {
        $organization = $this->seedOrganization('Graph Unknown Email Corp');
        $email = 'graph-unknown-' . uniqid() . '@example.test';

        $this->graphQL(self::ADD_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'email' => $email,
            ],
        ])->assertSuccessful();

        $this->assertNotNull(Users::query()->where('email', $email)->first());
        $this->assertSame([$email], OrganizationApprover::emailsFor($organization));
    }

    public function testAddingTheSameApproverTwiceIsIdempotent(): void
    {
        $organization = $this->seedOrganization('Graph Idempotent Corp');
        $approver = $this->seedUser('graph-idempotent');

        $variables = [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'users_id' => (string) $approver->getId(),
            ],
        ];

        $this->graphQL(self::ADD_MUTATION, $variables)->assertSuccessful();
        $this->graphQL(self::ADD_MUTATION, $variables)->assertSuccessful();

        $this->assertCount(1, OrganizationApprover::emailsFor($organization));
    }

    public function testRemoveApprover(): void
    {
        $organization = $this->seedOrganization('Graph Remove Corp');
        $approver = $this->seedUser('graph-remove');
        new AddApproverToOrganizationAction($organization, $approver)->execute();

        $this->graphQL(self::REMOVE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'users_id' => (string) $approver->getId(),
            ],
        ])->assertSuccessful()->assertJson(['data' => ['removeOrganizationApprover' => true]]);

        $this->assertSame([], OrganizationApprover::emailsFor($organization));
    }

    public function testRemoveApproverWhoWasNeverOneReturnsFalse(): void
    {
        $organization = $this->seedOrganization('Graph Remove Stranger Corp');
        $stranger = $this->seedUser('graph-stranger');

        $this->graphQL(self::REMOVE_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'users_id' => (string) $stranger->getId(),
            ],
        ])->assertSuccessful()->assertJson(['data' => ['removeOrganizationApprover' => false]]);
    }

    public function testApproversAreExposedOnTheOrganizationType(): void
    {
        $organization = $this->seedOrganization('Graph Relation Corp');
        $approver = $this->seedUser('graph-relation');
        new AddApproverToOrganizationAction($organization, $approver)->execute();

        $this->graphQL('
            query organizations($id: Mixed) {
                organizations(where: { column: ID, operator: EQ, value: $id }) {
                    data { id approvers { user { email } } }
                }
            }
        ', ['id' => $organization->getId()])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'organizations' => [
                        'data' => [
                            ['approvers' => [['user' => ['email' => $approver->email]]]],
                        ],
                    ],
                ],
            ]);
    }

    public function testPassingNeitherUsersIdNorEmailFails(): void
    {
        $organization = $this->seedOrganization('Graph No Reference Corp');

        $this->graphQL(self::ADD_MUTATION, [
            'input' => ['organization_id' => (string) $organization->getId()],
        ])->assertGraphQLErrorMessage('Pass exactly one of users_id or email.');
    }

    public function testPassingBothUsersIdAndEmailFails(): void
    {
        $organization = $this->seedOrganization('Graph Both References Corp');
        $approver = $this->seedUser('graph-both');

        $this->graphQL(self::ADD_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'users_id' => (string) $approver->getId(),
                'email' => $approver->email,
            ],
        ])->assertGraphQLErrorMessage('Pass exactly one of users_id or email.');
    }

    public function testAnotherCompanysOrganizationIsNotReachable(): void
    {
        $otherCompany = Companies::factory()->create();
        $organization = $this->seedOrganization('Graph Other Tenant Corp', $otherCompany);
        $approver = $this->seedUser('graph-other-tenant');

        $this->graphQL(self::ADD_MUTATION, [
            'input' => [
                'organization_id' => (string) $organization->getId(),
                'users_id' => (string) $approver->getId(),
            ],
        ])->assertGraphQLErrorMessage(
            'No ' . Organization::class . ' record found with ID ' . $organization->getId()
            . ' for Company ID ' . auth()->user()->getCurrentCompany()->getId()
        );

        $this->assertSame([], OrganizationApprover::emailsFor($organization));
    }

    private function seedUser(string $prefix): Users
    {
        $user = Users::factory()->create(['email' => $prefix . '-' . uniqid() . '@example.test']);

        // The User GraphQL type reads email/displayname off the app profile, so an approver with no
        // users_associated_apps row makes `approvers { user { email } }` throw.
        new RegisterUsersAppAction($user, app(Apps::class))->execute($user->password);

        return $user;
    }

    private function seedOrganization(string $name, ?Companies $company = null): Organization
    {
        $app = app(Apps::class);
        $user = auth()->user();

        return Organization::create([
            'apps_id' => $app->getId(),
            'companies_id' => ($company ?? $user->getCurrentCompany())->getId(),
            'users_id' => $user->getId(),
            'name' => $name,
            'address' => '',
            'total_employees' => 0,
        ]);
    }
}
