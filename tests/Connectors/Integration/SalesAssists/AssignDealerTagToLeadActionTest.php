<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\Connectors\Internal\Handlers\InternalHandler;
use Kanvas\Connectors\SalesAssist\Actions\AssignDealerTagToLeadAction;
use Kanvas\Connectors\SalesAssist\Activities\AssignDealerTagToLeadActivity;
use Kanvas\Connectors\SalesAssist\Enums\ConfigurationEnum;
use Kanvas\Connectors\SalesAssist\Enums\LeadCustomFieldEnum;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use Kanvas\Workflow\Enums\IntegrationsEnum;
use Kanvas\Workflow\Models\StoredWorkflow;
use Tests\Connectors\Traits\HasIntegrationCompany;
use Tests\TestCase;

/**
 * A fresh company per test: the rooftop map is a company setting, which lands in Redis and never
 * rolls back, so setting it on the shared test company would leak into every other test.
 */
class AssignDealerTagToLeadActionTest extends TestCase
{
    use DatabaseTransactions;
    use HasIntegrationCompany;

    private const int NORTH_OWNER_ID = 990101;
    private const int SOUTH_OWNER_ID = 990202;
    private const int UNMAPPED_OWNER_ID = 990303;

    protected $connectionsToTransact = [null, 'crm', 'social'];

    public function testVehicleStockNumberTagsALeadWithNoOwner(): void
    {
        $lead = $this->createLead(stockNumber: 's-4410');

        $result = new AssignDealerTagToLeadAction($lead)->execute();

        $this->assertSame('Store South', $result['tag']);
        $this->assertSame(AssignDealerTagToLeadAction::TRIGGER_VEHICLE, $result['trigger']);
        $this->assertSame(['Store South'], $this->tagNames($lead));
    }

    public function testOwnerTeamWinsOverTheVehicle(): void
    {
        $lead = $this->createLead(stockNumber: 'S4410', ownerId: self::NORTH_OWNER_ID);

        $result = new AssignDealerTagToLeadAction($lead)->execute();

        $this->assertSame('Store North', $result['tag']);
        $this->assertSame(AssignDealerTagToLeadAction::TRIGGER_OWNER, $result['trigger']);
        $this->assertSame(['Store North'], $this->tagNames($lead));
    }

    public function testAnOwnerAssignedLaterReplacesTheVehicleTag(): void
    {
        $lead = $this->createLead(stockNumber: 'S4410');
        $lead->addTag('vip');
        new AssignDealerTagToLeadAction($lead)->execute();

        $lead->leads_owner_id = self::NORTH_OWNER_ID;
        $lead->saveQuietly();
        new AssignDealerTagToLeadAction($lead->refresh())->execute();

        $this->assertSame(['Store North', 'vip'], $this->tagNames($lead));
    }

    public function testAnOwnerOutsideEveryTeamFallsBackToTheVehicle(): void
    {
        $lead = $this->createLead(stockNumber: 'N1002', ownerId: self::UNMAPPED_OWNER_ID);

        $result = new AssignDealerTagToLeadAction($lead)->execute();

        $this->assertSame('Store North', $result['tag']);
        $this->assertSame(AssignDealerTagToLeadAction::TRIGGER_VEHICLE, $result['trigger']);
    }

    public function testNothingMatchedLeavesTagsUntouched(): void
    {
        $lead = $this->createLead(stockNumber: 'X999');
        $lead->addTag('Store South');

        $result = new AssignDealerTagToLeadAction($lead)->execute();

        $this->assertNull($result['tag']);
        $this->assertSame(['Store South'], $this->tagNames($lead));
    }

    public function testACompanyWithoutTheMapIsANoOp(): void
    {
        $lead = $this->createLead(stockNumber: 'N1002', configure: false);

        $this->assertNull(new AssignDealerTagToLeadAction($lead)->execute()['tag']);
        $this->assertSame([], $this->tagNames($lead));
    }

    public function testActivityTagsTheLead(): void
    {
        $company = Companies::factory()->create();
        $this->setIntegration(
            app(Apps::class),
            IntegrationsEnum::INTERNAL,
            InternalHandler::class,
            $company,
            auth()->user()
        );
        $lead = $this->createLead(stockNumber: 'N1002', company: $company);

        $result = new AssignDealerTagToLeadActivity(
            0,
            now()->toDateTimeString(),
            new StoredWorkflow(),
            []
        )->execute($lead, app(Apps::class), []);

        $this->assertSame('Store North', $result['tag'] ?? null, json_encode($result));
        $this->assertSame(['Store North'], $this->tagNames($lead));
    }

    private function createLead(
        string $stockNumber,
        ?int $ownerId = null,
        bool $configure = true,
        ?Companies $company = null
    ): Lead {
        $user = auth()->user();
        $app = app(Apps::class);
        $company ??= Companies::factory()->create();

        if ($configure) {
            $company->set(ConfigurationEnum::LEAD_DEALER_TAGS->value, [
                ['tag' => 'Store North', 'owner_ids' => [self::NORTH_OWNER_ID], 'stock_prefixes' => ['N']],
                ['tag' => 'Store South', 'owner_ids' => [self::SOUTH_OWNER_ID], 'stock_prefixes' => ['S']],
            ]);
        }

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        $lead = Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();

        $lead->leads_owner_id = $ownerId ?? 0;
        $lead->saveQuietly();
        $lead->set(LeadCustomFieldEnum::VEHICLE_OF_INTEREST->value, [
            'year' => 2025,
            'make' => 'Honda',
            'stockNumber' => $stockNumber,
        ]);

        return $lead;
    }

    private function tagNames(Lead $lead): array
    {
        return $lead->tags()->pluck('name')->sort()->values()->all();
    }
}
