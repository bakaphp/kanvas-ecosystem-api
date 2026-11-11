<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\Zoho;

use Baka\Contracts\AppInterface;
use Baka\Contracts\CompanyInterface;
use Baka\Users\Contracts\UserInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\Zoho\Activities\ZohoPullDealActivity;
use Kanvas\Connectors\Zoho\Enums\CustomFieldEnum;
use Kanvas\Guild\Deals\Models\Deal;
use Kanvas\Workflow\Enums\StatusEnum;
use ReflectionClass;
use Tests\Connectors\Integration\Zoho\Concerns\BuildsZohoDeal;
use Tests\TestCase;

final class ZohoPullDealActivityTest extends TestCase
{
    use BuildsZohoDeal;
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'social', 'ecosystem'];

    public function testReturnsTheLocalDealWithoutPulling(): void
    {
        $company = $this->makeZohoCompany();
        $deal = $this->makeDeal($company, stage: $this->makeStage($company, 'Underwriting'));
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-local-1');

        /** @var ZohoPullDealActivity $activity */
        $activity = new ReflectionClass(ZohoPullDealActivity::class)->newInstanceWithoutConstructor();
        $result = $activity->execute($deal, app(Apps::class), ['zoho_id' => 'zd-local-1']);

        $this->assertSame($deal->getId(), $result[0]['id']);
        $this->assertSame($deal->title, $result[0]['title']);
        $this->assertSame('Underwriting', $result[0]['stage']);
        $this->assertIsFloat($result[0]['rank'], 'the pull button draws the match percentage from rank');
        $this->assertFalse($result[0]['recentlyCreated']);
        $this->assertArrayHasKey('custom_fields', $result[0]);
    }

    public function testRefreshRePullsADealThatAlreadyExistsLocally(): void
    {
        $company = $this->makeZohoCompany();
        $deal = $this->makeDeal($company);
        $deal->set(CustomFieldEnum::ZOHO_DEAL_ID->value, 'zd-local-4');
        $activity = $this->activity($deal);

        $result = $activity->execute($deal, app(Apps::class), ['zoho_id' => 'zd-local-4', 'refresh' => true]);

        $this->assertSame('zd-local-4', $activity->requestedId);
        $this->assertSame($deal->getId(), $result[0]['id']);
    }

    public function testPullsFromZohoWhenNothingLocalMatches(): void
    {
        $company = $this->makeZohoCompany();
        $pulled = $this->makeDeal($company);
        $activity = $this->activity($pulled);

        $result = $activity->execute($this->makeDeal($company), app(Apps::class), ['entity_id' => 'zd-remote-2']);

        $this->assertSame('zd-remote-2', $activity->requestedId);
        $this->assertSame($pulled->getId(), $result[0]['id']);
        $this->assertArrayNotHasKey('pipeline_stage_id', $result[0], 'must be the candidate shape, not a raw toArray()');
    }

    public function testWorksOnTheIdZeroStubTheMutationHandsOverForAnUnknownDeal(): void
    {
        $company = $this->makeZohoCompany();
        $pulled = $this->makeDeal($company);
        $activity = $this->activity($pulled);

        $stub = new Deal();
        $stub->fill(['id' => 0, 'apps_id' => app(Apps::class)->getId(), 'companies_id' => $company->getId()]);
        $stub->setRelation('company', $company);

        $result = $activity->execute($stub, app(Apps::class), ['zoho_id' => 'zd-remote-3', 'user' => auth()->user()]);

        $this->assertSame($pulled->getId(), $result[0]['id']);
        $this->assertSame($company->getId(), $activity->companyId);
    }

    public function testFailsWithoutAZohoId(): void
    {
        $activity = $this->activity(null);

        $result = $activity->execute($this->makeDeal($this->makeZohoCompany()), app(Apps::class), []);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertStringContainsString('required', $result['error']);
    }

    public function testFailsWhenZohoHasNoSuchDeal(): void
    {
        $activity = $this->activity(null);

        $result = $activity->execute($this->makeDeal($this->makeZohoCompany()), app(Apps::class), ['zoho_id' => 'zd-nowhere']);

        $this->assertSame(StatusEnum::FAILED, $activity->workflowStatus());
        $this->assertStringContainsString('Failed to pull', $result['error']);
    }

    private function activity(?Deal $pulled): ZohoPullDealActivity
    {
        return new class ($pulled) extends ZohoPullDealActivity {
            public ?string $requestedId = null;
            public ?int $companyId = null;

            public function __construct(private ?Deal $pulled)
            {
            }

            protected function pullDealFromZoho(
                AppInterface $app,
                CompanyInterface $company,
                UserInterface $user,
                string $zohoDealId
            ): ?Deal {
                $this->requestedId = $zohoDealId;
                $this->companyId = $company->getId();

                return $this->pulled;
            }
        };
    }
}
