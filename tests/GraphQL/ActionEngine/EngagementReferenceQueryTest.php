<?php

declare(strict_types=1);

namespace Tests\GraphQL\ActionEngine;

use Baka\Support\Str;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Kanvas\ActionEngine\Actions\Enums\ActionEnum;
use Kanvas\ActionEngine\Engagements\Actions\CreateEngagementAction;
use Kanvas\ActionEngine\Engagements\DataTransferObject\Engagement as EngagementData;
use Kanvas\ActionEngine\Engagements\Models\Engagement;
use Kanvas\ActionEngine\Enums\ActionStatusEnum;
use Kanvas\ActionEngine\Support\Setup;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Guild\Leads\Models\Lead;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * Serial: Setup writes the global `companies_id = 0` actions row every process shares.
 */
#[Group('serial')]
final class EngagementReferenceQueryTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'ecosystem', 'action_engine', 'crm', 'social'];

    private const string QUERY = '
        query ($entityType: Mixed!, $entityId: Mixed!) {
            engagements(
                where: {
                    AND: [
                        { column: ENTITY_TYPE, operator: EQ, value: $entityType }
                        { column: ENTITY_ID, operator: EQ, value: $entityId }
                    ]
                }
            ) {
                data {
                    id
                    entity_type
                    reference {
                        __typename
                        ... on Lead { id }
                    }
                }
            }
        }
    ';

    private Apps $currentApp;
    private Companies $company;
    private Lead $lead;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();

        $this->currentApp = app(Apps::class);
        $this->company = auth()->user()->getCurrentCompany();
        $this->lead = Lead::factory()
            ->withAppAndCompany($this->currentApp->getId(), $this->company->getId())
            ->create();
        $this->lead->companies_branches_id = $this->company->defaultBranch()->firstOrFail()->getId();
        $this->lead->saveOrFail();

        new Setup($this->currentApp, auth()->user(), $this->company)->run();
    }

    public function testFiltersByReferenceAndResolvesItWithinTheCurrentCompany(): void
    {
        $engagement = $this->createLeadEngagement();
        $this->insertForeignCompanyEngagementOnTheSameReference($engagement);

        $data = $this->graphQL(self::QUERY, [
            'entityType' => 'lead',
            'entityId' => $this->lead->getId(),
        ])->assertOk()->json('data.engagements.data');

        $this->assertCount(1, $data);
        $this->assertSame((string) $engagement->getId(), (string) $data[0]['id']);
        $this->assertSame('lead', $data[0]['entity_type']);
        $this->assertSame('Lead', $data[0]['reference']['__typename']);
        $this->assertSame((string) $this->lead->getId(), (string) $data[0]['reference']['id']);
    }

    public function testALegacyRowStillResolvesItsLeadAsReference(): void
    {
        $engagement = $this->createLeadEngagement();
        DB::connection('action_engine')->table('engagements')
            ->where('id', $engagement->getId())
            ->update(['entity_type' => null, 'entity_id' => null]);

        $data = $this->graphQL('
            query ($id: Mixed!) {
                engagements(where: { column: ID, operator: EQ, value: $id }) {
                    data { id entity_type reference { __typename ... on Lead { id } } }
                }
            }
        ', ['id' => $engagement->getId()])->assertOk()->json('data.engagements.data');

        $this->assertNull($data[0]['entity_type']);
        $this->assertSame((string) $this->lead->getId(), (string) $data[0]['reference']['id']);
    }

    private function createLeadEngagement(): Engagement
    {
        return new CreateEngagementAction(EngagementData::from(
            $this->currentApp,
            $this->company,
            auth()->user(),
            $this->lead,
            [
                'action' => ActionEnum::SYNC->value,
                'request_id' => Str::uuid()->toString(),
                'source' => 'test',
                'status' => ActionStatusEnum::SENT->value,
            ]
        ))->withoutWorkflow()->execute();
    }

    private function insertForeignCompanyEngagementOnTheSameReference(Engagement $engagement): void
    {
        $row = DB::connection('action_engine')->table('engagements')->where('id', $engagement->getId())->first();
        $copy = (array) $row;
        unset($copy['id']);
        $copy['uuid'] = Str::uuid()->toString();
        $copy['companies_id'] = Companies::factory()->create()->getId();

        DB::connection('action_engine')->table('engagements')->insert($copy);
    }
}
