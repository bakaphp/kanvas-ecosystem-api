<?php

declare(strict_types=1);

namespace Tests\Approvals;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Approvals\Enums\ApprovalOriginEnum;
use Kanvas\Approvals\Enums\ApprovalTriggerEnum;
use Kanvas\Approvals\Models\ApprovalPolicy;
use Kanvas\Apps\Models\Apps;
use Kanvas\Guild\Customers\Models\People;
use Tests\TestCase;

/**
 * ApprovalRequest.entityRecord resolves through a custom @union type resolver
 * (App\GraphQL\Approvals\Types\ApprovableEntityTypeResolver) because People's model class_basename
 * happens to match its GraphQL type name, but the exercise is the resolver itself — the same request
 * shape a frontend would send to get the full entity in one round trip instead of a second query keyed
 * off entitySummary()'s id.
 */
final class ApprovableEntityUnionTest extends TestCase
{
    use DatabaseTransactions;

    protected array $connectionsToTransact = ['mysql', 'ecosystem', 'crm', 'intelligence'];

    private const QUERY = '
        query approvalRequests($id: Mixed) {
            approvalRequests(where: { column: ID, operator: EQ, value: $id }) {
                data {
                    id
                    entityRecord {
                        __typename
                        ... on People {
                            id
                            firstname
                            lastname
                        }
                    }
                }
            }
        }
    ';

    public function testEntityRecordResolvesTheFullPeopleInOneQuery(): void
    {
        $app = app(Apps::class);
        $user = auth()->user();
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withUserId($user->getId())
            ->create(['firstname' => 'Wayland', 'lastname' => 'Smithers']);

        ApprovalPolicy::create([
            'apps_id' => $app->getId(),
            'companies_id' => $company->getId(),
            'system_modules_id' => $people->approvalSystemModuleId(),
            'approval_type' => 'approve_union_test',
            'steps' => [['resolver' => 'company_owner', 'config' => [], 'required_approvals' => 1]],
            'trigger' => ApprovalTriggerEnum::MANUAL,
        ]);

        $request = $people->requestApproval(
            'approve_union_test',
            origin: ApprovalOriginEnum::SYSTEM,
        );

        $this->graphQL(self::QUERY, ['id' => $request->getId()])
            ->assertSuccessful()
            ->assertJson([
                'data' => [
                    'approvalRequests' => [
                        'data' => [[
                            'entityRecord' => [
                                '__typename' => 'People',
                                'id' => (string) $people->getId(),
                                'firstname' => 'Wayland',
                                'lastname' => 'Smithers',
                            ],
                        ]],
                    ],
                ],
            ]);
    }
}
