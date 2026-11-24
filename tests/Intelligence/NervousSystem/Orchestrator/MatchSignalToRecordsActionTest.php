<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\MatchSignalToRecordsAction;
use Kanvas\NervousSystem\Orchestrator\Routing\DataTransferObject\RoutingCandidate;
use Kanvas\NervousSystem\Orchestrator\Routing\Enums\RoutingTargetTypeEnum;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class MatchSignalToRecordsActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'crm', 'intelligence', 'social'];

    /**
     * @param list<string> $emails
     * @param list<RoutingTargetTypeEnum>|null $types
     *
     * @return list<string>
     */
    private function keys(Companies $company, array $emails, ?array $types = null): array
    {
        $matches = new MatchSignalToRecordsAction(
            app(Apps::class),
            $company,
            $emails,
            $types ?? [RoutingTargetTypeEnum::LEAD, RoutingTargetTypeEnum::ORGANIZATION]
        )->execute();

        return array_map(static fn (RoutingCandidate $candidate): string => $candidate->key(), $matches);
    }

    public function testAnOpenLeadOfTheAttendeeMatches(): void
    {
        $company = $this->freshCompany();
        $email = $this->uniqueEmail();
        $lead = $this->lead($company, $this->person($company, $email));

        $this->assertSame(['lead:' . $lead->getId()], $this->keys($company, [$email]));
    }

    public function testAClosedLeadFallsThroughToThePersonsOrganization(): void
    {
        $company = $this->freshCompany();
        $email = $this->uniqueEmail();
        $person = $this->person($company, $email);
        $this->lead($company, $person, open: false);
        $organization = $this->organization($company, 'Acme');
        $organization->addPeople($person);

        $this->assertSame(['organization:' . $organization->getId()], $this->keys($company, [$email]));
    }

    public function testAClosedLeadWithNoOrganizationMatchesNothing(): void
    {
        $company = $this->freshCompany();
        $email = $this->uniqueEmail('nobody-knows.io');
        $this->lead($company, $this->person($company, $email), open: false);

        $this->assertSame([], $this->keys($company, [$email]));
    }

    public function testAnUnknownAttendeeMatchesTheOrganizationOwningTheirDomain(): void
    {
        $company = $this->freshCompany();
        $organization = $this->organization($company, 'Globex', ['globex-test.io']);

        $this->assertSame(['organization:' . $organization->getId()], $this->keys($company, [$this->uniqueEmail('globex-test.io')]));
    }

    public function testAFreeMailDomainNeverMatchesAnOrganization(): void
    {
        $company = $this->freshCompany();
        $this->organization($company, 'Gmail Lovers', ['gmail.com']);

        $this->assertSame([], $this->keys($company, [$this->uniqueEmail('gmail.com')]));
    }

    public function testTheOrganizationOfAMatchedLeadIsNotACompetingCandidate(): void
    {
        $company = $this->freshCompany();
        $email = $this->uniqueEmail('initech-test.io');
        $organization = $this->organization($company, 'Initech', ['initech-test.io']);
        $lead = $this->lead($company, $this->person($company, $email));
        $lead->organization_id = $organization->getId();
        $lead->saveQuietly();

        $colleague = $this->uniqueEmail('initech-test.io');

        $this->assertSame(['lead:' . $lead->getId()], $this->keys($company, [$email, $colleague]));
    }

    public function testOnlyTheConfiguredLevelsAreMatched(): void
    {
        $company = $this->freshCompany();
        $email = $this->uniqueEmail();
        $this->lead($company, $this->person($company, $email));

        $this->assertSame([], $this->keys($company, [$email], [RoutingTargetTypeEnum::ORGANIZATION]));
    }

    public function testAnotherCompanysLeadIsNeverMatched(): void
    {
        $company = $this->freshCompany();
        $other = $this->freshCompany();
        $email = $this->uniqueEmail();
        $this->lead($other, $this->person($other, $email));

        $this->assertSame([], $this->keys($company, [$email]));
    }
}
