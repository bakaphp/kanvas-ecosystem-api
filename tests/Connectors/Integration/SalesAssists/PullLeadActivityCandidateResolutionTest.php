<?php

declare(strict_types=1);

namespace Tests\Connectors\Integration\SalesAssists;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Connectors\SalesAssist\Activities\PullLeadActivity;
use Kanvas\Guild\Customers\Models\People;
use Kanvas\Guild\Leads\Models\Lead;
use ReflectionClass;
use Tests\TestCase;

/**
 * The seam every vendor-calling arm of PullLeadActivity resolves through. It used to be a
 * single match() below the if/elseif chain, ordered differently from it — so a company
 * carrying a DriveCentric store_id or a DealerSocket credential alongside its eLead or
 * VinSolutions config pulled through one arm and resolved through another, landing on a
 * null lead: no social channels, no closing status, no AI trigger.
 *
 * The lookup must also miss softly. getByIdFromCompanyApp() throws, and it sat outside the
 * try/catch, so a candidate the scope could not see discarded an otherwise successful pull.
 */
class PullLeadActivityCandidateResolutionTest extends TestCase
{
    use DatabaseTransactions;

    protected $connectionsToTransact = [null, 'crm', 'ecosystem'];

    public function testResolvesTheTopRankedCandidateOfTheActingCompany(): void
    {
        $lead = $this->createLead();

        $resolved = $this->resolve([
            ['id' => $lead->getId(), 'rank' => 0.9],
            ['id' => $this->createLead()->getId(), 'rank' => 0.4],
        ]);

        $this->assertInstanceOf(Lead::class, $resolved);
        $this->assertSame($lead->getId(), $resolved->getId());
    }

    public function testAnUnreachableCandidateIsNullNotAThrownPull(): void
    {
        $this->assertNull(
            $this->resolve([['id' => PHP_INT_MAX, 'rank' => 1.0]]),
            'a candidate the company/app scope cannot see must not abort the pull'
        );
    }

    public function testADeletedCandidateDoesNotResolve(): void
    {
        $lead = $this->createLead();
        $lead->is_deleted = 1;
        $lead->saveQuietly();

        $this->assertNull($this->resolve([['id' => $lead->getId(), 'rank' => 1.0]]));
    }

    public function testNoCandidatesResolveToNothing(): void
    {
        $this->assertNull($this->resolve([]));
    }

    private function resolve(array $candidates): ?Lead
    {
        $app = app(Apps::class);
        $company = auth()->user()->getCurrentCompany();

        $reflection = new ReflectionClass(PullLeadActivity::class);

        return $reflection->getMethod('resolveCandidateLead')->invoke(
            $reflection->newInstanceWithoutConstructor(),
            $candidates,
            $company,
            $app
        );
    }

    private function createLead(): Lead
    {
        $user = auth()->user();
        $app = app(Apps::class);
        $company = $user->getCurrentCompany();

        $people = People::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->create();

        return Lead::factory()
            ->withUserId($user->getId())
            ->withAppId($app->getId())
            ->withCompanyId($company->getId())
            ->withPeopleId($people->getId())
            ->create();
    }
}
