<?php

declare(strict_types=1);

namespace Tests\Intelligence\NervousSystem\Orchestrator;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Kanvas\Apps\Models\Apps;
use Kanvas\Companies\Models\Companies;
use Kanvas\HumanResources\Employees\Enums\EmployeeStatusEnum;
use Kanvas\NervousSystem\Orchestrator\Routing\Actions\ResolveExternalAttendeesAction;
use Kanvas\Users\Models\Users;
use Tests\Intelligence\NervousSystem\Orchestrator\Concerns\MakesMeetingRoutingFixtures;
use Tests\TestCase;

class ResolveExternalAttendeesActionTest extends TestCase
{
    use DatabaseTransactions;
    use MakesMeetingRoutingFixtures;

    protected array $connectionsToTransact = ['mysql', 'crm', 'hr', 'intelligence', 'social'];

    /**
     * @param list<string> $emails
     * @param list<string> $extraDomains
     *
     * @return list<string>
     */
    private function external(Companies $company, array $emails, array $extraDomains = []): array
    {
        return new ResolveExternalAttendeesAction(app(Apps::class), $company, $extraDomains)
            ->execute($this->meetingSignal($emails));
    }

    public function testAnEmployeeAndTheirColleaguesOnTheSameDomainAreInternal(): void
    {
        $company = $this->freshCompany();
        $staff = Users::factory()->create(['email' => $this->uniqueEmail('ourco-test.io')]);
        $this->employee($company, $staff);

        $colleague = $this->uniqueEmail('ourco-test.io');
        $customer = $this->uniqueEmail('customer-test.io');

        $this->assertSame([$customer], $this->external($company, [$staff->email, $colleague, $customer]));
    }

    public function testADepartedEmployeeCountsAsExternal(): void
    {
        $company = $this->freshCompany();
        $current = Users::factory()->create(['email' => $this->uniqueEmail('ourco-test.io')]);
        $this->employee($company, $current);
        $former = Users::factory()->create(['email' => $this->uniqueEmail('gmail.com')]);
        $this->employee($company, $former, EmployeeStatusEnum::DEPARTED);

        $this->assertSame([strtolower($former->email)], $this->external($company, [$current->email, $former->email]));
    }

    public function testACompanyWithNoHrFallsBackToItsUsers(): void
    {
        $company = $this->freshCompany();
        $staffEmail = strtolower($this->testUser()->email);
        $customer = $this->uniqueEmail('customer-test.io');

        $external = $this->external($company, [$staffEmail, $customer]);

        $this->assertNotContains($staffEmail, $external);
        $this->assertContains($customer, $external);
    }

    public function testAnExtraInternalDomainIsInternal(): void
    {
        $company = $this->freshCompany();
        $this->employee($company, Users::factory()->create(['email' => $this->uniqueEmail('ourco-test.io')]));

        $this->assertSame([], $this->external($company, [$this->uniqueEmail('old-ourco-test.io')], ['old-ourco-test.io']));
    }
}
